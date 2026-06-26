<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\RegionalDataHandler;
use LiturgicalCalendar\Api\Http\Exception\UnprocessableContentException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Repositories\OutboxBatchInsertInterface;
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
        $this->restoreItFixture();
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
    private function restoreItFixture(): void
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
        // PUT requires exactly 1 path param (the category). Passing the
        // request without a body trips the empty-payload check in
        // parseBodyPayload → ValidationException.
        $this->expectException(ValidationException::class);
        ( new RegionalDataHandler(['nation']) )
            ->handle($this->requestFor('PUT', '/data/nation', ['Content-Type' => 'application/json'], ''));
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
        $base              = Router::$apiFilePath . 'jsondata/sourcedata/calendars/nations/HR';
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
            ->with('national_calendar:HR');
        $handler->setPurgeService($purge);

        // --- Act: issue DELETE (bypasses JWT middleware — in-process) --------
        $request  = $this->requestFor('DELETE', '/data/nation/HR');
        $response = $handler->handle($request);

        // --- Assert ----------------------------------------------------------
        self::assertSame(200, $response->getStatusCode());
        // purgeForObject assertion is enforced by the mock expectation above
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
     * tearDown via {@see restoreItFixture()} to keep the working tree clean.
     */
    public function testCreateNationalCalendarEnqueuesMemberNationTuple(): void
    {
        // --- Arrange: record paths so tearDown can delete the new files -------
        $base              = Router::$apiFilePath . 'jsondata/sourcedata/calendars/nations/MT';
        $this->mtNationDir = $base;
        $this->mtJsonPath  = $base . '/MT.json';
        $this->mtI18nDir   = $base . '/i18n';
        $this->mtI18nPath  = $base . '/i18n/en_MT.json';

        // Defensive guard: if MT already exists (it shouldn't), skip.
        if (file_exists($this->mtJsonPath)) {
            $this->markTestSkipped(
                'MT national-calendar file already exists; skipping enqueue test to avoid overwriting it.'
            );
        }

        // --- Build handler with injected mock OutboxRepository ----------------
        // PUT requests expect exactly ONE path param (the category); the nation
        // key is derived from the payload body, not the URL.
        $handler = new RegionalDataHandler(['nation']);

        $repo = $this->createMock(OutboxBatchInsertInterface::class);
        $repo->expects($this->atLeastOnce())
            ->method('insertBatch')
            ->with($this->callback(function (array $rows): bool {
                foreach ($rows as $r) {
                    if (
                        $r['fga_user'] === 'national_calendar:MT'
                        && $r['fga_relation'] === 'member_nation'
                        && $r['fga_object'] === 'wider_region:Europe'
                    ) {
                        return true;
                    }
                }
                return false;
            }))
            ->willReturn([99]);
        $handler->setOutboxRepository($repo);

        // Build a valid PUT payload for Malta (MT) with wider_region=Europe.
        // The i18n section is required by the PUT handler.
        $payload = [
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

        // --- Act: issue PUT (bypasses JWT middleware — in-process) -----------
        $request  = $this->requestFor('PUT', '/data/nation', [], $payload);
        $response = $handler->handle($request);

        // --- Assert ----------------------------------------------------------
        self::assertContains($response->getStatusCode(), [200, 201]);
        // insertBatch assertion is enforced by the mock expectation above
    }
}
