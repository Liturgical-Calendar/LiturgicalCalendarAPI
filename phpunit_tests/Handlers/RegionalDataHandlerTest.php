<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\RegionalDataHandler;
use LiturgicalCalendar\Api\Http\Exception\UnprocessableContentException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Repositories\OutboxBatchInsertInterface;
use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * RegionalDataHandler serves and edits per-nation / per-diocese / per-wider-
 * region calendar source data. The PUT/PATCH/DELETE branches are gated by
 * JWT auth middleware (added by the router before handler invocation) and
 * involve disk writes; this suite covers the read paths and the path /
 * category validators that run before any side effects.
 */
#[CoversClass(RegionalDataHandler::class)]
final class RegionalDataHandlerTest extends AbstractHandlerTestCase
{
    // The handler resolves national/diocesan keys against the calendars metadata
    // index, which RegionalDataHandler now builds in-process from local source
    // data (CalendarMetadataProvider). AbstractHandlerTestCase already pins
    // Router::$apiFilePath to the project root, so those lookups resolve against
    // the bundled sourcedata with no HTTP server or fixture needed.

    // -------------------------------------------------------------------------
    // Filesystem backup state for testDeleteCalendarPurgesOperationalTuples.
    // Croatia (HR) has no diocesan calendars in the bundled source data, so the
    // DELETE validation passes without a "diocesan calendars depend on this"
    // error.  We save the file contents before the test runs and restore them
    // in tearDown — even when the test fails or is skipped — to keep the
    // working tree clean.
    // -------------------------------------------------------------------------

    /** Contents of jsondata/…/nations/HR/HR.json saved before deletion. */
    private ?string $hrJsonContent = null;

    /** Contents of jsondata/…/nations/HR/i18n/hr_HR.json saved before deletion. */
    private ?string $hrI18nContent = null;

    /** Absolute path to HR.json (resolved once in the test, used in tearDown). */
    private string $hrJsonPath = '';

    /** Absolute path to the i18n directory for HR (used in tearDown). */
    private string $hrI18nDir = '';

    /** Absolute path to the HR i18n locale file (used in tearDown). */
    private string $hrI18nPath = '';

    /** Absolute path to the HR nation directory (used in tearDown). */
    private string $hrNationDir = '';

    // -------------------------------------------------------------------------
    // Filesystem cleanup state for testCreateNationalCalendarEnqueuesMemberNationTuple.
    // Malta (MT) is used as the fixture nation because it has no existing national
    // calendar in the bundled source data (so PUT does not conflict) and it is a
    // valid European nation code.  The newly created MT files are deleted in
    // tearDown regardless of test outcome to keep the working tree clean.
    // -------------------------------------------------------------------------

    /** Absolute path to the MT nation directory created by the create test. */
    private string $mtNationDir = '';

    /** Absolute path to MT.json created by the create test. */
    private string $mtJsonPath = '';

    /** Absolute path to the MT i18n directory created by the create test. */
    private string $mtI18nDir = '';

