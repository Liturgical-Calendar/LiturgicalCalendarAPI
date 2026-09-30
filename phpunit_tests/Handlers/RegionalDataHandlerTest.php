<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\RegionalDataHandler;
use LiturgicalCalendar\Api\Http\Exception\UnprocessableContentException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\Repositories\OutboxBatchInsertInterface;
use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeServiceInterface;
use LiturgicalCalendar\Api\Services\SupportedLocales;
use LiturgicalCalendar\Tests\Support\EnvIsolationTrait;
use LiturgicalCalendar\Tests\Support\ShadowProjectRootTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use Swaggest\JsonSchema\Schema;

/**
 * RegionalDataHandler serves and edits per-nation / per-diocese / per-wider-
 * region calendar source data. The PUT/PATCH/DELETE branches are gated by
 * JWT auth middleware (added by the router before handler invocation) and
 * involve disk writes; this suite covers the read paths, the write paths, and
 * the path / category validators that run before any side effects.
 *
 * Every write and every delete in this class lands in a throwaway copy of
 * `jsondata/`, never in the working tree (#935). See the fixture block below.
 */
#[CoversClass(RegionalDataHandler::class)]
final class RegionalDataHandlerTest extends AbstractHandlerTestCase
{
    use ShadowProjectRootTrait;
    use EnvIsolationTrait;

    // The handler resolves national/diocesan keys against the calendars metadata
    // index, which RegionalDataHandler now builds in-process from local source
    // data (CalendarMetadataProvider). Those lookups resolve against whatever
    // Router::$apiFilePath points at — here, the byte-identical copy of the
    // bundled sourcedata described below, with no HTTP server needed.

    // -------------------------------------------------------------------------
    // Shadow project root (#935).
    //
    // Several tests here drive the handler's write paths: the DELETE tests remove
    // the Croatian (HR) national calendar, and the create/update tests add a
    // Maltese (MT) one. Those used to act on the real working tree, with HR's only
    // surviving copy held in PHP memory until tearDown() wrote it back — so a fatal,
    // an OOM kill, a `timeout` or a Ctrl-C in between left tracked source files
    // deleted, and the next run then failed looking exactly like a legitimate
    // "HR calendar not found" regression.
    //
    // The window is removed rather than narrowed: setUpBeforeClass() copies
    // `jsondata/` into a temporary shadow of the project root and repoints
    // Router::$apiFilePath at it — the single seam every `JsonData::…->path()`
    // resolves against, so the handler under test and this class's own assertions
    // move together. Nothing outside sys_get_temp_dir() is written, chmod'ed or
    // deleted for the lifetime of the class, and the per-test reset below lives
    // entirely inside it, so an interruption at any instant is harmless: the worst
    // outcome is an abandoned temp directory.
    // -------------------------------------------------------------------------

    /** Temporary shadow of the project root. Empty until setUpBeforeClass() allocates it. */
    private static string $fixtureRoot = '';

    /** The real project root, kept so tests can assert the working tree stays untouched. */
    private static string $realRoot = '';

    /** Untouched copies of the shipped calendar trees, re-applied before every test. */
    private static string $pristineCalendars = '';

    /**
     * The calendar trees inside the fixture — the only trees these tests ever mutate.
     *
     * @var array<string,string> rite name => absolute folder inside the fixture
     */
    private static array $fixtureCalendars = [];

