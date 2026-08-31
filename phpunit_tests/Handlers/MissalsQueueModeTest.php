<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Enum\RomanMissal;
use LiturgicalCalendar\Api\Handlers\MissalsHandler;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataWriteMode;
use LiturgicalCalendar\Tests\Support\ShadowProjectRootTrait;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Sanctorale writes driven end to end in queue mode — issue #943.
 *
 * The point of routing these through the `WritesSourceData` seam rather than growing a second
 * write path is that they inherit change-request queueing for free. This class proves they
 * actually did: the response says `submitted`, the batch holds every file the request touched —
 * the structure row and its fan-out across the locale sidecars, in ONE batch, so a reviewer sees
 * one proposal rather than a row here and fourteen translations there — and the filesystem is
 * byte-for-byte untouched.
 *
 * A shadow project root is used even though queue mode is supposed to write nothing: the
 * assertion that it wrote nothing is only worth making if a failure of it cannot damage the
 * working tree.
 */
#[CoversClass(MissalsHandler::class)]
final class MissalsQueueModeTest extends AbstractHandlerTestCase
{
    use ShadowProjectRootTrait;

    protected static bool $requiresDatabase = true;

    private static string $fixtureRoot = '';

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $realRoot = Router::$apiFilePath;
        $realLogs = $realRoot . 'logs';
        if (!is_dir($realLogs)) {
            mkdir($realLogs, 0755, true);
        }
        LoggerFactory::create('audit', $realLogs, 90, false, true, false);