    /** Absolute path to the MT i18n locale file created by the create test. */
    private string $mtI18nPath = '';

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreHrFixture();
        $this->cleanupMtFixture();
    }

    /**
     * Recreates the HR national-calendar fixture files deleted by the delete
     * test.  Called unconditionally from tearDown so the tree is always clean,
     * regardless of whether the test passed, failed, or was skipped.
     */
    private function restoreHrFixture(): void
    {
        if ($this->hrJsonContent === null) {
            return; // no backup was taken — nothing to restore
        }
        if (!is_dir($this->hrNationDir)) {
            mkdir($this->hrNationDir, 0755, true);
        }
        if (!is_dir($this->hrI18nDir)) {
            mkdir($this->hrI18nDir, 0755, true);
        }
        if (!file_exists($this->hrJsonPath)) {
            file_put_contents($this->hrJsonPath, $this->hrJsonContent);
        }
        if (!file_exists($this->hrI18nPath)) {
            file_put_contents($this->hrI18nPath, $this->hrI18nContent);
        }
        $this->hrJsonContent = null;
        $this->hrI18nContent = null;
    }

    /**
     * Deletes the MT national-calendar files created by the create test.
     * Called unconditionally from tearDown so the tree is always clean,
     * regardless of whether the test passed, failed, or was skipped.
     */
    private function cleanupMtFixture(): void
    {
        if ($this->mtNationDir === '') {
            return; // create test did not run — nothing to clean up
        }
        if ($this->mtI18nPath !== '' && file_exists($this->mtI18nPath)) {
            unlink($this->mtI18nPath);
        }
        if ($this->mtJsonPath !== '' && file_exists($this->mtJsonPath)) {
            unlink($this->mtJsonPath);
        }
        if ($this->mtI18nDir !== '' && is_dir($this->mtI18nDir)) {
            rmdir($this->mtI18nDir);
        }
        if ($this->mtNationDir !== '' && is_dir($this->mtNationDir)) {
            rmdir($this->mtNationDir);
        }
        $this->mtNationDir = '';
        $this->mtJsonPath  = '';
        $this->mtI18nDir   = '';
        $this->mtI18nPath  = '';
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
                'wider_region' => 'Europe',
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
        // Same HR fixture approach as testDeleteCalendarPurgesOperationalTuples;
        // tearDown restores the files from these saved contents.
        $base              = Router::$apiFilePath . 'jsondata/sourcedata/rite/roman/calendars/nations/HR';
        $this->hrNationDir = $base;
        $this->hrJsonPath  = $base . '/HR.json';
        $this->hrI18nDir   = $base . '/i18n';
        $this->hrI18nPath  = $base . '/i18n/hr_HR.json';

        $hrJsonContent = file_get_contents($this->hrJsonPath);
        $hrI18nContent = file_get_contents($this->hrI18nPath);
        if ($hrJsonContent === false || $hrI18nContent === false) {
            $this->markTestSkipped('HR national-calendar fixture files not found; skipping purge-failure test.');
        }
        $this->hrJsonContent = $hrJsonContent;
        $this->hrI18nContent = $hrI18nContent;

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
     * The HR files are backed up before the test and restored in tearDown.
     */
    public function testDeleteCalendarPurgesOperationalTuples(): void
    {
        // --- Arrange: save fixture files so tearDown can restore them --------
        $base              = Router::$apiFilePath . 'jsondata/sourcedata/rite/roman/calendars/nations/HR';
        $this->hrNationDir = $base;
        $this->hrJsonPath  = $base . '/HR.json';
        $this->hrI18nDir   = $base . '/i18n';
        $this->hrI18nPath  = $base . '/i18n/hr_HR.json';

        $hrJsonContent = file_get_contents($this->hrJsonPath);
        $hrI18nContent = file_get_contents($this->hrI18nPath);

        if ($hrJsonContent === false || $hrI18nContent === false) {
            $this->markTestSkipped(
                'HR national-calendar fixture files not found; skipping delete/purge test.'
            );
        }

        $this->hrJsonContent = $hrJsonContent;
        $this->hrI18nContent = $hrI18nContent;

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
     * Registers the MT fixture paths for {@see cleanupMtFixture()} and skips
     * the calling test if an MT national calendar unexpectedly already exists.
     */
    private function armMtFixture(): void
    {
        $base              = Router::$apiFilePath . 'jsondata/sourcedata/rite/roman/calendars/nations/MT';
        $this->mtNationDir = $base;
        $this->mtJsonPath  = $base . '/MT.json';
        $this->mtI18nDir   = $base . '/i18n';
        $this->mtI18nPath  = $base . '/i18n/en_MT.json';

        // Defensive guard: if MT already exists (it shouldn't), skip.
        if (file_exists($this->mtJsonPath)) {
            $this->markTestSkipped(
                'MT national-calendar file already exists; skipping to avoid overwriting it.'
            );
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
                'wider_region' => 'Europe',
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
     * then updates it via PATCH. MT files are cleaned up in tearDown.
     */
    public function testUpdateNationalCalendarSucceeds(): void
    {
        $this->armMtFixture();

        $payload = self::mtNationalCalendarPayload();

        $createResponse = ( new RegionalDataHandler(['nation', 'MT']) )
            ->handle($this->requestFor('PUT', '/data/nation/MT', [], $payload));
        self::assertSame(201, $createResponse->getStatusCode());

        // PATCH must send a locale the MT calendar supports (defaults to Latin otherwise).
        $updateResponse = ( new RegionalDataHandler(['nation', 'MT']) )
            ->handle($this->requestFor('PATCH', '/data/nation/MT', ['Accept-Language' => 'en-MT'], $payload));

        self::assertSame(201, $updateResponse->getStatusCode());
        $body = $this->decodeJsonBody($updateResponse);
        self::assertSame('Calendar data updated for Nation "Malta" ("MT")', $body['success']);
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
     * The new MT files written by the handler are deleted unconditionally in
     * tearDown via {@see cleanupMtFixture()} to keep the working tree clean.
     */
    public function testCreateNationalCalendarEnqueuesMemberNationTuple(): void
    {
        // --- Arrange: record paths so tearDown can delete the new files -------
        $this->armMtFixture();

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
                        && $r['fga_object'] === 'wider_region:roman/Europe'
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
