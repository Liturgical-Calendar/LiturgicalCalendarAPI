<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\RegionalDataHandler;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\ChangeRequestReview;
use LiturgicalCalendar\Api\Services\SourceData\ChangeRequestSourceDataWriter;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataWriteMode;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The diocesan and wider-region write paths, driven end to end in queue mode.
 *
 * `RegionalDataHandlerTest` deliberately stops short of a completed write: finishing one in
 * disk mode would create real files under `jsondata/sourcedata`. Queue mode is the way to
 * exercise the rest of the path — staging, commit, and the response merge — without the
 * handler touching the filesystem at all, which these tests also assert.
 */
#[CoversClass(RegionalDataHandler::class)]
#[CoversClass(ChangeRequestSourceDataWriter::class)]
#[CoversClass(SourceDataChangeRequestRepository::class)]
#[CoversClass(SourceDataWriteMode::class)]
#[CoversClass(ChangeRequestReview::class)]
final class RegionalDataQueueModeTest extends AbstractHandlerTestCase
{
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        // AbstractHandlerTestCase does not open a connection of its own here, so this must
        // use the same one the handler writes through or it silently truncates nothing.
        Connection::getInstance()->exec('TRUNCATE TABLE sourcedata_change_requests RESTART IDENTITY CASCADE');

        foreach ([SourceDataWriteMode::FLAG, 'OPENFGA_API_URL', 'OPENFGA_STORE_ID', 'OPENFGA_MODEL_ID'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? false;
        }

        // A store id that does not exist, so every FGA check fails fast and
        // ChangeRequestReview::administers() returns false. Submissions therefore stay
        // `submitted` rather than being auto-approved, which is what these assertions read.
        $_ENV[SourceDataWriteMode::FLAG] = 'true';
        $_ENV['OPENFGA_API_URL']         = 'http://localhost:8083';
        $_ENV['OPENFGA_STORE_ID']        = 'no-such-store-regional-queue-test';
        $_ENV['OPENFGA_MODEL_ID']        = 'no-such-model-regional-queue-test';
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if (false === $value) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        $this->originalEnv = [];

        parent::tearDown();
    }