    public static function setUpBeforeClass(): void
    {
        // Pins Router::$apiFilePath to the real project root (and skips the whole class
        // when JWT config is absent, before anything below has allocated state).
        parent::setUpBeforeClass();

        self::$realRoot = Router::$apiFilePath;
        self::assertShippedCalendarsIntact(self::$realRoot);

        // Pin the audit logger to the REAL logs/ folder while the root still points there.
        // LoggerFactory memoises both the resolved logs folder and the 'audit' channel for
        // the whole process; letting RegionalDataHandler's constructor resolve it later —
        // under the fixture root — would leave every subsequent test class in this process
        // logging into a directory this class deletes in tearDownAfterClass().
        $realLogs = self::$realRoot . 'logs';
        if (!is_dir($realLogs)) {
            mkdir($realLogs, 0755, true);
        }
        LoggerFactory::create('audit', $realLogs, 90, false, true, false);

        // Copies jsondata/ and symlinks the read-only gettext catalogs; see the trait.
        // The catalogs are safe to share: CalendarMetadataProvider::buildLocales() only
        // globs them, and nothing in this class or in RegionalDataHandler writes there —
        // every write the handler makes lands under jsondata/sourcedata/.
        self::$fixtureRoot = self::createShadowProjectRoot(self::$realRoot, 'litcal-regionaldata-fixture');

        // From here on every JsonData path — handler and assertions alike — resolves
        // inside the fixture.
        Router::$apiFilePath = self::$fixtureRoot . DIRECTORY_SEPARATOR;

        self::$fixtureCalendars = [
            'roman'     => JsonData::CALENDARS_FOLDER->path(),
            'ambrosian' => dirname(JsonData::AMBROSIAN_DIOCESAN_CALENDARS_FOLDER->path()),
        ];

        // Snapshots taken from inside the fixture, so the per-test reset never reads the
        // repository again.
        self::$pristineCalendars = self::$fixtureRoot . DIRECTORY_SEPARATOR . 'pristine';
        foreach (self::$fixtureCalendars as $rite => $folder) {
            self::copyTree($folder, self::$pristineCalendars . DIRECTORY_SEPARATOR . $rite);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if ('' !== self::$fixtureRoot) {
            self::removeTree(self::$fixtureRoot);
            self::$fixtureRoot       = '';
            self::$realRoot          = '';
            self::$pristineCalendars = '';
            self::$fixtureCalendars  = [];
        }
        // Restores Router::$apiFilePath to whatever it was before this class ran.
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Reset the mutable calendar trees between tests: the DELETE tests remove HR and
        // the create tests add MT. Source and destination both live under
        // sys_get_temp_dir(), so this delete-then-copy can only ever destroy a copy.
        foreach (self::$fixtureCalendars as $rite => $folder) {
            self::removeTree($folder);
            self::copyTree(self::$pristineCalendars . DIRECTORY_SEPARATOR . $rite, $folder);
        }
    }

    /**
     * Refuse to run against a working tree that an interrupted run of an earlier version
     * of this class already damaged, rather than reporting that damage as a code failure.
     *
     * This throws rather than calling self::fail(): PHPUnit 12 cannot render a failure
     * raised from setUpBeforeClass() and crashes the runner with
     * `Call to undefined method BeforeFirstTestMethodFailed::test()`.
     */
    private static function assertShippedCalendarsIntact(string $realRoot): void
    {
        foreach ([self::hrCalendarFile($realRoot), self::hrI18nFile($realRoot)] as $required) {
            if (!is_file($required)) {
                throw new \RuntimeException(sprintf(
                    'The HR national-calendar source data is missing from the working tree (%s). '
                    . 'An interrupted run of an earlier version of this test may have deleted it; '
                    . 'recover with `git checkout -- jsondata/`.',
                    $required
                ));
            }
        }
    }

    /**
     * Resolve the HR national-calendar file against an explicit project root, so a test can
     * name the fixture copy and the working-tree original in the same breath. $root must
     * carry a trailing directory separator, as Router::$apiFilePath does.
     */
    private static function hrCalendarFile(string $root): string
    {
        return $root . strtr(JsonData::NATIONAL_CALENDAR_FILE->value, ['{nation}' => 'HR']);
    }

    /** The HR i18n locale file, resolved against an explicit project root. */
    private static function hrI18nFile(string $root): string
    {
        return $root . strtr(JsonData::NATIONAL_CALENDAR_I18N_FILE->value, ['{nation}' => 'HR', '{locale}' => 'hr_HR']);
    }

    /** The MT national-calendar file, resolved against an explicit project root. */
    private static function mtCalendarFile(string $root): string
    {
        return $root . strtr(JsonData::NATIONAL_CALENDAR_FILE->value, ['{nation}' => 'MT']);
    }

    /**
     * Issue #786: /data reads the rite's own partition of the source tree. Before the
     * rite segment existed, the Ambrosian diocesan calendars were unreachable — the
     * handler could only resolve Roman-only JsonData constants, so this was a 404.
     */
    public function testGetAmbrosianDiocesanCalendarReadsTheAmbrosianTree(): void
    {
        $response = ( new RegionalDataHandler(['diocese', 'lugano_ch'], Rite::AMBROSIAN) )
            ->handle($this->requestFor('GET', '/data/ambrosian/diocese/lugano_ch', ['Accept-Language' => 'it-IT']));

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeJsonBody($response);
        self::assertArrayHasKey('litcal', $body);
        self::assertNotEmpty($body['litcal']);
    }

    /**
     * The GET path stamps the i18n `name` onto every `liturgical_event` row (see
     * RegionalDataHandler::readCalendar(), around the `$liturgicalEvent->name = $localeData->{$eventKey};`
     * line), including `setProperty` rows whose schema branches did not originally declare an optional
     * `name` property (unlike the `createNew` branches, which always did). That meant a GET response body
     * could fail its own published `DiocesanCalendar` schema — silently, since the GET path never
     * self-validates — and a subsequent PUT/PATCH of that same body would then be rejected by
     * RegionalDataHandler's write-path schema validation. Lugano (`lugano_ch`) has `setProperty:grade`,
     * `setProperty:common`, and `setProperty:name` rows, so it exercises all three previously-invalid
     * branches at once.
     */
    public function testGetAmbrosianDiocesanCalendarNameStampedBodyValidatesAgainstSchema(): void
    {
        $response = ( new RegionalDataHandler(['diocese', 'lugano_ch'], Rite::AMBROSIAN) )
            ->handle($this->requestFor('GET', '/data/ambrosian/diocese/lugano_ch', ['Accept-Language' => 'it-IT']));

        self::assertSame(200, $response->getStatusCode());

        // Decode as objects (not associative arrays) — swaggest/json-schema validates stdClass instances.
        $decoded = json_decode((string) $response->getBody(), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $decoded);

        // Sanity check: at least one row must actually have been name-stamped by the GET path,
        // otherwise this test would pass vacuously without exercising the defect.
        $stampedRows = array_filter(
            $decoded->litcal,
            static fn (\stdClass $row): bool => property_exists($row->liturgical_event, 'name')
        );
        self::assertNotEmpty($stampedRows, 'The GET path must have stamped a `name` onto at least one row.');

        $schema = Schema::import(LitSchema::DIOCESAN->path());
        $schema->in($decoded);
        self::assertTrue(true, 'The name-stamped GET response body must validate against the DiocesanCalendar schema.');
    }

    public function testGetRomanDiocesanCalendarStillReadsTheRomanTree(): void
    {
        $response = ( new RegionalDataHandler(['diocese', 'rotter_nl'], Rite::ROMAN) )
            ->handle($this->requestFor('GET', '/data/roman/diocese/rotter_nl', ['Accept-Language' => 'nl-NL']));

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('litcal', $this->decodeJsonBody($response));
    }

    public function testGetAmbrosianDiocesanI18nReadsTheAmbrosianTree(): void
    {
        // The i18n file for an Ambrosian diocese lives beside its calendar, under
        // rite/ambrosian/, not in the Roman tree.
        $response = ( new RegionalDataHandler(['diocese', 'lugano_ch', 'it_IT'], Rite::AMBROSIAN) )
            ->handle($this->requestFor('GET', '/data/ambrosian/diocese/lugano_ch/it_IT', ['Accept-Language' => 'it-IT']));

        self::assertSame(200, $response->getStatusCode());
        self::assertNotEmpty($this->decodeJsonBody($response));
    }

    public function testDiocesanCalendarOfAnotherRiteIsRejected(): void
    {
        // Requesting an Ambrosian diocese under the Roman rite would otherwise read
        // and write the wrong partition of the source tree.
        $this->expectException(UnprocessableContentException::class);
        $this->expectExceptionMessage('belongs to the ambrosian rite, not the requested roman rite');

        ( new RegionalDataHandler(['diocese', 'lugano_ch'], Rite::ROMAN) )
            ->handle($this->requestFor('GET', '/data/diocese/lugano_ch', ['Accept-Language' => 'it-IT']));
    }

    public function testRomanDioceseUnderTheAmbrosianRiteIsRejected(): void
    {
        $this->expectException(UnprocessableContentException::class);
        $this->expectExceptionMessage('belongs to the roman rite, not the requested ambrosian rite');

        ( new RegionalDataHandler(['diocese', 'rotter_nl'], Rite::AMBROSIAN) )
            ->handle($this->requestFor('GET', '/data/ambrosian/diocese/rotter_nl', ['Accept-Language' => 'nl-NL']));
    }

    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new RegionalDataHandler() )->handle(
            $this->requestFor('OPTIONS', '/data', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testTooFewPathParamsIsValidationError(): void
    {
        // GET requires at least two segments (category + key); pass one.
        $this->expectException(ValidationException::class);
        ( new RegionalDataHandler(['nation']) )
            ->handle($this->requestFor('GET', '/data/nation'));
    }

    public function testInvalidCategoryIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        ( new RegionalDataHandler(['planet', 'mars']) )
            ->handle($this->requestFor('GET', '/data/planet/mars'));
    }

    public function testGetForUnknownNationalCalendarIsUnprocessable(): void
    {
        // 'ZZ' isn't a real nation key; the handler surfaces an
        // UnprocessableContentException listing valid keys.
        $this->expectException(UnprocessableContentException::class);
        ( new RegionalDataHandler(['nation', 'ZZ']) )
            ->handle($this->requestFor('GET', '/data/nation/ZZ'));
    }

    public function testPutWithoutPayloadIsValidationError(): void
    {
        // PUT requires exactly 2 path params (category + key). Passing the
        // request without a body trips the empty-payload check in
        // parseBodyPayload → ValidationException.
        $this->expectException(ValidationException::class);
        ( new RegionalDataHandler(['nation', 'ZZ']) )
            ->handle($this->requestFor('PUT', '/data/nation/ZZ', ['Content-Type' => 'application/json'], ''));
    }

    /**
     * A `PUT /data/nation` whose `nation` is a syntactically-valid but unassigned
     * code (e.g. ZZ) must be rejected: the create path is gated by the
     * CommonDef.json `Nation` enum — the same ISO 3166-1 alpha-2 set the
     * access-request flow validates against.
     */
    public function testCreateNationalCalendarRejectsNonIsoNationCode(): void
    {
        // 'ZZ' passes NationalMetadata's [A-Z]{2} format check but is NOT an
        // assigned ISO 3166-1 alpha-2 code, so the create gate must reject it
        // (the same validator the access-request flow uses).
        $payload = [
            'litcal'   => [
                [
                    'liturgical_event' => ['event_key' => 'StGeorgeMartyr', 'grade' => 4],
                    'metadata'         => ['action' => 'makePatron', 'since_year' => 1868, 'url' => 'https://www.vatican.va/'],
                ],
            ],
            'settings' => [
                'epiphany'               => 'JAN6',
                'ascension'              => 'SUNDAY',
                'corpus_christi'         => 'SUNDAY',
                'eternal_high_priest'    => false,
                'holydays_of_obligation' => [
                    'Christmas'            => true,
                    'Epiphany'             => false,
                    'Ascension'            => false,
                    'CorpusChristi'        => false,
                    'MaryMotherOfGod'      => true,
                    'ImmaculateConception' => true,
                    'Assumption'           => true,
                    'StJoseph'             => false,
                    'StsPeterPaulAp'       => false,
                    'AllSaints'            => false,
                ],
            ],
            'metadata' => [
                'nation'  => 'ZZ',
                'missals' => ['IT_1983'],
                'locales' => ['en'],
            ],
            'i18n'     => [
                'en' => ['StGeorgeMartyr' => 'Saint George, Martyr'],
            ],
        ];

        $this->expectException(UnprocessableContentException::class);
        ( new RegionalDataHandler(['nation', 'ZZ']) )->handle($this->requestFor('PUT', '/data/nation/ZZ', [], $payload));
    }

    public function testPutWithSinglePathParamIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        ( new RegionalDataHandler(['nation']) )
            ->handle($this->requestFor('PUT', '/data/nation', ['Content-Type' => 'application/json'], ''));
    }

    public function testPutPathBodyKeyMismatchIsUnprocessable(): void
    {
        // Unlike testCreateNationalCalendarRejectsNonIsoNationCode's 'ZZ' payload (which
        // is missing `wider_region` and uses a non-ISO nation code, both of which trip
        // schema validation before the key is ever extracted), this payload must be
        // schema-valid so that the mismatch check — not an unrelated schema error — is
        // what fires. PUT to /data/nation/XK with metadata.nation = 'IT': path/body key
        // mismatch must be rejected before any create-condition checks (including the
        // "already exists" / ISO-region gate in checkNationalCalendarConditions).
        $payload = [
            'litcal'   => [
                [
                    'liturgical_event' => ['event_key' => 'StGeorgeMartyr', 'grade' => 4],
                    'metadata'         => ['action' => 'makePatron', 'since_year' => 1868, 'url' => 'https://www.vatican.va/'],
                ],
            ],
            'settings' => [
                'epiphany'               => 'JAN6',
                'ascension'              => 'SUNDAY',
                'corpus_christi'         => 'SUNDAY',
                'eternal_high_priest'    => false,
                'holydays_of_obligation' => [
                    'Christmas'            => true,
                    'Epiphany'             => false,
                    'Ascension'            => false,
                    'CorpusChristi'        => false,
                    'MaryMotherOfGod'      => true,
                    'ImmaculateConception' => true,
                    'Assumption'           => true,
                    'StJoseph'             => false,
                    'StsPeterPaulAp'       => false,
                    'AllSaints'            => false,
                ],
            ],
            'metadata' => [
                'nation'       => 'IT',
                'wider_region' => 'europe',
                'missals'      => ['IT_1983'],
                'locales'      => ['it_IT'],
            ],
            'i18n'     => [
                'it_IT' => ['StGeorgeMartyr' => 'San Giorgio, Martire'],
            ],
        ];

        $this->expectException(UnprocessableContentException::class);
        $this->expectExceptionMessage('The key in the request path does not match the key in the payload');
        ( new RegionalDataHandler(['nation', 'XK']) )->handle($this->requestFor('PUT', '/data/nation/XK', [], $payload));
    }

