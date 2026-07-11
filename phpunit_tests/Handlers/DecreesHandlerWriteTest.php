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

        // FINDING 1: placeholder must NOT appear in 201 response body
        $responseBody = json_decode((string) $resp->getBody(), true);
        self::assertIsArray($responseBody);
        self::assertArrayHasKey('decree', $responseBody);
        self::assertArrayHasKey('liturgical_event', $responseBody['decree']);
        self::assertArrayNotHasKey('name', $responseBody['decree']['liturgical_event']);

        $db = json_decode((string) file_get_contents(JsonData::DECREES_FILE->path()), true);
        self::assertContains('StTest_Create', array_column($db, 'decree_id'));

        $en = json_decode((string) file_get_contents(strtr(JsonData::DECREES_I18N_FILE->path(), ['{locale}' => 'en'])), true);
        self::assertSame('Saint Test', $en['StTest']);

        $it = json_decode((string) file_get_contents(strtr(JsonData::DECREES_I18N_FILE->path(), ['{locale}' => 'it'])), true);
        self::assertSame('', $it['StTest']); // placeholder for un-provided locale

        $lectEn = json_decode((string) file_get_contents(strtr(JsonData::LECTIONARY_DECREES_FILE->path(), ['{locale}' => 'en'])), true);
        self::assertSame('Genesis 1:1', $lectEn['StTest']['first_reading']);

        // FINDING 1: placeholder must NOT leak into stored db entry or 201 response body
        $entry = array_values(array_filter($db, fn ($d) => $d['decree_id'] === 'StTest_Create'))[0];
        self::assertArrayNotHasKey('name', $entry['liturgical_event']);

        $body = json_decode((string) file_get_contents(JsonData::DECREES_FILE->path()), true);
        self::assertIsArray($body);
        $stored = array_values(array_filter($body, fn ($d) => $d['decree_id'] === 'StTest_Create'))[0];
        self::assertArrayNotHasKey('name', $stored['liturgical_event']);
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

    public function testPatchPreservesUnprovidedLocaleTranslations(): void
    {
        // FINDING 2: PATCH with i18n en+it must not blank locales absent from the payload.
        // Create with en+it translations.
        $payload         = self::createNewPayload();
        $payload['i18n'] = ['en' => 'Saint Test', 'it' => 'San Test'];
        ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('PUT', '/decrees/StTest_Create', ['Accept-Language' => 'en'], $payload)
        );

        $itBefore = json_decode((string) file_get_contents(strtr(JsonData::DECREES_I18N_FILE->path(), ['{locale}' => 'it'])), true);
        self::assertSame('San Test', $itBefore['StTest']);

        // PATCH with en only — Italian translation must be preserved, not blanked.
        $patch         = self::createNewPayload();
        $patch['i18n'] = ['en' => 'Saint Test, Amended'];
        unset($patch['readings']);
        ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('PATCH', '/decrees/StTest_Create', ['Accept-Language' => 'en'], $patch)
        );

        $itAfter = json_decode((string) file_get_contents(strtr(JsonData::DECREES_I18N_FILE->path(), ['{locale}' => 'it'])), true);
        self::assertSame('San Test', $itAfter['StTest'], 'Italian translation must be preserved after PATCH with only English i18n');

        // A locale never provided (fr) should stay as '' (empty, not removed).
        $frFile = strtr(JsonData::DECREES_I18N_FILE->path(), ['{locale}' => 'fr']);
        if (file_exists($frFile)) {
            $frAfter = json_decode((string) file_get_contents($frFile), true);
            self::assertSame('', $frAfter['StTest'] ?? '', 'Locale fr never provided should remain empty string');
        }
    }

    public function testPatchChangingEventKeyIsRejected(): void
    {
        // FINDING 3: PATCH must reject 400 when payload changes liturgical_event.event_key.
        ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('PUT', '/decrees/StTest_Create', ['Accept-Language' => 'en'], self::createNewPayload())
        );

        $patch                                  = self::createNewPayload();
        $patch['liturgical_event']['event_key'] = 'StTestRenamed'; // different key
        unset($patch['readings']);

        $this->expectException(ValidationException::class);
        ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('PATCH', '/decrees/StTest_Create', ['Accept-Language' => 'en'], $patch)
        );
    }

    public function testPatchUnknownDecreeIs404(): void
    {
        $this->expectException(NotFoundException::class);
        ( new DecreesHandler(['Nonexistent_Create']) )->handle(
            $this->requestFor('PATCH', '/decrees/Nonexistent_Create', ['Accept-Language' => 'en'], self::createNewPayload('Nonexistent_Create'))
        );
    }

    public function testDeleteRemovesDecreeAndOrphanedSidecarKeys(): void
    {
        ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('PUT', '/decrees/StTest_Create', ['Accept-Language' => 'en'], self::createNewPayload())
        );
        $resp = ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('DELETE', '/decrees/StTest_Create', ['Accept-Language' => 'en'])
        );
        self::assertSame(200, $resp->getStatusCode());

        $db = json_decode((string) file_get_contents(JsonData::DECREES_FILE->path()), true);
        self::assertNotContains('StTest_Create', array_column($db, 'decree_id'));

        $en = json_decode((string) file_get_contents(strtr(JsonData::DECREES_I18N_FILE->path(), ['{locale}' => 'en'])), true);
        self::assertArrayNotHasKey('StTest', $en);

        $lectEn = json_decode((string) file_get_contents(strtr(JsonData::LECTIONARY_DECREES_FILE->path(), ['{locale}' => 'en'])), true);
        self::assertArrayNotHasKey('StTest', $lectEn);
    }

    public function testDeletePreservesSidecarKeysSharedWithSurvivingDecrees(): void
    {
        // The shipped database has only one decree for StFaustinaKowalska, so the
        // fixture assumption (two decrees sharing an event_key) does not hold.
        // We create the second decree via PUT with a makeDoctor payload sharing
        // the same event_key (StTest) as the first decree (StTest_Create).
        // makeDoctor payloads must NOT include readings on create, and MUST include
        // i18n with the locale entry — the sidecar guard enforces this.
        ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('PUT', '/decrees/StTest_Create', ['Accept-Language' => 'en'], self::createNewPayload())
        );

        $doctorPayload = [
            'decree_id'        => 'StTest_Doctor',
            'decree_date'      => '2025-06-01',
            'decree_protocol'  => 'Prot. N. 2/25',
            'description'      => 'Test decree elevating the same event to Doctor of the Church.',
            'liturgical_event' => [
                'event_key' => 'StTest',
                'common'    => ['Proper'],
                'calendar'  => 'GENERAL ROMAN',
            ],
            'metadata'         => [
                'action'     => 'makeDoctor',
                'since_year' => 2025,
                'url'        => 'https://www.vatican.va/roman_curia/congregations/ccdds/documents/test-doctor.html',
            ],
            'i18n'             => ['en' => 'Saint Test, Doctor of the Church'],
        ];
        ( new DecreesHandler(['StTest_Doctor']) )->handle(
            $this->requestFor('PUT', '/decrees/StTest_Doctor', ['Accept-Language' => 'en'], $doctorPayload)
        );

        // Now delete only the first decree — the sidecar key 'StTest' must be preserved
        // because StTest_Doctor still references it.
        ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('DELETE', '/decrees/StTest_Create', ['Accept-Language' => 'en'])
        );

        $en = json_decode((string) file_get_contents(strtr(JsonData::DECREES_I18N_FILE->path(), ['{locale}' => 'en'])), true);
        self::assertArrayHasKey('StTest', $en);
    }

    public function testDeleteUnknownDecreeIs404(): void
    {
        $this->expectException(NotFoundException::class);
        ( new DecreesHandler(['Nonexistent_Create']) )->handle(
            $this->requestFor('DELETE', '/decrees/Nonexistent_Create', ['Accept-Language' => 'en'])
        );
    }
}
