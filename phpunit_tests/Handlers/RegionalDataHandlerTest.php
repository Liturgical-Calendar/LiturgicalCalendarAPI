<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\RegionalDataHandler;
use LiturgicalCalendar\Api\Http\Exception\UnprocessableContentException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeService;
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

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreHrFixture();
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

        $purge = $this->createMock(ResourceTuplePurgeService::class);
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
}