    public function testDeletePurgeFailureDoesNotFailDeletion(): void
    {
        // Same HR fixture as testDeleteCalendarPurgesOperationalTuples: the delete lands
        // in the shadow root, and setUp() restores that copy before the next test.
        // The purge throws, but the calendar files are already deleted — the DELETE
        // must still succeed (200); the failure is logged and the reconciler retries.
        $purge = $this->createStub(ResourceTuplePurgeServiceInterface::class);
        $purge->method('purgeForObject')->willThrowException(new \RuntimeException('FGA unavailable'));

        $handler = new RegionalDataHandler(['nation', 'HR']);
        $handler->setPurgeService($purge);

        $response = $handler->handle($this->requestFor('DELETE', '/data/nation/HR'));

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * After a successful calendar DELETE, the handler must call
     * ResourceTuplePurgeService::purgeForObject() with the correct FGA object
     * identifier so that editor/viewer operational tuples are cleaned up.
     *
     * Croatia (HR) is used as the fixture nation because it has no diocesan
     * calendars in the bundled source data, so the DELETE pre-check that
     * rejects nations still in use by diocesan calendars passes cleanly.
     * The files this deletes are the shadow root's copies; setUp() restores
     * them from the pristine snapshot before the next test.
     */
    public function testDeleteCalendarPurgesOperationalTuples(): void
    {
        // --- Build handler with injected mock purge service ------------------
        $handler = new RegionalDataHandler(['nation', 'HR']);

        $purge = $this->createMock(ResourceTuplePurgeServiceInterface::class);
        $purge->expects($this->once())
            ->method('purgeForObject')
            ->with('national_calendar:roman/HR');
        $handler->setPurgeService($purge);

        // --- Act: issue DELETE (bypasses JWT middleware — in-process) --------
        $request  = $this->requestFor('DELETE', '/data/nation/HR');
        $response = $handler->handle($request);

        // --- Assert ----------------------------------------------------------
        self::assertSame(200, $response->getStatusCode());
        // purgeForObject assertion is enforced by the mock expectation above
    }

    /**
     * #935: a DELETE must consume the shadow root's copy of the HR calendar and leave the
     * tracked working-tree files alone.
     *
     * This is the regression guard for the defect itself rather than for handler behaviour:
     * before the shadow root, the handler deleted the real files and the only surviving copy
     * of them was a PHP string in this class, written back in tearDown(). Any run that never
     * reached tearDown() — a fatal, an OOM kill, a `timeout`, a Ctrl-C — left tracked source
     * data deleted. Asserting both halves (the fixture copy is gone, the tracked file is not)
     * pins the redirect: a test that only asserted the deletion would pass just as happily
     * with Router::$apiFilePath pointing back at the repository.
     */
    public function testDeleteConsumesTheFixtureCopyAndNotTheWorkingTree(): void
    {
        $fixtureHrJson = self::hrCalendarFile(Router::$apiFilePath);
        $fixtureHrI18n = self::hrI18nFile(Router::$apiFilePath);
        self::assertFileExists($fixtureHrJson, 'setUp() must have restored the fixture copy of HR.');

        $handler = new RegionalDataHandler(['nation', 'HR']);
        $handler->setPurgeService($this->createStub(ResourceTuplePurgeServiceInterface::class));

        $response = $handler->handle($this->requestFor('DELETE', '/data/nation/HR'));
        self::assertSame(200, $response->getStatusCode());

        self::assertFileDoesNotExist($fixtureHrJson, 'The DELETE must have removed the fixture copy.');
        self::assertFileDoesNotExist($fixtureHrI18n, 'The DELETE must have removed the fixture i18n copy.');

        self::assertFileExists(
            self::hrCalendarFile(self::$realRoot),
            'The tracked HR national calendar must never be deleted from the working tree (#935).'
        );
        self::assertFileExists(
            self::hrI18nFile(self::$realRoot),
            'The tracked HR i18n file must never be deleted from the working tree (#935).'
        );
    }

    /**
     * Skips the calling test if an MT national calendar unexpectedly already exists.
     *
     * The files the MT tests create are the shadow root's, and setUp() discards them
     * before the next test, so nothing has to be cleaned up. The guard remains because
     * a shipped MT calendar would silently turn every "create" assertion below into an
     * assertion about an update: these tests would then need a different fixture nation.
     * A stray MT folder in the working tree — the leftover an interrupted pre-#935 run
     * could produce — is copied into the fixture and shows up here too.
     */
    private function requireMtNationAbsent(): void
    {
        if (file_exists(self::mtCalendarFile(Router::$apiFilePath))) {
            $this->markTestSkipped(sprintf(
                'An MT national calendar already exists (%s); skipping. '
                . 'If that is a leftover from an interrupted pre-#935 run, delete it and rerun.',
                self::mtCalendarFile(self::$realRoot)
            ));
        }
    }

    /**
     * A schema-valid national-calendar PUT/PATCH payload for Malta (MT) with
     * wider_region=Europe. The i18n section is required by the PUT/PATCH handlers.
     *
     * @return array<string,mixed>
     */
    private static function mtNationalCalendarPayload(): array
    {
        return [
            'litcal'   => [
                [
                    'liturgical_event' => ['event_key' => 'StGeorgeMartyr', 'grade' => 4],
                    'metadata'         => [
                        'action'     => 'makePatron',
                        'since_year' => 1868,
                        'url'        => 'https://www.vatican.va/',
                    ],
                ],
            ],
            'settings' => [
                'epiphany'               => 'JAN6',
                'ascension'              => 'SUNDAY',
                'corpus_christi'         => 'SUNDAY',
                'eternal_high_priest'    => false,
                'holydays_of_obligation' => [
                    'Christmas'            => true,
                    'Epiphany'             => false,
                    'Ascension'            => false,
                    'CorpusChristi'        => false,
                    'MaryMotherOfGod'      => true,
                    'ImmaculateConception' => true,
                    'Assumption'           => true,
                    'StJoseph'             => false,
                    'StsPeterPaulAp'       => false,
                    'AllSaints'            => false,
                ],
            ],
            'metadata' => [
                'nation'       => 'MT',
                'wider_region' => 'europe',
                'missals'      => ['IT_1983'],
                'locales'      => ['en_MT'],
            ],
            'i18n'     => [
                'en_MT' => ['StGeorgeMartyr' => 'Saint George, Martyr, Patron of Malta'],
            ],
        ];
    }

    /**
     * A successful PATCH on an existing national calendar must report the
     * update with the nation's English name alongside the ISO code — the same
     * format createNationalCalendar uses.
     *
     * Creates MT via PUT first (no OpenFGA env forced, so no outbox/DB needed),
     * then updates it via PATCH, both inside the shadow root.
     */
    public function testUpdateNationalCalendarSucceeds(): void
    {
        $this->requireMtNationAbsent();

        $payload = self::mtNationalCalendarPayload();

        $createResponse = ( new RegionalDataHandler(['nation', 'MT']) )
            ->handle($this->requestFor('PUT', '/data/nation/MT', [], $payload));
        self::assertSame(201, $createResponse->getStatusCode());

        // PATCH must send a locale the MT calendar supports (defaults to Latin otherwise).
        $updateResponse = ( new RegionalDataHandler(['nation', 'MT']) )
            ->handle($this->requestFor('PATCH', '/data/nation/MT', ['Accept-Language' => 'en-MT'], $payload));

        self::assertSame(200, $updateResponse->getStatusCode());
        $body = $this->decodeJsonBody($updateResponse);
        self::assertSame('Calendar data updated for Nation "Malta" ("MT")', $body['success']);
    }

    /**
     * A nation's declared wider regions must exist and must list the nation among their own
     * `national_calendars` (#1005).
     */
    public function testPutRefusesAnUnknownWiderRegion(): void
    {
        $this->requireMtNationAbsent();
        $payload = self::mtNationalCalendarPayload();
        unset($payload['metadata']['wider_region']);
        $payload['metadata']['wider_regions'] = ['europe', 'atlantis'];

        try {
            ( new RegionalDataHandler(['nation', 'MT']) )->handle($this->requestFor('PUT', '/data/nation/MT', [], $payload));
            self::fail('An unknown wider region must be refused.');
        } catch (UnprocessableContentException $e) {
            self::assertStringContainsString('atlantis', $e->getMessage());
            self::assertStringContainsString('europe', $e->getMessage(), 'Names the known regions');
        }
    }

    public function testPutRefusesARegionWhoseRosterLacksTheNation(): void
    {
        $this->requireMtNationAbsent();
        $payload = self::mtNationalCalendarPayload();
        unset($payload['metadata']['wider_region']);
        $payload['metadata']['wider_regions'] = ['americas'];

        $this->expectException(UnprocessableContentException::class);
        $this->expectExceptionMessage('americas');

        ( new RegionalDataHandler(['nation', 'MT']) )->handle($this->requestFor('PUT', '/data/nation/MT', [], $payload));
    }

    public function testALegacyPatchIsAcceptedStoredAsAListAndWarned(): void
    {
        $payload = self::shippedNationalCalendarPayload('HR');
        unset($payload['metadata']['wider_regions']);
        $payload['metadata']['wider_region'] = 'europe';

        $response = ( new RegionalDataHandler(['nation', 'HR']) )
            ->handle($this->requestFor('PATCH', '/data/nation/HR', ['Accept-Language' => 'hr-HR'], $payload));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertStringContainsString('wider_regions', implode(' ', $body['warnings'] ?? []));

        $stored = json_decode((string) file_get_contents(self::hrCalendarFile(Router::$apiFilePath)), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['europe'], $stored['metadata']['wider_regions']);
        self::assertArrayNotHasKey('wider_region', $stored['metadata']);
    }

    public function testALegacyRegionNameIsStoredAsItsIdAndWarned(): void
    {
        $payload                              = self::shippedNationalCalendarPayload('HR');
        $payload['metadata']['wider_regions'] = ['Europe'];

        $response = ( new RegionalDataHandler(['nation', 'HR']) )
            ->handle($this->requestFor('PATCH', '/data/nation/HR', ['Accept-Language' => 'hr-HR'], $payload));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertStringContainsString('Wider region names are deprecated', implode(' ', $body['warnings'] ?? []));

        $stored = json_decode((string) file_get_contents(self::hrCalendarFile(Router::$apiFilePath)), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['europe'], $stored['metadata']['wider_regions']);
    }

    public function testAPatchEchoingBothAgreeingFieldsIsAccepted(): void
    {
        $payload                             = self::shippedNationalCalendarPayload('HR');
        $payload['metadata']['wider_region'] = 'europe';   // beside the stored wider_regions: ["europe"]

        $response = ( new RegionalDataHandler(['nation', 'HR']) )
            ->handle($this->requestFor('PATCH', '/data/nation/HR', ['Accept-Language' => 'hr-HR'], $payload));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testALegacyPatchToANationInSeveralRegionsIsRefused(): void
    {
        // Give HR a second region in the shadow root: a synthetic Balkans region whose roster lists Croatia.
        self::writeRegion('balkans', ['Croatia' => 'HR'], ['hr_HR']);
        $file                            = self::hrCalendarFile(Router::$apiFilePath);
        $hr                              = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $hr['metadata']['wider_regions'] = ['europe', 'balkans'];
        file_put_contents($file, json_encode($hr, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $payload = self::shippedNationalCalendarPayload('HR');
        unset($payload['metadata']['wider_regions']);
        $payload['metadata']['wider_region'] = 'europe';

        $this->expectException(UnprocessableContentException::class);
        $this->expectExceptionMessage('wider_regions');

        ( new RegionalDataHandler(['nation', 'HR']) )
            ->handle($this->requestFor('PATCH', '/data/nation/HR', ['Accept-Language' => 'hr-HR'], $payload));
    }

    public function testGetKeepsTheDeprecatedSingleFormForOneRegion(): void
    {
        $response = ( new RegionalDataHandler(['nation', 'HR']) )
            ->handle($this->requestFor('GET', '/data/nation/HR', ['Accept-Language' => 'hr-HR']));

        $body = $this->decodeJsonBody($response);
        self::assertSame(['europe'], $body['metadata']['wider_regions']);
        self::assertSame('europe', $body['metadata']['wider_region']);
    }

    /**
     * #1018: the old Frontend still asks for `/data/widerregion/Europe`. The Router rewrites the key to the id before the
     * handler is built, so the handler reads `europe/europe.json` and never looks for (or creates) an `Europe/` folder.
     */
    public function testALegacyRegionKeyReadsTheRegionByItsId(): void
    {
        $parts = ['widerregion', 'Europe'];
        self::assertTrue(Router::canonicaliseWiderRegionKey($parts));

        $response = ( new RegionalDataHandler($parts) )
            ->handle($this->requestFor('GET', '/data/widerregion/europe', ['Accept-Language' => 'it-IT']));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertArrayHasKey('litcal', $body);

        $regionsFolder = Router::$apiFilePath . JsonData::WIDER_REGIONS_FOLDER->value;
        self::assertDirectoryExists(rtrim($regionsFolder, '/') . '/europe');
        self::assertDirectoryDoesNotExist(rtrim($regionsFolder, '/') . '/Europe');
    }

    public function testDeletingARegionANationStillDeclaresIsRefused(): void
    {
        $this->expectException(UnprocessableContentException::class);

        ( new RegionalDataHandler(['widerregion', 'europe']) )->handle($this->requestFor('DELETE', '/data/widerregion/europe'));
    }

    /**
     * Writes a minimal valid region into the shadow root, reusing Europe's file as a template.
     *
     * @param array<string,string> $members name => nation code
     * @param list<string>         $locales
     */
    private static function writeRegion(string $name, array $members, array $locales): void
    {
        $europe = json_decode((string) file_get_contents(strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => 'europe'])), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($europe);
        $region                       = $europe;
        $region['litcal']             = [$europe['litcal'][0]];
        $region['national_calendars'] = $members;
        $region['metadata']           = ['wider_region' => $name, 'locales' => $locales];

        $folder = dirname(strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => $name]));
        if (!is_dir("{$folder}/i18n") && !mkdir("{$folder}/i18n", 0777, true)) {
            throw new \RuntimeException("Cannot create {$folder}/i18n");
        }
        file_put_contents("{$folder}/{$name}.json", json_encode($region, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        foreach ($locales as $locale) {
            file_put_contents("{$folder}/i18n/{$locale}.json", json_encode([$europe['litcal'][0]['liturgical_event']['event_key'] => $name], JSON_THROW_ON_ERROR));
        }
    }

    /**
     * When a national calendar whose payload declares a wider_region is created
     * via PUT, the handler must enqueue a WRITE_TUPLE outbox row that links
     * `national_calendar:<N>` to `wider_region:<R>` via the `member_nation`
     * relation.
     *
     * Malta (MT) is used as the fixture nation because:
     * - It is a valid European nation code recognised by PHP's ICU locales.
     * - It has no existing national calendar in the bundled source data, so
     *   the PUT does not trigger a ResourceConflictException.
     *
     * The MT files the handler writes land in the shadow root, which setUp()
     * resets before the next test and tearDownAfterClass() deletes outright.
     */
    public function testCreateNationalCalendarEnqueuesMemberNationTuple(): void
    {
        // --- Arrange: confirm the fixture really has no MT calendar yet ------
        $this->requireMtNationAbsent();

        // --- Build handler with injected mock OutboxRepository ----------------
        // PUT requests expect exactly TWO path params (category + key); the key
        // in the path must match the nation key in the payload body.
        $handler = new RegionalDataHandler(['nation', 'MT']);

        $repo = $this->createMock(OutboxBatchInsertInterface::class);
        $repo->expects($this->atLeastOnce())
            ->method('insertBatch')
            ->with($this->callback(function (array $rows): bool {
                foreach ($rows as $r) {
                    if (
                        $r['fga_user'] === 'national_calendar:roman/MT'
                        && $r['fga_relation'] === 'member_nation'
                        && $r['fga_object'] === 'wider_region:roman/europe'
                    ) {
                        return true;
                    }
                }
                return false;
            }))
            ->willReturn([99]);
        $handler->setOutboxRepository($repo);

        // Build a valid PUT payload for Malta (MT) with wider_region=Europe.
        $payload = self::mtNationalCalendarPayload();

        // Force OpenFGA "configured" so the create-sync transaction + processSync
        // branches execute (CI does not configure OpenFGA, so they would otherwise
        // be skipped). Requires Postgres for the outbox PDO; skip cleanly if absent.
        try {
            Connection::getInstance();
        } catch (\Throwable) {
            $this->markTestSkipped('Postgres not available for the create-sync coverage path.');
        }
        $fake  = ['OPENFGA_API_URL' => 'http://localhost:8080', 'OPENFGA_STORE_ID' => 'store-test', 'OPENFGA_MODEL_ID' => 'model-test'];
        $saved = [];
        foreach ($fake as $k => $v) {
            $saved[$k] = [array_key_exists($k, $_ENV) ? (string) $_ENV[$k] : null, getenv($k)];
            $_ENV[$k]  = $v;
            putenv("{$k}={$v}");
        }

        try {
            // --- Act: issue PUT (bypasses JWT middleware — in-process) -------
            $response = $handler->handle($this->requestFor('PUT', '/data/nation/MT', [], $payload));
            self::assertContains($response->getStatusCode(), [200, 201]);
            // insertBatch assertion is enforced by the mock expectation above
        } finally {
            foreach ($saved as $k => [$envVal, $getenvVal]) {
                if ($envVal === null) {
                    unset($_ENV[$k]);
                } else {
                    $_ENV[$k] = $envVal;
                }
                if ($getenvVal === false) {
                    putenv($k);
                } else {
                    putenv("{$k}={$getenvVal}");
                }
            }
        }
    }

    /**
     * Builds a handler with a mock outbox repository that captures every inserted row without
     * processing it, so a test can assert on the rows a write path enqueues without a live
     * OpenFGA/Postgres.
     *
     * @param array<int,string> $path
     * @return array{0: RegionalDataHandler, 1: \ArrayObject<int, array<string,mixed>>}
     */
    private function handlerCapturingOutbox(array $path): array
    {
        $handler  = new RegionalDataHandler($path);
        $captured = new \ArrayObject();
        // A stub, not a mock: nothing here asserts on the call itself — only the rows it
        // captures, which the calling test inspects afterwards.
        $repo = $this->createStub(OutboxBatchInsertInterface::class);
        $repo->method('insertBatch')->willReturnCallback(static function (array $rows) use ($captured): array {
            foreach ($rows as $row) {
                $captured->append($row);
            }
            return range(1, count($rows));
        });
        $handler->setOutboxRepository($repo);

        return [$handler, $captured];
    }

    /**
     * A nation moving from a single region to a different one must write the newly-declared
     * region and delete the one it no longer declares (#1005).
     */
    public function testPatchWritesAddedRegionsAndDeletesRemovedOnes(): void
    {
        self::writeRegion('balkans', ['Croatia' => 'HR'], ['hr_HR']);
        $payload                              = self::shippedNationalCalendarPayload('HR');
        $payload['metadata']['wider_regions'] = ['balkans'];

        [$handler, $rows] = $this->handlerCapturingOutbox(['nation', 'HR']);
        $this->withoutEnv(
            ['OPENFGA_API_URL', 'OPENFGA_STORE_ID', 'OPENFGA_MODEL_ID'],
            fn () => $handler->handle($this->requestFor('PATCH', '/data/nation/HR', ['Accept-Language' => 'hr-HR'], $payload))
        );

        $summary = array_map(static fn ($r) => $r['operation']->value . ' ' . $r['fga_object'], $rows->getArrayCopy());
        self::assertSame(['write_tuple wider_region:roman/balkans', 'delete_tuple wider_region:roman/europe'], $summary);
    }

    /** A handler whose outbox insert always fails, as it would with Postgres unreachable. */
    private function handlerWithFailingOutbox(array $path): RegionalDataHandler
    {
        $handler = new RegionalDataHandler($path);
        $repo    = $this->createStub(OutboxBatchInsertInterface::class);
        $repo->method('insertBatch')->willThrowException(new \RuntimeException('outbox unavailable'));
        $handler->setOutboxRepository($repo);

        return $handler;
    }

    /**
     * Once an applied PATCH has written the calendar, a failure to record its membership must not
     * turn the completed write into a 500: the client would retry a write that already happened.
     * The response is truthful and says what to run to repair the membership (#1007 review).
     */
    public function testAPatchWhoseMembershipCannotBeRecordedStillSucceedsAndSaysSo(): void
    {
        self::writeRegion('balkans', ['Croatia' => 'HR'], ['hr_HR']);
        $payload                              = self::shippedNationalCalendarPayload('HR');
        $payload['metadata']['wider_regions'] = ['balkans'];

        $handler  = $this->handlerWithFailingOutbox(['nation', 'HR']);
        $response = $this->withoutEnv(
            ['OPENFGA_API_URL', 'OPENFGA_STORE_ID', 'OPENFGA_MODEL_ID'],
            fn () => $handler->handle($this->requestFor('PATCH', '/data/nation/HR', ['Accept-Language' => 'hr-HR'], $payload))
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('seed-wider-region-membership.php', implode(' ', $this->decodeJsonBody($response)['warnings'] ?? []));
        $stored = json_decode((string) file_get_contents(self::hrCalendarFile(Router::$apiFilePath)), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['balkans'], $stored['metadata']['wider_regions']);
    }

    /** The same holds for creation: the calendar exists, so the PUT answers 201 and warns (#1007 review). */
    public function testAPutWhoseMembershipCannotBeRecordedStillSucceedsAndSaysSo(): void
    {
        $this->requireMtNationAbsent();
        $payload = self::mtNationalCalendarPayload();

        $handler  = $this->handlerWithFailingOutbox(['nation', 'MT']);
        $response = $this->withoutEnv(
            ['OPENFGA_API_URL', 'OPENFGA_STORE_ID', 'OPENFGA_MODEL_ID'],
            fn () => $handler->handle($this->requestFor('PUT', '/data/nation/MT', [], $payload))
        );

        self::assertSame(201, $response->getStatusCode());
        $warnings = implode(' ', $this->decodeJsonBody($response)['warnings'] ?? []);
        self::assertStringContainsString('seed-wider-region-membership.php', $warnings);
        self::assertStringContainsString('deprecated', $warnings, 'The legacy-form warning is kept alongside');
        self::assertFileExists(self::mtCalendarFile(Router::$apiFilePath));
    }

    /** A PATCH that leaves the declared regions unchanged must enqueue nothing (#1005). */
    public function testPatchKeepingTheSameRegionsEnqueuesNothing(): void
    {
        [$handler, $rows] = $this->handlerCapturingOutbox(['nation', 'HR']);
        $this->withoutEnv(
            ['OPENFGA_API_URL', 'OPENFGA_STORE_ID', 'OPENFGA_MODEL_ID'],
            fn () => $handler->handle($this->requestFor('PATCH', '/data/nation/HR', ['Accept-Language' => 'hr-HR'], self::shippedNationalCalendarPayload('HR')))
        );

        self::assertCount(0, $rows);
    }

    /** Deleting a nation must delete the `member_nation` tuple for every region it declared (#1005). */
    public function testDeletingANationRemovesItsMembership(): void
    {
        [$handler, $rows] = $this->handlerCapturingOutbox(['nation', 'HR']);
        $this->withoutEnv(
            ['OPENFGA_API_URL', 'OPENFGA_STORE_ID', 'OPENFGA_MODEL_ID'],
            fn () => $handler->handle($this->requestFor('DELETE', '/data/nation/HR'))
        );

        $summary = array_map(static fn ($r) => $r['operation']->value . ' ' . $r['fga_object'], $rows->getArrayCopy());
        self::assertSame(['delete_tuple wider_region:roman/europe'], $summary);
    }

    /**
     * A schema-valid diocesan payload carrying a color licit only in the *other* rite must be
     * rejected by the write path (issue #771).
     *
     * The JSON Schemas enumerate the union of both rites' colors, because JSON Schema cannot
     * key a `color` facet off `metadata.rite` elsewhere in the document. The write path is the
     * one place that holds both the payload and its declared rite, so it is where the
     * rite-scoping is enforced — against the single authoritative palette in
     * {@see \LiturgicalCalendar\Api\Models\Calendar\Rite\RiteProfile::colors()}.
     *
     * @return array<string,array{0:string,1:string,2:string,3:string}>
     */
    public static function illicitRiteColorProvider(): array
    {
        return [
            // Exactly the defect #772 fixed in the shipped data, now caught at write time.
            'roman purple in an ambrosian diocese' => ['ambrosian', 'purple', 'novara_it', 'Diocesi di Novara'],
            'roman rose in an ambrosian diocese'   => ['ambrosian', 'rose', 'novara_it', 'Diocesi di Novara'],
            'ambrosian morello in a roman diocese' => ['roman', 'morello', 'romamo_it', 'Diocesi di Roma'],
            'ambrosian black in a roman diocese'   => ['roman', 'black', 'romamo_it', 'Diocesi di Roma'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('illicitRiteColorProvider')]
    public function testPutDiocesanCalendarRejectsColorIllicitInDeclaredRite(
        string $rite,
        string $color,
        string $dioceseId,
        string $dioceseName
    ): void {
        $payload = self::diocesanPayloadWithColor($rite, $color, $dioceseId, $dioceseName);

        $this->expectException(UnprocessableContentException::class);
        // Assert on the message, not merely the type: several unrelated conditions on this
        // path also raise UnprocessableContentException (unknown key, already exists, …), and
        // a bare type assertion would pass for the wrong reason.
        $this->expectExceptionMessage("not licit in the {$rite} rite");

        ( new RegionalDataHandler(['diocese', $dioceseId]) )
            ->handle($this->requestFor('PUT', "/data/diocese/{$dioceseId}", [], $payload));
    }

    /**
     * Control: the same payload shape with a color the declared rite *does* admit must get
     * past the color gate. It may still fail later (the diocese already exists), so this
     * asserts only that the failure is not the color one.
     */
    /**
     * A diocesan calendar depends on its national calendar (#993): Agen is a real diocese, so it
     * passes the dioceses-data checks, but France has no national calendar to inherit from.
     */
    public function testPutDiocesanCalendarRejectsANationWithoutANationalCalendar(): void
    {
        $franceFile = Router::$apiFilePath . strtr(JsonData::NATIONAL_CALENDAR_FILE->value, ['{nation}' => 'FR']);
        if (file_exists($franceFile)) {
            $this->markTestSkipped('An FR national calendar already exists; this test needs a nation without one.');
        }

        $payload                         = self::diocesanPayloadWithColor('roman', 'white', 'ageneg_fr', 'Agen');
        $payload['metadata']['nation']   = 'FR';
        $payload['metadata']['locales']  = ['fr_FR'];
        $payload['metadata']['timezone'] = 'Europe/Paris';
        $payload['i18n']                 = ['fr_FR' => ['StsProtaseGervase' => 'Saints Gervais et Protais']];

        try {
            ( new RegionalDataHandler(['diocese', 'ageneg_fr']) )
                ->handle($this->requestFor('PUT', '/data/diocese/ageneg_fr', [], $payload));
            self::fail('A diocesan calendar whose nation has no national calendar must be rejected.');
        } catch (UnprocessableContentException $e) {
            self::assertStringContainsString('national calendar of FR', $e->getMessage());
        }

        self::assertDirectoryDoesNotExist(
            JsonData::DIOCESAN_CALENDARS_FOLDER->path() . '/FR',
            'Nothing is written for a rejected PUT.'
        );
    }

    public function testPutDiocesanCalendarAcceptsColorLicitInDeclaredRite(): void
    {
        $payload = self::diocesanPayloadWithColor('ambrosian', 'morello', 'novara_it', 'Diocesi di Novara');

        try {
            ( new RegionalDataHandler(['diocese', 'novara_it']) )
                ->handle($this->requestFor('PUT', '/data/diocese/novara_it', [], $payload));
            self::assertTrue(true, 'morello passed the rite color gate');
        } catch (\Throwable $e) {
            self::assertStringNotContainsString(
                'not licit in the',
                $e->getMessage(),
                'morello is licit in the ambrosian rite and must not be rejected by the color gate'
            );
        }
    }

    /**
     * `color_ad_libitum` (issue #781) is a *sanctorale* property: it is declared on
     * `PropriumDeSanctis.json` and resolved by the Ambrosian sanctorale assembly, and no
     * `/data`-writable schema permits it. A diocesan payload carrying it is therefore
     * rejected outright by schema validation, before the rite-scoped colour gate is reached.
     *
     * This pins that boundary deliberately. `collectIllicitColors()` does understand the
     * `color_ad_libitum` shape — a bare `color` string rather than an array — so the gate is
     * ready if the property is ever extended to diocesan or national calendars, but until
     * both the schema and the engine support it there, admitting it here would promise
     * behaviour the calculation does not implement.
     */
    public function testPutDiocesanCalendarRejectsAdLibitumColorAsUnknownProperty(): void
    {
        $payload                                                      = self::diocesanPayloadWithColor('ambrosian', 'morello', 'novara_it', 'Diocesi di Novara');
        $payload['litcal'][0]['liturgical_event']['color_ad_libitum'] = [
            ['color' => 'black', 'when' => 'not_sunday'],
        ];

        $this->expectException(UnprocessableContentException::class);
        $this->expectExceptionMessage('Schema validation error');

        ( new RegionalDataHandler(['diocese', 'novara_it']) )
            ->handle($this->requestFor('PUT', '/data/diocese/novara_it', [], $payload));
    }

    /**
     * A payload whose `i18n` covers fewer locales than `metadata.locales` announces must be
     * rejected as unprocessable content, NOT crash with an uncaught \ValueError (issue #462).
     *
     * JSON Schema cannot express "the keys of this object equal the values of that array", so
     * the invariant is enforced in the RegionalData model layer, which signals a breach with a
     * plain \ValueError. Nothing on the write path caught it, so a client that submitted a
     * short `i18n` — which the diocesan editor did whenever a save beat its secondary-locale
     * translation fetch — got a 500 blaming the server for its own bad request.
     *
     * Covered here for the diocesan and national categories; wider-region reaches the same
     * model check through the identical `fromObject()` write path.
     */
    public function testPutDiocesanCalendarRejectsI18nNarrowerThanDeclaredLocales(): void
    {
        $payload                        = self::diocesanPayloadWithColor('ambrosian', 'morello', 'novara_it', 'Diocesi di Novara');
        $payload['metadata']['locales'] = ['it_IT', 'la_VA'];
        // `i18n` still carries it_IT alone — exactly the mismatch the model rejects.

        $this->expectException(UnprocessableContentException::class);
        // Assert on the message, not merely the type: many unrelated conditions on this path
        // also raise UnprocessableContentException, so a bare type assertion could pass for
        // the wrong reason.
        $this->expectExceptionMessage('keys of i18n parameter must be the same as the values of metadata.locales');

        ( new RegionalDataHandler(['diocese', 'novara_it']) )
            ->handle($this->requestFor('PUT', '/data/diocese/novara_it', [], $payload));
    }

    /**
     * The national category has its own `fromObject()` call, so it needs its own guard — the
     * defect was never diocese-specific.
     */
    public function testPutNationalCalendarRejectsI18nNarrowerThanDeclaredLocales(): void
    {
        $payload                        = self::mtNationalCalendarPayload();
        $payload['metadata']['locales'] = ['en_MT', 'it_IT'];
        // `i18n` still carries en_MT alone.

        $this->expectException(UnprocessableContentException::class);
        $this->expectExceptionMessage('keys of i18n parameter must be the same as the values of metadata.locales');

        ( new RegionalDataHandler(['nation', 'MT']) )
            ->handle($this->requestFor('PUT', '/data/nation/MT', [], $payload));
    }

    // ---- Translation readiness of a national calendar's locales (#994) -----------------
    //
    // A national calendar can only be created in a language whose General Roman Calendar
    // and Decrees are translated, i.e. an officially supported language; otherwise it is
    // served with untranslated event names. Existing calendars keep the locales they
    // already declare: only creation and locales newly added by a PATCH are checked.

    public function testPutNationalCalendarRejectsALocaleThatIsNotOfficial(): void
    {
        $this->requireMtNationAbsent();

        $payload                        = self::mtNationalCalendarPayload();
        $payload['metadata']['locales'] = ['en_MT', 'mt_MT'];
        $payload['i18n']['mt_MT']       = ['StGeorgeMartyr' => 'San Ġorġ, Martri, Patrun ta\' Malta'];

        try {
            ( new RegionalDataHandler(['nation', 'MT']) )
                ->handle($this->requestFor('PUT', '/data/nation/MT', [], $payload));
            self::fail('A PUT declaring a locale that is not officially supported must be rejected.');
        } catch (UnprocessableContentException $e) {
            self::assertStringContainsString('mt_MT', $e->getMessage());
            self::assertStringNotContainsString('en_MT', $e->getMessage(), 'Only the failing locale is named.');
        }

        self::assertFileDoesNotExist(self::mtCalendarFile(Router::$apiFilePath), 'Nothing is written for a rejected PUT.');
    }

    /**
     * The issue's own case: the frontend used to pre-select every ICU locale of France, so a PUT
     * declared Breton, Catalan, Swiss German and Occitan alongside French. Every failing locale is
     * named, in one response, so the curator does not have to find them one retry at a time.
     * (`oc_FR` is left out: the schema's Locale enum predates it, so it never reaches the check.)
     */
    public function testPutNationalCalendarNamesEveryLocaleThatIsNotOfficial(): void
    {
        $franceFile = Router::$apiFilePath . strtr(JsonData::NATIONAL_CALENDAR_FILE->value, ['{nation}' => 'FR']);
        if (file_exists($franceFile)) {
            $this->markTestSkipped('An FR national calendar already exists; this test needs a nation without one.');
        }

        $locales                        = ['br_FR', 'ca_FR', 'fr_FR', 'gsw_FR'];
        $payload                        = self::mtNationalCalendarPayload();
        $payload['metadata']['nation']  = 'FR';
        $payload['metadata']['locales'] = $locales;
        $payload['i18n']                = array_fill_keys($locales, ['StGeorgeMartyr' => 'Saint Georges, martyr']);

        try {
            ( new RegionalDataHandler(['nation', 'FR']) )
                ->handle($this->requestFor('PUT', '/data/nation/FR', [], $payload));
            self::fail('A PUT declaring locales that are not officially supported must be rejected.');
        } catch (UnprocessableContentException $e) {
            foreach (['br_FR', 'ca_FR', 'gsw_FR'] as $failing) {
                self::assertStringContainsString($failing, $e->getMessage());
            }
            self::assertStringNotContainsString('fr_FR', $e->getMessage(), 'French is official and is not named.');
        }

        self::assertFileDoesNotExist($franceFile, 'Nothing is written for a rejected PUT.');
    }

    /**
     * A PATCH body for a shipped national calendar: its source file plus the i18n of every
     * locale it declares, read from the shadow root.
     *
     * @return array<string,mixed>
     */
    private static function shippedNationalCalendarPayload(string $nation): array
    {
        $calendarFile = Router::$apiFilePath . strtr(JsonData::NATIONAL_CALENDAR_FILE->value, ['{nation}' => $nation]);
        $calendar     = json_decode((string) file_get_contents($calendarFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($calendar);
        self::assertIsArray($calendar['metadata']);
        self::assertIsArray($calendar['metadata']['locales']);

        $calendar['i18n'] = [];
        foreach ($calendar['metadata']['locales'] as $locale) {
            self::assertIsString($locale);
            $i18nFile                  = Router::$apiFilePath . strtr(JsonData::NATIONAL_CALENDAR_I18N_FILE->value, ['{nation}' => $nation, '{locale}' => $locale]);
            $calendar['i18n'][$locale] = json_decode((string) file_get_contents($i18nFile), true, 512, JSON_THROW_ON_ERROR);
        }
        return $calendar;
    }

    /** Croatian is not (yet) official, but HR already declared it: an update must not lose the calendar. */
    public function testPatchNationalCalendarKeepsALocaleItAlreadyDeclares(): void
    {
        $payload = self::shippedNationalCalendarPayload('HR');
        self::assertSame(['hr_HR'], $payload['metadata']['locales'], 'Fixture precondition: HR declares hr_HR alone.');
        self::assertFalse(SupportedLocales::isOfficial('hr_HR'), 'Fixture precondition: Croatian is not official.');

        $response = ( new RegionalDataHandler(['nation', 'HR']) )
            ->handle($this->requestFor('PATCH', '/data/nation/HR', ['Accept-Language' => 'hr-HR'], $payload));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testPatchNationalCalendarRejectsALocaleItAddsThatIsNotOfficial(): void
    {
        $payload = self::shippedNationalCalendarPayload('NL');
        self::assertSame(['nl_NL'], $payload['metadata']['locales'], 'Fixture precondition: NL declares nl_NL alone.');
        $payload['metadata']['locales'][] = 'fy_NL';
        $payload['i18n']['fy_NL']         = $payload['i18n']['nl_NL'];

        try {
            ( new RegionalDataHandler(['nation', 'NL']) )
                ->handle($this->requestFor('PATCH', '/data/nation/NL', ['Accept-Language' => 'nl-NL'], $payload));
            self::fail('A PATCH adding a locale that is not officially supported must be rejected.');
        } catch (UnprocessableContentException $e) {
            self::assertStringContainsString('fy_NL', $e->getMessage());
            self::assertStringNotContainsString('nl_NL', $e->getMessage(), 'Only the failing locale is named.');
        }
    }

    /**
     * Sent in the very locale it adds, the PATCH must still be answered with the readiness 422,
     * not with a complaint that the request locale is not one the calendar declares.
     */
    public function testPatchNationalCalendarInTheLocaleItAddsReportsReadiness(): void
    {
        $payload                          = self::shippedNationalCalendarPayload('NL');
        $payload['metadata']['locales'][] = 'fy_NL';
        $payload['i18n']['fy_NL']         = $payload['i18n']['nl_NL'];

        try {
            ( new RegionalDataHandler(['nation', 'NL']) )
                ->handle($this->requestFor('PATCH', '/data/nation/NL', ['Accept-Language' => 'fy-NL'], $payload));
            self::fail('A PATCH adding a locale that is not officially supported must be rejected.');
        } catch (UnprocessableContentException $e) {
            self::assertStringContainsString('not officially supported', $e->getMessage());
            self::assertStringContainsString('fy_NL (fy):', $e->getMessage());
        }
    }

    /** An official locale the PATCH adds is a locale of the calendar, so the PATCH may be sent in it. */
    public function testPatchNationalCalendarMayBeSentInAnOfficialLocaleItAdds(): void
    {
        $payload                          = self::shippedNationalCalendarPayload('NL');
        $payload['metadata']['locales'][] = 'en_NL';
        $payload['i18n']['en_NL']         = $payload['i18n']['nl_NL'];

        $response = ( new RegionalDataHandler(['nation', 'NL']) )
            ->handle($this->requestFor('PATCH', '/data/nation/NL', ['Accept-Language' => 'en-NL'], $payload));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testPatchNationalCalendarAcceptsAnOfficialLocaleItAdds(): void
    {
        $payload                          = self::shippedNationalCalendarPayload('NL');
        $payload['metadata']['locales'][] = 'en_NL';
        $payload['i18n']['en_NL']         = $payload['i18n']['nl_NL'];

        $response = ( new RegionalDataHandler(['nation', 'NL']) )
            ->handle($this->requestFor('PATCH', '/data/nation/NL', ['Accept-Language' => 'nl-NL'], $payload));

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * And the third `fromObject()` call. Wider-region had no write-path coverage of this
     * check at all before, which is what left the branch unexercised.
     */
    public function testPutWiderRegionCalendarRejectsI18nNarrowerThanDeclaredLocales(): void
    {
        $payload = self::europeWiderRegionPayload();
        // Two locales declared, `i18n` carries it_IT alone.
        $payload['metadata']['locales'] = ['it_IT', 'fr_FR'];

        $this->expectException(UnprocessableContentException::class);
        $this->expectExceptionMessage('keys of i18n parameter must be the same as the values of metadata.locales');

        ( new RegionalDataHandler(['widerregion', 'europe']) )
            ->handle($this->requestFor('PUT', '/data/widerregion/europe', [], $payload));
    }

    public function testALegacyRegionNameIsStoredAsItsIdOnARegionPut(): void
    {
        $payload                             = self::europeWiderRegionPayload();
        $payload['metadata']['wider_region'] = 'Africa';

        $response = ( new RegionalDataHandler(['widerregion', 'Africa']) )
            ->handle($this->requestFor('PUT', '/data/widerregion/Africa', [], $payload));

        self::assertSame(201, $response->getStatusCode());
        $file = strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => 'africa']);
        self::assertFileExists($file);
        $stored = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('africa', $stored['metadata']['wider_region']);
    }

    /**
     * The Router rewrites a legacy path key to the id before the handler is built, and the payload's legacy name is
     * stored as the id: `Middle East` (a new region; `europe` exists, so a PUT on it would be a 409) becomes
     * `middle-east/middle-east.json` with `"wider_region": "middle-east"`.
     */
    public function testALegacyRegionPathAndPayloadAreStoredAsTheIdOnARegionPutThroughTheRouter(): void
    {
        $parts = ['widerregion', 'Middle East'];
        self::assertTrue(Router::canonicaliseWiderRegionKey($parts));
        self::assertSame(['widerregion', 'middle-east'], $parts);

        $payload                             = self::europeWiderRegionPayload();
        $payload['metadata']['wider_region'] = 'Middle East';

        $response = ( new RegionalDataHandler($parts) )
            ->handle($this->requestFor('PUT', '/data/widerregion/middle-east', [], $payload));

        self::assertSame(201, $response->getStatusCode());
        $stored = self::storedRegion('middle-east');
        self::assertSame('middle-east', $stored['metadata']['wider_region']);
    }

    public function testALegacyRegionPathAndPayloadAreStoredAsTheIdOnARegionPatch(): void
    {
        $parts = ['widerregion', 'Europe'];
        self::assertTrue(Router::canonicaliseWiderRegionKey($parts));

        $payload                             = self::shippedEuropePayload();
        $payload['metadata']['wider_region'] = 'Europe';

        $response = ( new RegionalDataHandler($parts) )
            ->handle($this->requestFor('PATCH', '/data/widerregion/europe', ['Accept-Language' => 'it-IT'], $payload));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('europe', self::storedRegion('europe')['metadata']['wider_region']);
        self::assertDirectoryDoesNotExist(dirname(strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => 'Europe'])));
    }

    /**
     * The current Frontend rebuilds a region's metadata as `{locales, wider_region}`: a save from it must not wipe the
     * labels, so a PATCH that sends none keeps the stored ones.
     */
    public function testARegionPatchWithoutLabelsKeepsTheStoredLabels(): void
    {
        $before  = self::storedRegion('europe')['metadata']['labels'];
        $payload = self::shippedEuropePayload();
        unset($payload['metadata']['labels']);

        $response = ( new RegionalDataHandler(['widerregion', 'europe']) )
            ->handle($this->requestFor('PATCH', '/data/widerregion/europe', ['Accept-Language' => 'it-IT'], $payload));

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($before);
        self::assertNotEmpty($before);
        self::assertSame($before, self::storedRegion('europe')['metadata']['labels']);
    }

    /** A carried-over label whose language the PATCH no longer declares is dropped, so the stored file stays valid. */
    public function testARegionPatchWithoutLabelsDropsTheLabelsOfLanguagesItNoLongerDeclares(): void
    {
        $before  = self::storedRegion('europe')['metadata']['labels'];
        $payload = self::shippedEuropePayload();
        unset($payload['metadata']['labels']);
        self::assertIsArray($payload['metadata']['locales']);
        self::assertIsArray($payload['i18n']);
        $payload['metadata']['locales'] = array_values(array_filter($payload['metadata']['locales'], static fn ($l): bool => !str_starts_with((string) $l, 'hu_')));
        unset($payload['i18n']['hu_HU']);

        $response = ( new RegionalDataHandler(['widerregion', 'europe']) )
            ->handle($this->requestFor('PATCH', '/data/widerregion/europe', ['Accept-Language' => 'it-IT'], $payload));

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($before);
        unset($before['hu']);
        self::assertSame($before, self::storedRegion('europe')['metadata']['labels']);
    }

    public function testARegionPatchWithLabelsReplacesThem(): void
    {
        $payload                       = self::shippedEuropePayload();
        $payload['metadata']['labels'] = ['en' => 'Europe'];

        $response = ( new RegionalDataHandler(['widerregion', 'europe']) )
            ->handle($this->requestFor('PATCH', '/data/widerregion/europe', ['Accept-Language' => 'it-IT'], $payload));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['en' => 'Europe'], self::storedRegion('europe')['metadata']['labels']);
    }

    public function testARegionPutWithAnUndeclaredLabelLanguageIsRefusedAndWritesNothing(): void
    {
        $payload                             = self::europeWiderRegionPayload();
        $payload['metadata']['wider_region'] = 'africa';
        $payload['metadata']['labels']       = ['en' => 'Africa', 'sw' => 'Afrika'];

        try {
            ( new RegionalDataHandler(['widerregion', 'africa']) )
                ->handle($this->requestFor('PUT', '/data/widerregion/africa', [], $payload));
            self::fail('A label in a language the region does not declare must be refused.');
        } catch (UnprocessableContentException $e) {
            self::assertSame(422, $e->getCode());
            self::assertStringContainsString('`sw`', $e->getMessage());
        }
        self::assertDirectoryDoesNotExist(dirname(strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => 'africa'])));
    }

    public function testARegionPatchWithAnUndeclaredLabelLanguageIsRefusedAndWritesNothing(): void
    {
        $file                          = strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => 'europe']);
        $before                        = file_get_contents($file);
        $payload                       = self::shippedEuropePayload();
        $payload['metadata']['labels'] = ['en' => 'Europe', 'sw' => 'Ulaya'];

        try {
            ( new RegionalDataHandler(['widerregion', 'europe']) )
                ->handle($this->requestFor('PATCH', '/data/widerregion/europe', ['Accept-Language' => 'it-IT'], $payload));
            self::fail('A label in a language the region does not declare must be refused.');
        } catch (UnprocessableContentException $e) {
            self::assertStringContainsString('`sw`', $e->getMessage());
        }
        self::assertSame($before, file_get_contents($file));
    }

    /** @return array<string,mixed> the stored region file, decoded */
    private static function storedRegion(string $id): array
    {
        $file = strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => $id]);
        self::assertFileExists($file);
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['metadata']);
        return $data;
    }

    /**
     * A PATCH body for the shipped Europe region: its source file plus the i18n of every locale it declares.
     *
     * @return array<string,mixed>
     */
    private static function shippedEuropePayload(): array
    {
        $region = self::storedRegion('europe');
        self::assertIsArray($region['metadata']['locales']);
        $region['i18n'] = [];
        foreach ($region['metadata']['locales'] as $locale) {
            self::assertIsString($locale);
            // A declared locale may have no file yet (hu_HU); any locale's names satisfy the PATCH's i18n check.
            $i18nFile = strtr(JsonData::WIDER_REGION_I18N_FILE->path(), ['{wider_region}' => 'europe', '{locale}' => $locale]);
            if (!is_file($i18nFile)) {
                $i18nFile = strtr(JsonData::WIDER_REGION_I18N_FILE->path(), ['{wider_region}' => 'europe', '{locale}' => 'it_IT']);
            }
            $region['i18n'][$locale] = json_decode((string) file_get_contents($i18nFile), true, 512, JSON_THROW_ON_ERROR);
        }
        return $region;
    }

    // ---- One locale's translations of a wider region ----------------------------------
    //
    // PUT /data/widerregion/{region}/{locale} is how a national calendar editor maintains
    // their own nation's translations of a wider region (the authorization is the
    // middleware's; see OpenFgaAuthorizationMiddlewareTest). These pin what it writes.

    /** @return array<string,mixed> the Americas region file, as the handler will read it */
    private static function americasData(): array
    {
        $file = strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => 'americas']);
        /** @var array<string,mixed> $data */
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        return $data;
    }

    /** @return array<string,string> every Americas event key, named in `$language` */
    private static function americasNames(string $language): array
    {
        $names = [];
        /** @var list<array{liturgical_event: array{event_key: string}}> $litcal */
        $litcal = self::americasData()['litcal'];
        foreach ($litcal as $row) {
            $names[$row['liturgical_event']['event_key']] = "{$language} {$row['liturgical_event']['event_key']}";
        }
        return $names;
    }

    private static function americasI18nFile(string $locale): string
    {
        return strtr(JsonData::WIDER_REGION_I18N_FILE->path(), ['{wider_region}' => 'americas', '{locale}' => $locale]);
    }

    public function testPutWiderRegionLocaleAddsANewNationsLocale(): void
    {
        self::assertFileDoesNotExist(self::americasI18nFile('es_VE'));
        $names = self::americasNames('es');

        $response = ( new RegionalDataHandler(['widerregion', 'americas', 'es_VE']) )
            ->handle($this->requestFor('PUT', '/data/widerregion/americas/es_VE', [], $names));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame($names, json_decode((string) file_get_contents(self::americasI18nFile('es_VE')), true));
        $data = self::americasData();
        /** @var array{locales: list<string>} $metadata */
        $metadata = $data['metadata'];
        self::assertContains('es_VE', $metadata['locales']);
        self::assertSame('VE', $data['national_calendars']['Venezuela'] ?? null);
    }

    public function testPutWiderRegionLocaleUpdatesAnExistingLocaleWithoutTouchingTheRegion(): void
    {
        $regionFile = strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => 'americas']);
        $before     = file_get_contents($regionFile);
        $names      = self::americasNames('fr');

        $response = ( new RegionalDataHandler(['widerregion', 'americas', 'fr_CA']) )
            ->handle($this->requestFor('PUT', '/data/widerregion/americas/fr_CA', [], $names));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($names, json_decode((string) file_get_contents(self::americasI18nFile('fr_CA')), true));
        self::assertSame($before, file_get_contents($regionFile));
    }

    public function testPutWiderRegionLocaleRejectsTranslationsThatDoNotNameEveryEvent(): void
    {
        // Americas has a single event, so an unknown key stands in for "not exactly the
        // region's events" here; a missing key trips the same check.
        $names = self::americasNames('es') + ['NoSuchEvent' => 'Nessuno'];

        $this->expectException(UnprocessableContentException::class);
        $this->expectExceptionMessage('must name every event of the wider region americas');
        ( new RegionalDataHandler(['widerregion', 'americas', 'es_UY']) )
            ->handle($this->requestFor('PUT', '/data/widerregion/americas/es_UY', [], $names));
    }

    public function testPutWiderRegionLocaleRejectsALocaleThatNamesNoNation(): void
    {
        // es_419 is a valid locale, but "Latin America" is not a nation: there is no
        // national calendar whose editors could own it.
        $this->expectException(ValidationException::class);
        ( new RegionalDataHandler(['widerregion', 'americas', 'es_419']) )
            ->handle($this->requestFor('PUT', '/data/widerregion/americas/es_419', [], self::americasNames('es')));
    }

    public function testPutWiderRegionLocaleIsRomanOnly(): void
    {
        // Every other wider region request is held to the Roman rite by RegionalDataParams,
        // which this path returns before building.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('has no wider regions');
        ( new RegionalDataHandler(['widerregion', 'americas', 'es_VE'], Rite::AMBROSIAN) )
            ->handle($this->requestFor('PUT', '/data/ambrosian/widerregion/americas/es_VE', [], self::americasNames('es')));
    }

    public function testPatchWiderRegionCreatesTheFileOfALocaleItAdds(): void
    {
        // The whole-region save used to refuse any locale that had no file yet with a
        // 404, so a language could never be added to an existing wider region.
        self::assertFileDoesNotExist(self::americasI18nFile('es_UY'));
        $payload = self::americasData();
        /** @var array{locales: list<string>, wider_region: string} $metadata */
        $metadata              = $payload['metadata'];
        $metadata['locales'][] = 'es_UY';
        $payload['metadata']   = $metadata;
        $payload['i18n']       = [];
        foreach ($metadata['locales'] as $locale) {
            $payload['i18n'][$locale] = $locale === 'es_UY'
                ? self::americasNames('es')
                : json_decode((string) file_get_contents(self::americasI18nFile($locale)), true);
        }

        $response = ( new RegionalDataHandler(['widerregion', 'americas']) )
            ->handle($this->requestFor('PATCH', '/data/widerregion/americas', ['Accept-Language' => 'en-US'], $payload));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::americasNames('es'), json_decode((string) file_get_contents(self::americasI18nFile('es_UY')), true));
    }

    /**
     * A schema-valid wider-region PUT/PATCH payload for Europe.
     *
     * Modelled on the shipped `wider_regions/europe/europe.json`: `national_calendars` is a
     * name => ISO code map and is required by the schema, and `makePatron` is one of the two
     * actions a wider region admits. The i18n section is required by the PUT/PATCH handlers.
     *
     * @return array<string,mixed>
     */
    private static function europeWiderRegionPayload(): array
    {
        return [
            'litcal'             => [
                [
                    'liturgical_event' => ['event_key' => 'StBenedict', 'grade' => 4],
                    'metadata'         => [
                        'action'       => 'makePatron',
                        'since_year'   => 1964,
                        'url'          => 'https://www.vatican.va/',
                        // Required alongside `url` for makePatron, per MetadataMakePatron.
                        'url_lang_map' => ['it' => 'it', 'la' => 'la'],
                    ],
                ],
            ],
            'national_calendars' => ['Italy' => 'IT', 'France' => 'FR'],
            'metadata'           => [
                'wider_region' => 'europe',
                'locales'      => ['it_IT'],
            ],
            'i18n'               => [
                'it_IT' => ['StBenedict' => 'San Benedetto'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function diocesanPayloadWithColor(string $rite, string $color, string $dioceseId, string $dioceseName): array
    {
        return [
            'litcal'   => [
                [
                    'liturgical_event' => [
                        'event_key' => 'StsProtaseGervase',
                        'color'     => [$color],
                        'grade'     => 3,
                        'common'    => ['Proper'],
                        'day'       => 19,
                        'month'     => 6,
                    ],
                    'metadata'         => ['since_year' => 2024, 'form_rownum' => 0],
                ],
            ],
            'metadata' => [
                // `diocese_name` is gated by an enum of real diocese names in CommonDef.json,
                // so the fixture has to name an actual diocese for the payload to be
                // schema-valid — otherwise it fails before ever reaching the color gate.
                'nation'       => 'IT',
                'diocese_id'   => $dioceseId,
                'diocese_name' => $dioceseName,
                'locales'      => ['it_IT'],
                'timezone'     => 'Europe/Rome',
                'rite'         => $rite,
            ],
            // PUT/PATCH require i18n covering every declared locale.
            'i18n'     => [
                'it_IT' => ['StsProtaseGervase' => 'Santi Protaso e Gervaso'],
            ],
        ];
    }
}