        self::$fixtureRoot   = self::createShadowProjectRoot($realRoot, 'litcal-missalqueue-fixture');
        Router::$apiFilePath = self::$fixtureRoot . DIRECTORY_SEPARATOR;
    }

    public static function tearDownAfterClass(): void
    {
        if ('' !== self::$fixtureRoot) {
            self::removeTree(self::$fixtureRoot);
            self::$fixtureRoot = '';
        }
        MissalsHandler::$missalsIndex   = null;
        MissalsHandler::$missalsIndexes = [];
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // AbstractHandlerTestCase does not open a connection of its own here, so this must use the
        // same one the handler writes through or it silently truncates nothing.
        Connection::getInstance()->exec('TRUNCATE TABLE sourcedata_change_requests RESTART IDENTITY CASCADE');

        foreach ([SourceDataWriteMode::FLAG, 'OPENFGA_API_URL', 'OPENFGA_STORE_ID', 'OPENFGA_MODEL_ID'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? false;
        }

        // A store id that does not exist, so every FGA check fails fast and
        // ChangeRequestReview::administers() returns false. Submissions therefore stay
        // `submitted` rather than being auto-approved, which is what these assertions read.
        $_ENV[SourceDataWriteMode::FLAG] = 'true';
        $_ENV['OPENFGA_API_URL']         = 'http://localhost:8083';
        $_ENV['OPENFGA_STORE_ID']        = 'no-such-store-missals-queue-test';
        $_ENV['OPENFGA_MODEL_ID']        = 'no-such-model-missals-queue-test';

        MissalsHandler::$missalsIndex   = null;
        MissalsHandler::$missalsIndexes = [];
        MissalsHandler::$availableLangs = [];
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

    /** @return array<string, string> path => content hash, for every source file in the fixture */
    private static function fingerprint(): array
    {
        $root        = self::$fixtureRoot . DIRECTORY_SEPARATOR . 'jsondata';
        $fingerprint = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && 'lock' !== $file->getExtension()) {
                $fingerprint[$file->getPathname()] = (string) md5_file($file->getPathname());
            }
        }
        ksort($fingerprint);

        return $fingerprint;
    }

    public function testCreatingASanctoraleEntryIsSubmittedAndTouchesNoFile(): void
    {
        $before = self::fingerprint();

        $handler = new MissalsHandler([RomanMissal::EDITIO_TYPICA_1970, 'StTestQueued']);
        $handler->setAllowedRequestMethods([RequestMethod::PUT]);
        $response = $handler->handle($this->withOidcUser(
            $this->requestFor('PUT', '/missals/' . RomanMissal::EDITIO_TYPICA_1970 . '/StTestQueued', [], [
                'month'    => 6,
                'day'      => 17,
                'grade'    => 2,
                'common'   => ['Pastors'],
                'calendar' => 'GENERAL ROMAN',
                'color'    => ['white'],
                'i18n'     => ['en' => 'Saint Test, Queued'],
            ]),
            'editor-1'
        ));

        $body = $this->decodeJsonBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('submitted', $body['disposition'] ?? null, 'queue mode must report the same disposition as every other source-data write');
        self::assertIsArray($body['change_request'] ?? null);
        /** @var array<string, mixed> $changeRequest */
        $changeRequest = $body['change_request'];
        self::assertIsString($changeRequest['batch_id'] ?? null);

        self::assertSame($before, self::fingerprint(), 'queue mode must not have touched the filesystem');

        $rows = ( new SourceDataChangeRequestRepository() )->getBatch($changeRequest['batch_id']);
        self::assertNotSame([], $rows);

        $paths = array_map(static fn (array $row): string => (string) $row['path'], $rows);
        self::assertContains(
            'jsondata/sourcedata/rite/roman/missals/propriumdesanctis_1970/propriumdesanctis_1970.json',
            $paths,
            'the structure row belongs to the batch'
        );
        // The fan-out is part of the SAME proposal: fourteen name files plus six rite-level
        // readings files, none of which mean anything reviewed apart from the row that needs them.
        self::assertCount(14, array_filter($paths, static fn (string $p): bool => str_contains($p, 'propriumdesanctis_1970/i18n/')));
        self::assertCount(6, array_filter($paths, static fn (string $p): bool => str_contains($p, 'lectionary/sanctorum/')));

        foreach ($rows as $row) {
            self::assertFalse(
                $row['metadata']['deletes_resource'] ?? false,
                'adding an entry does not delete the Missal resource'
            );
        }
    }

    /**
     * Deleting an ENTRY is not deleting the Missal. `deletesResource` drives the OpenFGA purge, so
     * passing true here would revoke every editor's grant on a live Missal because one saint was
     * removed — the trap `SourceDataWriter::commit()` documents.
     */
    public function testDeletingAnEntryIsNotFlaggedAsAResourceDeletion(): void
    {
        $before = self::fingerprint();

        $key     = 'StElizabethSeton';
        $handler = new MissalsHandler([RomanMissal::USA_EDITION_2011, $key]);
        $handler->setAllowedRequestMethods([RequestMethod::DELETE]);
        $response = $handler->handle($this->withOidcUser(
            $this->requestFor('DELETE', '/missals/' . RomanMissal::USA_EDITION_2011 . '/' . $key),
            'editor-1'
        ));

        $body = $this->decodeJsonBody($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('submitted', $body['disposition'] ?? null);
        self::assertSame($before, self::fingerprint(), 'queue mode must not have touched the filesystem');

        /** @var array<string, mixed> $changeRequest */
        $changeRequest = $body['change_request'];
        $rows          = ( new SourceDataChangeRequestRepository() )->getBatch($changeRequest['batch_id']);
        self::assertNotSame([], $rows);

        foreach ($rows as $row) {
            self::assertFalse($row['metadata']['deletes_resource'] ?? false);
            // The sidecars survive; only one key leaves them. So every staged operation is an
            // UPDATE carrying the rewritten body, never a DELETE.
            self::assertSame('update', $row['operation']);
        }
    }

    /**
     * The proposal is filed against the resource the caller was authorized on. A national edition
     * belongs to its national calendar, not to the General Roman Calendar — if the two disagreed,
     * a reviewer would be checking permissions on the wrong object.
     */
    public function testANationalEditionsProposalTargetsItsNationalCalendar(): void
    {
        $handler = new MissalsHandler([RomanMissal::USA_EDITION_2011, 'StElizabethSeton']);
        $handler->setAllowedRequestMethods([RequestMethod::PATCH]);
        $response = $handler->handle($this->withOidcUser(
            $this->requestFor('PATCH', '/missals/' . RomanMissal::USA_EDITION_2011 . '/StElizabethSeton', [], ['grade' => 4]),
            'editor-1'
        ));

        $body = $this->decodeJsonBody($response);
        /** @var array{resource: array{type: string, id: string}} $changeRequest */
        $changeRequest = $body['change_request'];

        self::assertSame('national_calendar', $changeRequest['resource']['type']);
        self::assertSame('roman/US', $changeRequest['resource']['id']);
    }

    public function testAnEditioTypicasProposalTargetsTheGeneralRomanCalendar(): void
    {
        $handler = new MissalsHandler([RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008, 'JuanDiego']);
        $handler->setAllowedRequestMethods([RequestMethod::PATCH]);
        $response = $handler->handle($this->withOidcUser(
            $this->requestFor('PATCH', '/missals/' . RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008 . '/JuanDiego', [], ['grade' => 3]),
            'editor-1'
        ));

        $body = $this->decodeJsonBody($response);
        /** @var array{resource: array{type: string, id: string}} $changeRequest */
        $changeRequest = $body['change_request'];

        self::assertSame('general_roman_calendar', $changeRequest['resource']['type']);
        self::assertSame(RomanMissal::EDITIO_TYPICA_TERTIA_EMENDATA_2008, $changeRequest['resource']['id']);
    }
}
