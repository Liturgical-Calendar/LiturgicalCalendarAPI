<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Handlers\DecreesHandler;
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
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
        $src      = dirname(JsonData::DECREES_FILE->path());
        $exitCode = 1;
        exec(sprintf('cp -r %s %s', escapeshellarg($src), escapeshellarg($this->backupDir)), result_code: $exitCode);
        if ($exitCode !== 0 || !is_dir($this->backupDir . '/decrees')) {
            self::fail('Failed to back up the decrees source data directory; aborting before any test can mutate it.');
        }
    }

    protected function tearDown(): void
    {
        $src       = dirname(JsonData::DECREES_FILE->path());
        $backupSrc = $this->backupDir . '/decrees';
        // Only wipe the live directory when a non-empty backup exists to restore from;
        // otherwise we would destroy the real decrees data with nothing to put back.
        if (is_dir($backupSrc) && count(glob($backupSrc . '/*') ?: []) > 0) {
            $exitCode = 1;
            exec(sprintf('rm -rf %s && cp -r %s %s', escapeshellarg($src), escapeshellarg($backupSrc), escapeshellarg($src)), result_code: $exitCode);
            if ($exitCode !== 0) {
                // Keep the backup dir around for manual recovery and fail loudly.
                parent::tearDown();
                self::fail(sprintf('Failed to restore the decrees source data directory from backup %s; backup left in place for manual recovery.', $backupSrc));
            }
            exec(sprintf('rm -rf %s', escapeshellarg($this->backupDir)));
        } else {
            trigger_error('DecreesHandlerWriteTest::tearDown: backup directory missing or empty, skipping restore.', E_USER_WARNING);
        }
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
        // MaryMotherChurch_Create ships with the API, so a PUT on it must conflict.
        // (A _Create decree_id is required: the write payload schema binds the
        // decree_id suffix to metadata.action, and the fixture payload is createNew.)
        $payload = self::createNewPayload('MaryMotherChurch_Create');
        $this->expectException(ConflictException::class);
        ( new DecreesHandler(['MaryMotherChurch_Create']) )->handle(
            $this->requestFor('PUT', '/decrees/MaryMotherChurch_Create', ['Accept-Language' => 'en'], $payload)
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

    // -----------------------------------------------------------------------
    // requireSinglePathParam violations on PATCH and DELETE
    // -----------------------------------------------------------------------

    public function testPatchWithNoPathParamsIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/require exactly one path parameter/');
        // No path params supplied → requireSinglePathParam() throws.
        ( new DecreesHandler([]) )->handle(
            $this->requestFor('PATCH', '/decrees', ['Accept-Language' => 'en'], self::createNewPayload('StTest_Create'))
        );
    }

    public function testPatchWithMultiplePathParamsIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/require exactly one path parameter/');
        ( new DecreesHandler(['StTest_Create', 'extra']) )->handle(
            $this->requestFor('PATCH', '/decrees/StTest_Create/extra', ['Accept-Language' => 'en'], self::createNewPayload('StTest_Create'))
        );
    }

    public function testDeleteWithNoPathParamsIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/require exactly one path parameter/');
        ( new DecreesHandler([]) )->handle(
            $this->requestFor('DELETE', '/decrees', ['Accept-Language' => 'en'])
        );
    }

    public function testDeleteWithMultiplePathParamsIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/require exactly one path parameter/');
        ( new DecreesHandler(['StTest_Create', 'extra']) )->handle(
            $this->requestFor('DELETE', '/decrees/StTest_Create/extra', ['Accept-Language' => 'en'])
        );
    }

    // -----------------------------------------------------------------------
    // 503 sidecar write failure → rollback of decrees.json (applySidecarsWithRollback)
    // -----------------------------------------------------------------------

    /**
     * When the i18n sidecar write fails (because the i18n folder is unwritable),
     * `applySidecarsWithRollback` must:
     *   1. Re-throw the ServiceUnavailableException.
     *   2. Roll the decrees.json back to its pre-PUT state (StTest_Create must NOT
     *      appear in the database after the 503).
     *
     * Skipped when running as root (chmod is ineffective for root).
     */
    public function testPutRollsBackDecreesDbWhenI18nSidecarWriteFails(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('chmod is ineffective as root; skipping filesystem permission test.');
        }

        $i18nFolder = JsonData::DECREES_I18N_FOLDER->path();
        // Make each i18n locale file unwritable.
        $files = glob($i18nFolder . '/*.json') ?: [];
        if (empty($files)) {
            $this->markTestSkipped('No i18n locale files found; cannot test write-failure path.');
        }

        foreach ($files as $file) {
            chmod($file, 0444);
        }
        // Suppress the expected PHP warning emitted by file_put_contents when denied.
        set_error_handler(static fn () => true, E_WARNING);
        try {
            $this->expectException(ServiceUnavailableException::class);
            try {
                ( new DecreesHandler(['StTest_Create']) )->handle(
                    $this->requestFor('PUT', '/decrees/StTest_Create', ['Accept-Language' => 'en'], self::createNewPayload())
                );
            } finally {
                restore_error_handler();
                // Always restore write permissions before the outer tearDown restores the folder.
                foreach ($files as $file) {
                    chmod($file, 0644);
                }
            }
        } catch (ServiceUnavailableException $e) {
            // Verify rollback: StTest_Create must NOT be in decrees.json.
            $db = json_decode((string) file_get_contents(JsonData::DECREES_FILE->path()), true);
            self::assertNotContains('StTest_Create', array_column($db, 'decree_id'), 'decrees.json must be rolled back after sidecar write failure');
            throw $e;
        }
    }

    /**
     * When the lectionary sidecar write fails after the decrees.json was already saved,
     * the handler must roll back decrees.json and re-throw 503.
     *
     * Skipped when running as root.
     */
    public function testPutRollsBackDecreesDbWhenLectionarySidecarWriteFails(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('chmod is ineffective as root; skipping filesystem permission test.');
        }

        $lectFolder = JsonData::LECTIONARY_DECREES_FOLDER->path();
        $files      = glob($lectFolder . '/*.json') ?: [];
        if (empty($files)) {
            $this->markTestSkipped('No lectionary locale files found; cannot test write-failure path.');
        }

        // Make lectionary files unwritable.
        foreach ($files as $file) {
            chmod($file, 0444);
        }
        // Suppress the expected PHP warning emitted by file_put_contents when denied.
        set_error_handler(static fn () => true, E_WARNING);
        try {
            $this->expectException(ServiceUnavailableException::class);
            try {
                ( new DecreesHandler(['StTest_Create']) )->handle(
                    $this->requestFor('PUT', '/decrees/StTest_Create', ['Accept-Language' => 'en'], self::createNewPayload())
                );
            } finally {
                restore_error_handler();
                foreach ($files as $file) {
                    chmod($file, 0644);
                }
            }
        } catch (ServiceUnavailableException $e) {
            $db = json_decode((string) file_get_contents(JsonData::DECREES_FILE->path()), true);
            self::assertNotContains('StTest_Create', array_column($db, 'decree_id'), 'decrees.json must be rolled back after lectionary write failure');
            throw $e;
        }
    }

    /**
     * When an i18n sidecar write fails during DELETE's GC phase (removeKeyFromLocaleFiles),
     * the handler must roll back decrees.json (i.e., restore the deleted entry) and re-throw 503.
     *
     * Skipped when running as root.
     */
    public function testDeleteRollsBackDecreesDbWhenSidecarGcFails(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('chmod is ineffective as root; skipping filesystem permission test.');
        }

        // Create a decree with a unique event_key so GC runs during DELETE.
        ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('PUT', '/decrees/StTest_Create', ['Accept-Language' => 'en'], self::createNewPayload())
        );

        // Confirm StTest_Create is in the database.
        $dbBefore = json_decode((string) file_get_contents(JsonData::DECREES_FILE->path()), true);
        self::assertContains('StTest_Create', array_column($dbBefore, 'decree_id'));

        $i18nFolder = JsonData::DECREES_I18N_FOLDER->path();
        $files      = glob($i18nFolder . '/*.json') ?: [];
        if (empty($files)) {
            $this->markTestSkipped('No i18n locale files found; cannot test write-failure path.');
        }

        foreach ($files as $file) {
            chmod($file, 0444);
        }
        // Suppress the expected PHP warning emitted by file_put_contents when denied.
        set_error_handler(static fn () => true, E_WARNING);
        try {
            $this->expectException(ServiceUnavailableException::class);
            try {
                ( new DecreesHandler(['StTest_Create']) )->handle(
                    $this->requestFor('DELETE', '/decrees/StTest_Create', ['Accept-Language' => 'en'])
                );
            } finally {
                restore_error_handler();
                foreach ($files as $file) {
                    chmod($file, 0644);
                }
            }
        } catch (ServiceUnavailableException $e) {
            // After rollback, StTest_Create must be restored in decrees.json.
            $dbAfter = json_decode((string) file_get_contents(JsonData::DECREES_FILE->path()), true);
            self::assertContains('StTest_Create', array_column($dbAfter, 'decree_id'), 'decrees.json must be restored after DELETE sidecar GC failure');
            throw $e;
        }
    }

    // -----------------------------------------------------------------------
    // PATCH with readings (covers the $auditFiles[] lectionary branch on PATCH)
    // -----------------------------------------------------------------------

    public function testPatchWithReadingsUpdatesLectionaryFile(): void
    {
        // Create the decree first.
        ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('PUT', '/decrees/StTest_Create', ['Accept-Language' => 'en'], self::createNewPayload())
        );

        // Patch with updated readings.
        $patch             = self::createNewPayload();
        $patch['i18n']     = ['en' => 'Saint Test, Updated'];
        $patch['readings'] = [
            'en' => [
                'first_reading'      => 'Exodus 1:1',
                'responsorial_psalm' => 'Psalm 23',
                'gospel_acclamation' => 'Matt 5:3',
                'gospel'             => 'Matt 5:1-12',
            ],
        ];

        $resp = ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('PATCH', '/decrees/StTest_Create', ['Accept-Language' => 'en'], $patch)
        );
        self::assertSame(200, $resp->getStatusCode());

        $lectEn = json_decode((string) file_get_contents(strtr(JsonData::LECTIONARY_DECREES_FILE->path(), ['{locale}' => 'en'])), true);
        self::assertSame('Exodus 1:1', $lectEn['StTest']['first_reading'], 'Lectionary readings must be updated after PATCH');
    }

    // -----------------------------------------------------------------------
    // PATCH / PUT with schema-invalid payload (covers requireValidatedPayload schema branch)
    // -----------------------------------------------------------------------

    public function testPutWithSchemaInvalidPayloadIsRejected(): void
    {
        // decree_id valid, but missing required fields like decree_date, liturgical_event, metadata
        $invalidPayload = ['decree_id' => 'StTest_Create'];
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/schema validation/i');
        ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('PUT', '/decrees/StTest_Create', ['Accept-Language' => 'en'], $invalidPayload)
        );
    }

    // -----------------------------------------------------------------------
    // PATCH of a decree whose stored entry lacks liturgical_event (null storedLitEvent path)
    // -----------------------------------------------------------------------

    /**
     * When a stored decree lacks a `liturgical_event` property (malformed source data),
     * `storedLitEvent` is null (line 555) and `storedEventKey` is '' (line 558).
     * The PATCH must proceed without throwing because storedEventKey === '' means no
     * event_key-change check fires. The updated record is saved successfully.
     */
    public function testPatchDecreeWithStoredEntryMissingLiturgicalEventSucceeds(): void
    {
        $decreesFile = JsonData::DECREES_FILE->path();
        $db          = json_decode((string) file_get_contents($decreesFile), true);

        // Inject a stored decree without liturgical_event (uses a valid decree_id pattern).
        $syntheticEntry                = [];
        $syntheticEntry['decree_id']   = 'StTest_Create';
        $syntheticEntry['decree_date'] = '2025-01-01';
        $syntheticEntry['description'] = 'No liturgical event stored.';
        $db[]                          = $syntheticEntry;
        file_put_contents($decreesFile, json_encode($db, JSON_PRETTY_PRINT) . PHP_EOL);

        // Build a valid PATCH payload that matches the schema.
        $patch = self::createNewPayload('StTest_Create');
        unset($patch['readings']);

        $resp = ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('PATCH', '/decrees/StTest_Create', ['Accept-Language' => 'en'], $patch)
        );
        self::assertSame(200, $resp->getStatusCode());

        $dbAfter = json_decode((string) file_get_contents($decreesFile), true);
        $updated = array_values(array_filter($dbAfter, fn ($d) => $d['decree_id'] === 'StTest_Create'))[0];
        self::assertSame('Test decree creating a new liturgical event.', $updated['description']);
    }

    // -----------------------------------------------------------------------
    // DELETE of decree that has no liturgical_event (null event_key path)
    // -----------------------------------------------------------------------

    /**
     * When a stored decree has no `liturgical_event` property (malformed source data),
     * event_key is '' and GC is skipped. The decree must still be deleted successfully.
     *
     * We inject a synthetic entry directly into decrees.json to exercise the null/empty
     * event_key branch in handleDeleteRequest (lines 607-610).
     */
    public function testDeleteDecreeWithoutLiturgicalEventSucceeds(): void
    {
        $decreesFile = JsonData::DECREES_FILE->path();
        $db          = json_decode((string) file_get_contents($decreesFile), true);

        // Inject a minimal decree without a liturgical_event (uses a different slot to not
        // conflict with other injected entries; tearDown restores the whole folder anyway).
        $syntheticEntry                = [];
        $syntheticEntry['decree_id']   = 'StTest_Doctor'; // valid decree_id pattern
        $syntheticEntry['decree_date'] = '2025-01-01';
        $db[]                          = $syntheticEntry;
        file_put_contents($decreesFile, json_encode($db, JSON_PRETTY_PRINT) . PHP_EOL);

        $resp = ( new DecreesHandler(['StTest_Doctor']) )->handle(
            $this->requestFor('DELETE', '/decrees/StTest_Doctor', ['Accept-Language' => 'en'])
        );
        self::assertSame(200, $resp->getStatusCode());

        $dbAfter = json_decode((string) file_get_contents($decreesFile), true);
        self::assertNotContains('StTest_Doctor', array_column($dbAfter, 'decree_id'));
    }
}
