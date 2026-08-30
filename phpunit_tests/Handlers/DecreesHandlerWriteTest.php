<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Handlers\DecreesHandler;
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Every write in this class lands in a throwaway copy of `jsondata/`, never in the
 * working tree (#921).
 *
 * The class used to back the real `jsondata/sourcedata/rite/roman/decrees/` folder up
 * to `/tmp` in `setUp()` and restore it in `tearDown()` with `rm -rf <src> && cp -r
 * <backup> <src>`. Between those two commands the repository held no decrees source
 * data at all, so a run that never reached `tearDown()` — a fatal, an OOM kill, a
 * `timeout`, a Ctrl-C — left tracked source data deleted (or, when the `rm -rf` failed
 * while the `&&` proceeded, nested as `decrees/decrees/`). The next run then failed for
 * reasons that had nothing to do with the change under test.
 *
 * The fix removes the window rather than narrowing it: `setUpBeforeClass()` copies
 * `jsondata/` into a temporary shadow of the project root and repoints
 * `Router::$apiFilePath` at it, which is the single seam every `JsonData::…->path()`
 * resolves against — for the handler under test AND for this class's own assertions.
 * Nothing outside `sys_get_temp_dir()` is written, chmod'ed or deleted for the whole
 * lifetime of the class, so an interruption at any instant is harmless: the worst
 * outcome is an abandoned temp directory.
 *
 * The per-test reset (a pristine snapshot re-copied in `setUp()`) lives entirely inside
 * that temp directory too, and `AbstractHandlerTestCase::tearDownAfterClass()` restores
 * `Router::$apiFilePath` for the classes that follow.
 */
#[CoversClass(DecreesHandler::class)]
final class DecreesHandlerWriteTest extends AbstractHandlerTestCase
{
    /**
     * Temporary shadow of the project root. Empty until setUpBeforeClass() allocates it.
     *
     * Contains a real copy of `jsondata/` plus a symlink to the (read-only) `i18n/`
     * catalogs; `Router::$apiFilePath` points here for the lifetime of the class.
     */
    private static string $fixtureRoot = '';

    /** Untouched copy of the shipped decrees folder, re-applied before every test. */
    private static string $pristineDecrees = '';

    /** The decrees folder inside the fixture — the only tree these tests ever mutate. */
    private static string $fixtureDecrees = '';

    public static function setUpBeforeClass(): void
    {
        // Pins Router::$apiFilePath to the real project root (and skips the whole class
        // when JWT config is absent, before anything below has allocated state).
        parent::setUpBeforeClass();

        $realRoot    = Router::$apiFilePath;
        $realDecrees = dirname(JsonData::DECREES_FILE->path());

        self::assertShippedCorpusIntact($realDecrees);

        // Pin the audit logger to the REAL logs/ folder while the root still points there.
        // LoggerFactory memoises both the resolved logs folder and the 'audit' channel for
        // the whole process; letting the handler resolve it later — under the fixture root —
        // would leave every subsequent test class in this process logging into a directory
        // this class deletes in tearDownAfterClass().
        $realLogs = $realRoot . 'logs';
        if (!is_dir($realLogs)) {
            mkdir($realLogs, 0755, true);
        }
        LoggerFactory::create('audit', $realLogs, 90, false, true, false);

        self::$fixtureRoot = sys_get_temp_dir() . '/litcal-decrees-fixture-' . bin2hex(random_bytes(6));
        self::copyTree($realRoot . 'jsondata', self::$fixtureRoot . '/jsondata');
        // Gettext catalogs are only ever read, so a symlink is enough and keeps the copy small.
        if (is_dir($realRoot . 'i18n')) {
            symlink($realRoot . 'i18n', self::$fixtureRoot . '/i18n');
        }

        // Snapshot taken from the untouched working tree, kept inside the fixture so the
        // per-test reset never reads the repository again.
        self::$pristineDecrees = self::$fixtureRoot . '/pristine/decrees';
        self::copyTree($realDecrees, self::$pristineDecrees);

        // From here on every JsonData path — handler and assertions alike — resolves
        // inside the fixture.
        Router::$apiFilePath  = self::$fixtureRoot . DIRECTORY_SEPARATOR;
        self::$fixtureDecrees = JsonData::DECREES_FOLDER->path();
    }

    public static function tearDownAfterClass(): void
    {
        if ('' !== self::$fixtureRoot) {
            self::removeTree(self::$fixtureRoot);
            self::$fixtureRoot     = '';
            self::$pristineDecrees = '';
            self::$fixtureDecrees  = '';
        }
        // Restores Router::$apiFilePath to whatever it was before this class ran.
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Reset the mutable corpus between tests. Source and destination both live under
        // sys_get_temp_dir(), so this delete-then-copy — unlike the one it replaces — can
        // only ever destroy a copy.
        self::removeTree(self::$fixtureDecrees);
        self::copyTree(self::$pristineDecrees, self::$fixtureDecrees);
    }

    /**
     * Refuse to run against a working tree that a previous (pre-#921) interrupted run
     * already damaged, rather than compounding the damage and reporting it as a code failure.
     */
    private static function assertShippedCorpusIntact(string $realDecrees): void
    {
        if (!is_dir($realDecrees) || !is_file($realDecrees . '/decrees.json')) {
            throw new \RuntimeException(sprintf(
                'The decrees source data is missing from the working tree (%s). '
                . 'An interrupted run of an earlier version of this test may have deleted it; '
                . 'recover with `git checkout -- jsondata/` (a backup may also survive as /tmp/decrees-backup-*).',
                $realDecrees
            ));
        }

        if (is_dir($realDecrees . '/decrees')) {
            throw new \RuntimeException(sprintf(
                'The decrees source data folder contains a nested copy of itself (%s/decrees). '
                . 'That is the signature of an interrupted restore in an earlier version of this test; '
                . 'recover with `rm -rf %s/decrees && git checkout -- jsondata/`.',
                $realDecrees,
                $realDecrees
            ));
        }
    }

    /** Recursive copy of a directory tree; files only, no symlinks are followed into. */
    private static function copyTree(string $from, string $to): void
    {
        if (!is_dir($to) && !mkdir($to, 0755, true) && !is_dir($to)) {
            throw new \RuntimeException('Could not create fixture directory ' . $to);
        }

        foreach (scandir($from) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $source = $from . DIRECTORY_SEPARATOR . $entry;
            $target = $to . DIRECTORY_SEPARATOR . $entry;
            if (is_link($source)) {
                continue;
            }
            if (is_dir($source)) {
                self::copyTree($source, $target);
                continue;
            }
            if (!copy($source, $target)) {
                throw new \RuntimeException('Could not copy ' . $source . ' to ' . $target);
            }
        }
    }

    /**
     * Recursive delete, hard-fenced to sys_get_temp_dir().
     *
     * Symlinks are unlinked, never descended into: the fixture root holds a symlink to
     * the repository's `i18n/` folder, and following it would be the very class of
     * accident this rewrite exists to remove.
     */
    private static function removeTree(string $dir): void
    {
        $tempDir = realpath(sys_get_temp_dir());
        $target  = realpath($dir);
        if (false === $tempDir || false === $target) {
            return;
        }
        if (!str_starts_with($target . DIRECTORY_SEPARATOR, $tempDir . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException(sprintf('Refusing to delete %s: it is outside %s.', $target, $tempDir));
        }

        foreach (scandir($target) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $target . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path) || !is_dir($path)) {
                unlink($path);
                continue;
            }
            self::removeTree($path);
        }
        rmdir($target);
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
