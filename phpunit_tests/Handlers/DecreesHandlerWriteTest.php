<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Handlers\DecreesHandler;
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DecreesHandler::class)]
final class DecreesHandlerWriteTest extends AbstractHandlerTestCase
{
    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = sys_get_temp_dir() . '/decrees-backup-' . uniqid();
        mkdir($this->backupDir, 0755, true);
        $src = dirname(JsonData::DECREES_FILE->path());
        exec(sprintf('cp -r %s %s', escapeshellarg($src), escapeshellarg($this->backupDir)));
    }

    protected function tearDown(): void
    {
        $src = dirname(JsonData::DECREES_FILE->path());
        exec(sprintf('rm -rf %s && cp -r %s %s', escapeshellarg($src), escapeshellarg($this->backupDir . '/decrees'), escapeshellarg($src)));
        exec(sprintf('rm -rf %s', escapeshellarg($this->backupDir)));
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private static function createNewPayload(string $decreeId = 'StTest_Create'): array
    {
        return [
            'decree_id'        => $decreeId,
            'decree_date'      => '2025-01-01',
            'decree_protocol'  => 'Prot. N. 1/25',
            'description'      => 'Test decree creating a new liturgical event.',
            'liturgical_event' => [
                'event_key' => 'StTest',
                'day'       => 14,
                'month'     => 2,
                'color'     => ['white'],
                'grade'     => 2,
                'common'    => ['Pastors'],
                'type'      => 'fixed',
                'calendar'  => 'GENERAL ROMAN',
            ],
            'metadata'         => [
                'action'     => 'createNew',
                'since_year' => 2025,
                'url'        => 'https://www.vatican.va/roman_curia/congregations/ccdds/documents/test.html',
            ],
            'i18n'             => ['en' => 'Saint Test'],
            'readings'         => [
                'en' => [
                    'first_reading'      => 'Genesis 1:1',
                    'responsorial_psalm' => 'Psalm 1',
                    'gospel_acclamation' => 'John 1:1',
                    'gospel'             => 'John 1:1-14',
                ],
            ],
        ];
    }

    public function testPutCreatesDecreeAndDistributesSidecars(): void
    {
        $resp = ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('PUT', '/decrees/StTest_Create', ['Accept-Language' => 'en'], self::createNewPayload())
        );
        self::assertSame(201, $resp->getStatusCode());

        $db = json_decode((string) file_get_contents(JsonData::DECREES_FILE->path()), true);
        self::assertContains('StTest_Create', array_column($db, 'decree_id'));

        $en = json_decode((string) file_get_contents(strtr(JsonData::DECREES_I18N_FILE->path(), ['{locale}' => 'en'])), true);
        self::assertSame('Saint Test', $en['StTest']);

        $it = json_decode((string) file_get_contents(strtr(JsonData::DECREES_I18N_FILE->path(), ['{locale}' => 'it'])), true);
        self::assertSame('', $it['StTest']); // placeholder for un-provided locale

        $lectEn = json_decode((string) file_get_contents(strtr(JsonData::LECTIONARY_DECREES_FILE->path(), ['{locale}' => 'en'])), true);
        self::assertSame('Genesis 1:1', $lectEn['StTest']['first_reading']);
    }

    public function testPutExistingDecreeIdConflicts(): void
    {
        $payload = self::createNewPayload('StMaryMagdalene_Upgrade');
        $this->expectException(ConflictException::class);
        ( new DecreesHandler(['StMaryMagdalene_Upgrade']) )->handle(
            $this->requestFor('PUT', '/decrees/StMaryMagdalene_Upgrade', ['Accept-Language' => 'en'], $payload)
        );
    }

    public function testPutBodyIdMismatchIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        ( new DecreesHandler(['SomethingElse_Create']) )->handle(
            $this->requestFor('PUT', '/decrees/SomethingElse_Create', ['Accept-Language' => 'en'], self::createNewPayload())
        );
    }

    public function testPutWithoutReadingsForCreateNewIsRejected(): void
    {
        $payload = self::createNewPayload();
        unset($payload['readings']);
        $this->expectException(ValidationException::class);
        ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('PUT', '/decrees/StTest_Create', ['Accept-Language' => 'en'], $payload)
        );
    }

    public function testPatchUpdatesExistingDecree(): void
    {
        // First create, then patch the description and the i18n entry.
        ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('PUT', '/decrees/StTest_Create', ['Accept-Language' => 'en'], self::createNewPayload())
        );
        $patch                = self::createNewPayload();
        $patch['description'] = 'Amended description.';
        $patch['i18n']        = ['en' => 'Saint Test, Amended'];
        unset($patch['readings']); // optional on PATCH

        $resp = ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('PATCH', '/decrees/StTest_Create', ['Accept-Language' => 'en'], $patch)
        );
        self::assertSame(200, $resp->getStatusCode());

        $db    = json_decode((string) file_get_contents(JsonData::DECREES_FILE->path()), true);
        $entry = array_values(array_filter($db, fn ($d) => $d['decree_id'] === 'StTest_Create'))[0];
        self::assertSame('Amended description.', $entry['description']);
        self::assertArrayNotHasKey('i18n', $entry); // sidecars never stored in the database

        $en = json_decode((string) file_get_contents(strtr(JsonData::DECREES_I18N_FILE->path(), ['{locale}' => 'en'])), true);
        self::assertSame('Saint Test, Amended', $en['StTest']);
    }

    public function testPatchUnknownDecreeIs404(): void
    {
        $this->expectException(NotFoundException::class);
        ( new DecreesHandler(['Nonexistent_Create']) )->handle(
            $this->requestFor('PATCH', '/decrees/Nonexistent_Create', ['Accept-Language' => 'en'], self::createNewPayload('Nonexistent_Create'))
        );
    }
}