    /**
     * A diocese with a schema-valid name (the enum in CommonDef.json lists real dioceses)
     * that has no calendar in the tree yet, so PUT is a genuine create rather than a conflict.
     *
     * @return array<string,mixed>
     */
    private static function newDiocesanPayload(): array
    {
        return [
            'litcal'   => [
                [
                    'liturgical_event' => [
                        'event_key' => 'StsProtaseGervase',
                        'color'     => ['red'],
                        'grade'     => 3,
                        'common'    => ['Proper'],
                        'day'       => 19,
                        'month'     => 6,
                    ],
                    'metadata'         => ['since_year' => 2024, 'form_rownum' => 0],
                ],
            ],
            'metadata' => [
                'nation'       => 'DE',
                'diocese_id'   => 'aachen_de',
                'diocese_name' => 'Aachen',
                'locales'      => ['de_DE'],
                'timezone'     => 'Europe/Berlin',
                'rite'         => 'roman',
            ],
            'i18n'     => [
                'de_DE' => ['StsProtaseGervase' => 'Heilige Protasius und Gervasius'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function diocesanPayload(): array
    {
        return [
            'litcal'   => [
                [
                    'liturgical_event' => [
                        'event_key' => 'StsProtaseGervase',
                        'color'     => ['morello'],
                        'grade'     => 3,
                        'common'    => ['Proper'],
                        'day'       => 19,
                        'month'     => 6,
                    ],
                    'metadata'         => ['since_year' => 2024, 'form_rownum' => 0],
                ],
            ],
            'metadata' => [
                'nation'       => 'IT',
                'diocese_id'   => 'novara_it',
                'diocese_name' => 'Diocesi di Novara',
                'locales'      => ['it_IT'],
                'timezone'     => 'Europe/Rome',
                'rite'         => 'ambrosian',
            ],
            'i18n'     => [
                'it_IT' => ['StsProtaseGervase' => 'Santi Protaso e Gervaso'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function widerRegionPayload(string $region = 'Europe'): array
    {
        return [
            'litcal'             => [
                [
                    'liturgical_event' => ['event_key' => 'StBenedict', 'grade' => 4],
                    'metadata'         => [
                        'action'       => 'makePatron',
                        'since_year'   => 1964,
                        'url'          => 'https://www.vatican.va/',
                        'url_lang_map' => ['it' => 'it', 'la' => 'la'],
                    ],
                ],
            ],
            'national_calendars' => ['Italy' => 'IT', 'France' => 'FR'],
            'metadata'           => [
                'wider_region' => $region,
                'locales'      => ['it_IT'],
            ],
            'i18n'               => [
                'it_IT' => ['StBenedict' => 'San Benedetto'],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function pendingRows(): array
    {
        $stmt = Connection::getInstance()->query(
            'SELECT path, resource_type, resource_id, review_status FROM sourcedata_change_requests ORDER BY path'
        );

        /** @var array<int, array<string, mixed>> $rows */
        $rows = false === $stmt ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @param array<string,mixed> $body
     */
    private static function assertQueued(array $body, string $expectedResourceId): void
    {
        self::assertSame('submitted', $body['disposition'] ?? null);
        self::assertIsArray($body['change_request'] ?? null);
        /** @var array<string,mixed> $changeRequest */
        $changeRequest = $body['change_request'];
        self::assertArrayHasKey('batch_id', $changeRequest);
        self::assertIsArray($changeRequest['resource'] ?? null);
        /** @var array<string,mixed> $resource */
        $resource = $changeRequest['resource'];
        self::assertSame($expectedResourceId, $resource['id'] ?? null);
    }

    public function testCreatingADiocesanCalendarIsQueuedAndWritesNothingToDisk(): void
    {
        $onDisk = 'jsondata/sourcedata/rite/roman/calendars/dioceses/DE/aachen_de';
        self::assertDirectoryDoesNotExist($onDisk, 'fixture assumption: this diocese has no calendar yet');

        $response = ( new RegionalDataHandler(['diocese', 'aachen_de']) )
            ->handle($this->withOidcUser($this->requestFor('PUT', '/data/diocese/aachen_de', [], self::newDiocesanPayload()), 'editor-1'));

        $body = $this->decodeJsonBody($response);

        self::assertSame(201, $response->getStatusCode());
        // The pre-existing success body survives; the change-request keys are merged onto it.
        self::assertArrayHasKey('success', $body);
        self::assertArrayHasKey('data', $body);
        self::assertQueued($body, 'roman/aachen_de');

        // Queue mode must not have touched the filesystem — not even the directory tree,
        // which the handler used to create up front before the writer took that over.
        self::assertDirectoryDoesNotExist($onDisk);

        $rows = $this->pendingRows();
        self::assertNotSame([], $rows, 'the calendar and its i18n file must both be queued');
        foreach ($rows as $row) {
            self::assertSame('diocesan_calendar', $row['resource_type']);
            self::assertSame('submitted', $row['review_status']);
        }
    }

    public function testUpdatingADiocesanCalendarIsQueued(): void
    {
        $response = ( new RegionalDataHandler(['diocese', 'novara_it'], Rite::AMBROSIAN) )
            ->handle($this->withOidcUser($this->requestFor('PATCH', '/data/diocese/novara_it', ['Accept-Language' => 'it-IT'], self::diocesanPayload()), 'editor-1'));

        $body = $this->decodeJsonBody($response);

        // PATCH updates an existing calendar, so it answers 200 OK; only PUT answers 201.
        self::assertSame(200, $response->getStatusCode());
        self::assertQueued($body, 'ambrosian/novara_it');
        self::assertNotSame([], $this->pendingRows());
    }

    public function testCreatingAWiderRegionCalendarIsQueued(): void
    {
        // Americas, Asia and Europe are in the tree; Africa is a valid name with no resource.
        $response = ( new RegionalDataHandler(['widerregion', 'Africa']) )
            ->handle($this->withOidcUser($this->requestFor('PUT', '/data/widerregion/Africa', [], self::widerRegionPayload('Africa')), 'editor-1'));

        $body = $this->decodeJsonBody($response);

        self::assertSame(201, $response->getStatusCode());
        // widerRegion() takes no Rite: a wider region is a layer above national calendars,
        // and its object id is qualified with the Roman rite by construction.
        self::assertQueued($body, 'roman/Africa');

        foreach ($this->pendingRows() as $row) {
            self::assertSame('wider_region', $row['resource_type']);
        }
    }

    public function testUpdatingAWiderRegionCalendarIsQueued(): void
    {
        $response = ( new RegionalDataHandler(['widerregion', 'Europe']) )
            ->handle($this->withOidcUser($this->requestFor('PATCH', '/data/widerregion/Europe', ['Accept-Language' => 'it-IT'], self::widerRegionPayload()), 'editor-1'));

        $body = $this->decodeJsonBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertQueued($body, 'roman/Europe');
        self::assertNotSame([], $this->pendingRows());
    }

    /**
     * The handler-level counterpart to
     * RegionalDataChangeRequestTest::testDeletingACalendarFlagsTheBatchAsAResourceDeletion(): this
     * proves `RegionalDataHandler::deleteCalendar()` itself passes `deletesResource: true` through
     * to the writer, not merely that the writer honours the flag when handed it directly.
     *
     * Croatia (HR) is reused from RegionalDataHandlerTest's disk-mode delete fixtures: it has no
     * diocesan calendars in the bundled source data, so the "diocesan calendars depend on this
     * nation" pre-check passes cleanly. Unlike those disk-mode tests, queue mode never touches the
     * filesystem, so there is nothing to back up or restore here.
     */
    public function testDeletingANationalCalendarIsQueuedAndFlaggedAsAResourceDeletion(): void
    {
        $onDisk = Router::$apiFilePath . 'jsondata/sourcedata/rite/roman/calendars/nations/HR/HR.json';
        self::assertFileExists($onDisk, 'fixture assumption: HR has a national calendar on disk');

        $response = ( new RegionalDataHandler(['nation', 'HR']) )
            ->handle($this->withOidcUser($this->requestFor('DELETE', '/data/nation/HR'), 'editor-1'));

        $body = $this->decodeJsonBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertQueued($body, 'roman/HR');

        // Queue mode must not have touched the filesystem.
        self::assertFileExists($onDisk);

        $rows = $this->pendingRows();
        self::assertNotSame([], $rows, 'the calendar and its i18n file must both be queued');
        foreach ($rows as $row) {
            self::assertSame('national_calendar', $row['resource_type']);
        }

        self::assertIsArray($body['change_request'] ?? null);
        /** @var array<string,mixed> $changeRequest */
        $changeRequest = $body['change_request'];
        self::assertIsString($changeRequest['batch_id'] ?? null);

        $repo = new SourceDataChangeRequestRepository();
        foreach ($repo->getBatch($changeRequest['batch_id']) as $batchRow) {
            self::assertTrue(
                $batchRow['metadata']['deletes_resource'] ?? false,
                'every row of a resource-deletion batch must carry the flag'
            );
        }
    }
}
