<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * CalendarHandler is the heart of the API — it computes the full liturgical
 * calendar for a year. This suite exercises the request-shape gates and a
 * handful of happy-path year computations. Deep coverage of edge cases
 * (suppression, transfer rules, Easter cycle anomalies) lives in the
 * external UnitTestInterface integration suite by design.
 */
#[CoversClass(CalendarHandler::class)]
final class CalendarHandlerTest extends AbstractHandlerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // CalendarHandler's CalendarParams fetches /calendars metadata from
        // Router::$apiPath; route it at the M1 fixture so the constructor
        // succeeds without an HTTP server.
        $fixturePath = realpath(__DIR__ . '/../fixtures/api');
        self::assertNotFalse($fixturePath, 'M1 calendars fixture must be present');
        Router::$apiPath = 'file://' . $fixturePath;
    }

    /**
     * In production, Router::buildHandler() calls setAllowedReturnTypes()
     * before invoking the handler; without it CalendarParams rejects the
     * default-negotiated 'JSON' return type. Centralise the setup here.
     */
    private function makeHandler(array $pathParams = []): CalendarHandler
    {
        $handler = new CalendarHandler($pathParams);
        $handler->setAllowedReturnTypes([
            ReturnTypeParam::JSON,
            ReturnTypeParam::YAML,
            ReturnTypeParam::XML,
            ReturnTypeParam::ICS,
        ]);
        return $handler;
    }

    public function testOptionsPreflightSucceeds(): void
    {
        $response = $this->makeHandler()->handle(
            $this->requestFor('OPTIONS', '/calendar', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testGetForCurrentYearReturnsCalendarShape(): void
    {
        $response = $this->makeHandler()->handle(
            $this->requestFor('GET', '/calendar', ['Accept-Language' => 'la'])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);

        // CalendarHandler produces a fixed top-level shape.
        self::assertArrayHasKey('settings', $body);
        self::assertArrayHasKey('metadata', $body);
        self::assertArrayHasKey('litcal', $body);
        self::assertNotEmpty($body['litcal'], 'A computed calendar must contain liturgical events');
    }

    public function testGetForExplicitYearAppliesIt(): void
    {
        $response = $this->makeHandler(['2025'])->handle(
            $this->requestFor('GET', '/calendar/2025', ['Accept-Language' => 'la'])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertSame(2025, $body['settings']['year']);
    }

    public function testYearOutsideRangeIsValidationError(): void
    {
        // YEAR_LOWER_LIMIT is 1970; 1900 must be rejected.
        $this->expectException(ValidationException::class);
        $this->makeHandler(['1900'])
            ->handle($this->requestFor('GET', '/calendar/1900', ['Accept-Language' => 'la']));
    }

    public function testNonNumericYearPathIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        $this->makeHandler(['twenty-twenty-five'])
            ->handle($this->requestFor('GET', '/calendar/twenty-twenty-five', ['Accept-Language' => 'la']));
    }

    public function testInvalidNationalCalendarPathIsValidationError(): void
    {
        // 'nation' segment + unknown nation key triggers CalendarParams's
        // validation against the M1 fixture's national_calendars_keys.
        $this->expectException(ValidationException::class);
        $this->makeHandler(['nation', 'ZZ', '2025'])
            ->handle($this->requestFor('GET', '/calendar/nation/ZZ/2025', ['Accept-Language' => 'la']));
    }

    /**
     * On a successful GitHub release lookup, the release object is passed
     * through unchanged to supply the ICS CREATED timestamp.
     */
    public function testResolveIcalReleaseObjectReturnsReleaseOnSuccess(): void
    {
        $release = (object) ['published_at' => '2024-01-01T00:00:00Z', 'tag_name' => 'v9.9.9'];
        $infoObj = (object) ['status' => 'success', 'obj' => $release];

        $result = ( new \ReflectionMethod(CalendarHandler::class, 'resolveIcalReleaseObject') )
            ->invoke($this->makeHandler(), $infoObj);

        self::assertSame($release, $result, 'A successful lookup must return the GitHub release object unchanged');
    }

    /**
     * When the GitHub release lookup fails (e.g. api.github.com rate-limits the
     * server), ICS generation must not 503: it falls back to the current UTC
     * time so produceIcal can still emit a valid CREATED line.
     */
    public function testResolveIcalReleaseObjectFallsBackToUtcNowOnError(): void
    {
        $infoObj = (object) ['status' => 'error', 'message' => '403 rate limit exceeded'];

        $result = ( new \ReflectionMethod(CalendarHandler::class, 'resolveIcalReleaseObject') )
            ->invoke($this->makeHandler(), $infoObj);

        self::assertIsString($result->published_at);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $result->published_at,
            'Fallback published_at must be an RFC3339 UTC timestamp for the ICS CREATED field'
        );
    }

    /**
     * Caching the GitHub release JSON is a non-essential optimization. When the
     * versioned cache directory does not yet exist but sits under a writable
     * parent, cacheDirectoryIsAvailable() must create it (exercising
     * ensureCachePathExists()'s create-succeeds branch, which is the real
     * first-request scenario) and report true.
     */
    public function testCacheDirectoryIsAvailableReturnsTrueWhenWritable(): void
    {
        $handler = $this->makeHandler();
        $parent  = sys_get_temp_dir() . '/litcal-cache-writable-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($parent, 0755));
        // A not-yet-existing versioned subdir under the writable parent, mirroring
        // the vN-<hash> dir that is absent on the first request for a given version.
        $cacheDir = $parent . '/v5_7-cafef00d';

        try {
            ( new \ReflectionProperty(CalendarHandler::class, 'CachePath') )
                ->setValue($handler, $cacheDir . '/');
            $available = ( new \ReflectionMethod(CalendarHandler::class, 'cacheDirectoryIsAvailable') )
                ->invoke($handler);
            self::assertTrue($available, 'A creatable cache directory must report as available');
            self::assertDirectoryExists($cacheDir, 'The versioned cache directory must have been created');
        } finally {
            if (is_dir($cacheDir)) {
                rmdir($cacheDir);
            }
            rmdir($parent);
        }
    }

    /**
     * Regression: on localhost the response cache is skipped entirely, yet
     * getGithubReleaseInfo() still tried to create the cache directory to
     * persist GHRelease.json and threw a 503 (ServiceUnavailableException) when
     * it could not — taking down ICS output even though the release info is
     * non-essential. cacheDirectoryIsAvailable() must report false instead of
     * throwing, so the caller can proceed without caching.
     */
    public function testCacheDirectoryIsAvailableReturnsFalseWhenParentNotWritable(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('Running as root bypasses directory permissions, so a read-only parent cannot be simulated.');
        }

        $handler  = $this->makeHandler();
        $roParent = sys_get_temp_dir() . '/litcal-cache-readonly-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($roParent, 0500)); // read + execute only: cannot create children

        try {
            ( new \ReflectionProperty(CalendarHandler::class, 'CachePath') )
                ->setValue($handler, $roParent . '/v5_7-deadbeef/');
            $available = ( new \ReflectionMethod(CalendarHandler::class, 'cacheDirectoryIsAvailable') )
                ->invoke($handler);
            self::assertFalse($available, 'An uncreatable cache directory must report as unavailable, not throw');
        } finally {
            chmod($roParent, 0700);
            rmdir($roParent);
        }
    }

    /**
     * The handler serves cached responses from engineCache/ when one exists
     * for the same API version + source-data hash; a cache file produced by
     * earlier (possibly buggy) code would mask regressions, so tests that
     * assert on computed calendar content must force a fresh computation.
     * The md5-named response caches match [0-9a-f]*; GHRelease.json (the
     * GitHub release cache, unrelated to calendar computation) is left alone.
     */
    private function purgeEngineCache(string $extension): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/engineCache/v*/[0-9a-f]*.' . $extension) ?: [] as $staleCache) {
            unlink($staleCache);
        }
    }

    /**
     * Regression for issue #690: the national-calendar 'moveEvent' action was
     * a silent no-op whenever the event to move was still present in the
     * calendar (i.e. NOT suppressed by the celebration replacing it) and the
     * target date was free. US_2011 moves St Camillus de Lellis from July 14
     * (taken by Blessed Kateri Tekakwitha, whose memorial does not suppress
     * his optional memorial) to July 18 — a free Saturday in 2026.
     */
    public function testNationalCalendarMoveEventMovesCoexistingEvent(): void
    {
        $this->purgeEngineCache('json');

        $response = $this->makeHandler(['nation', 'US', '2026'])->handle(
            $this->requestFor('GET', '/calendar/nation/US/2026', ['Accept-Language' => 'en'])
        );

        self::assertSame(200, $response->getStatusCode());
        $body   = $this->decodeJsonBody($response);
        $events = array_values(array_filter(
            $body['litcal'],
            static fn (array $event): bool => $event['event_key'] === 'StCamillusDeLellis'
        ));

        self::assertCount(1, $events, 'St Camillus de Lellis must be present in the US 2026 calendar');
        self::assertStringStartsWith(
            '2026-07-18',
            $events[0]['date'],
            'US_2011 moves St Camillus de Lellis from July 14 to July 18 (moveEvent action)'
        );
    }

    /**
     * Companion regression for issue #690 on a different moveEvent entry:
     * US_2011 moves St Elizabeth of Portugal from July 4 (taken by
     * Independence Day) to July 5, which in 2025 is a free Saturday.
     */
    public function testNationalCalendarMoveEventMovesElizabethOfPortugal(): void
    {
        $this->purgeEngineCache('json');

        $response = $this->makeHandler(['nation', 'US', '2025'])->handle(
            $this->requestFor('GET', '/calendar/nation/US/2025', ['Accept-Language' => 'en'])
        );

        self::assertSame(200, $response->getStatusCode());
        $body   = $this->decodeJsonBody($response);
        $events = array_values(array_filter(
            $body['litcal'],
            static fn (array $event): bool => $event['event_key'] === 'StElizabethPortugal'
        ));

        self::assertCount(1, $events, 'St Elizabeth of Portugal must be present in the US 2025 calendar');
        self::assertStringStartsWith(
            '2025-07-05',
            $events[0]['date'],
            'US_2011 moves St Elizabeth of Portugal from July 4 to July 5 (moveEvent action)'
        );
    }

    /**
     * When January 22 falls on a Sunday (e.g. 2023), the National Day of
     * Prayer for the Unborn moves forward to January 23 (GIRM 373 US
     * adaptation) and the optional memorial of St Vincent Deacon — suppressed
     * by the Sunday on January 22 — is recreated on January 23 alongside it,
     * as confirmed by the USCCB ordo for 2023. A hardcoded special case used
     * to leave St Vincent out entirely in these years.
     */
    public function testMovedEventIsRecreatedAlongsideTransferredDayOfPrayer(): void
    {
        $this->purgeEngineCache('json');

        $response = $this->makeHandler(['nation', 'US', '2023'])->handle(
            $this->requestFor('GET', '/calendar/nation/US/2023', ['Accept-Language' => 'en'])
        );

        self::assertSame(200, $response->getStatusCode());
        $body  = $this->decodeJsonBody($response);
        $dates = [];
        foreach ($body['litcal'] as $event) {
            if (in_array($event['event_key'], ['StVincentDeacon', 'PrayerUnborn'], true)) {
                $dates[$event['event_key']] = substr($event['date'], 0, 10);
            }
        }

        self::assertSame('2023-01-23', $dates['PrayerUnborn'] ?? null, 'Day of Prayer must move to Jan 23 when Jan 22 is a Sunday');
        self::assertSame('2023-01-23', $dates['StVincentDeacon'] ?? null, 'St Vincent must be recreated on Jan 23 alongside the Day of Prayer');
    }

    /**
     * Request the 2025 calendar as ICS (Latin locale for deterministic grade
     * strings) and return the response body with RFC 5545 line folding undone,
     * so assertions can match logical content lines directly.
     */
    private function fetchUnfoldedIcs(): string
    {
        $this->purgeEngineCache('ics');

        $response = $this->makeHandler(['2025'])->handle(
            $this->requestFor('GET', '/calendar/2025', [
                'Accept'          => 'text/calendar',
                'Accept-Language' => 'la',
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringStartsWith('BEGIN:VCALENDAR', $body);

        // produceIcal folds long lines with a CRLF + HT continuation sequence.
        return str_replace("\r\n\t", '', $body);
    }

    /**
     * Regression: DESCRIPTION values were built with pre-escaped literal '\n'
     * sequences, which escapeIcal() then double-escaped to '\\n'. RFC 5545
     * clients (e.g. Google Calendar) unescape '\\' to a literal backslash and
     * therefore rendered a literal "\n" instead of a line break.
     */
    public function testIcsTextValuesAreNotDoubleEscaped(): void
    {
        $unfolded = $this->fetchUnfoldedIcs();

        self::assertStringNotContainsString(
            '\\\\n',
            $unfolded,
            'ICS body must not contain double-escaped newline sequences (backslash-backslash-n)'
        );
    }

    /**
     * Regression: the plain-text DESCRIPTION only included the liturgical
     * rank when grade_display was explicitly set, which it is not for most
     * events; unlike the HTML X-ALT-DESC it never fell back to the localized
     * grade, so the rank never appeared in calendar clients.
     */
    public function testIcsDescriptionIncludesLiturgicalGrade(): void
    {
        $unfolded = $this->fetchUnfoldedIcs();

        // With a Latin locale LitGrade::MEMORIAL->i18n('la', false) is the
        // hardcoded string 'Memoria obligatoria' (no gettext involved); the
        // 2025 General Roman Calendar contains many obligatory memorials, so
        // at least one DESCRIPTION must carry it on an escaped newline.
        self::assertStringContainsString(
            '\nMemoria obligatoria',
            $unfolded,
            'ICS DESCRIPTION must include the localized liturgical rank for memorials'
        );
    }
}
