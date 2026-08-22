<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use LiturgicalCalendar\Api\Params\CalendarParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The ICS representation carries three metadata stamps that mean different things (#849).
 *
 * The serialized ICS is cached per params hash and return type, so whatever is written is frozen
 * for the life of that cache entry rather than recomputed per request:
 *
 * - `DTSTAMP` — this object declares `METHOD:PUBLISH`, so RFC 5545 3.8.7.2 makes it "when this
 *   instance was created": generation time. It is also stripped from the ETag by
 *   `validatorSource()`, so its volatility is doubly harmless.
 * - `CREATED` — the GitHub release the data belongs to, so it is stable across regenerations of
 *   the same version.
 * - `LAST-MODIFIED` — the mtime of the versioned cache directory, which is named for API_VERSION
 *   plus a digest of the source data. The release date is the wrong answer here on a deployment
 *   that moves faster than its last stable release.
 *
 * All three must be genuine UTC. `DTSTAMP` used to be built with `date()` and a literal `Z`.
 */
#[CoversClass(CalendarHandler::class)]
final class IcalTimestampStabilityTest extends TestCase
{
    private static function handler(): CalendarHandler
    {
        $handler = ( new \ReflectionClass(CalendarHandler::class) )->newInstanceWithoutConstructor();
        $params  = ( new \ReflectionClass(CalendarParams::class) )->newInstanceWithoutConstructor();
        ( new \ReflectionProperty(CalendarParams::class, 'Locale') )->setValue($params, 'en_US');
        ( new \ReflectionProperty(CalendarHandler::class, 'CalendarParams') )->setValue($handler, $params);

        return $handler;
    }

    /** @param ?\stdClass $release */
    private static function ics(?\stdClass $release): string
    {
        $event            = new LiturgicalEvent('Test Feast', new DateTime('2026-12-25'), grade: LitGrade::FEAST);
        $event->event_key = 'TestFeast';

        $litcal = new \stdClass();
        /** @phpstan-ignore-next-line assigning the minimal shape produceIcal reads */
        $litcal->litcal = [$event];

        return (string) ( new \ReflectionMethod(CalendarHandler::class, 'produceIcal') )
            ->invoke(self::handler(), $litcal, $release);
    }

    private static function validatorSource(string $body): string
    {
        $handler = self::handler();
        ( new \ReflectionProperty(CalendarParams::class, 'ReturnType') )
            ->setValue(( new \ReflectionProperty(CalendarHandler::class, 'CalendarParams') )->getValue($handler), ReturnTypeParam::ICS);

        return (string) ( new \ReflectionMethod(CalendarHandler::class, 'validatorSource') )->invoke($handler, $body);
    }

    // ----------------------------------------------------------------- a known release date

    public function testAKnownReleaseDateAnchorsCreated(): void
    {
        $ics = self::ics((object) ['published_at' => '2025-12-16T15:17:18Z']);

        self::assertStringContainsString('CREATED:20251216T151718Z', $ics, 'CREATED is anchored to the release');
    }

    // ----------------------------------------------------------------- an unknown release date

    /**
     * With no release date, CREATED falls back to generation time rather than being omitted: the
     * body is cached, so the value is written once per cache entry rather than per request.
     */
    public function testAnUnknownReleaseDateFallsBackToGenerationTime(): void
    {
        $ics = self::ics(null);

        self::assertMatchesRegularExpression('/DTSTAMP:\d{8}T\d{6}Z/', $ics, 'DTSTAMP is REQUIRED by RFC 5545');
        self::assertMatchesRegularExpression('/CREATED:\d{8}T\d{6}Z/', $ics, 'CREATED still has to carry a value');
        self::assertMatchesRegularExpression('/LAST-MODIFIED:\d{8}T\d{6}Z/', $ics, 'LAST-MODIFIED still has to carry a value');
    }

    /**
     * CREATED and DTSTAMP are different claims and must not be collapsed into one value: a release
     * cut months ago dates the data, while DTSTAMP dates this instance.
     */
    public function testCreatedTracksTheReleaseWhileDtstampTracksGeneration(): void
    {
        $ics = self::ics((object) ['published_at' => '2025-12-16T15:17:18Z']);

        self::assertSame(1, preg_match('/DTSTAMP:(\d{8}T\d{6})Z/', $ics, $dt));
        self::assertStringContainsString('CREATED:20251216T151718Z', $ics);
        self::assertNotSame('20251216T151718', $dt[1], 'DTSTAMP must date this instance, not the release');
    }

    // ----------------------------------------------------------------- DTSTAMP is genuine UTC

    /**
     * DTSTAMP carried a literal `Z` while being built from `date()` — server-local time. On a
     * Europe/Vatican host that is up to two hours ahead of the instant it claims.
     */
    public function testDtstampIsGenuineUtcNotLocalTimeWearingAZ(): void
    {
        // The bug is invisible on a UTC host, where date() and gmdate() agree — including this one.
        // Forcing a non-UTC default is what makes the assertion able to fail: the deployment runs
        // Europe/Vatican, where the old spelling was two hours ahead of the instant it claimed.
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Vatican');

        try {
            $ics = self::ics(null);
            self::assertSame(1, preg_match('/DTSTAMP:(\\d{8}T\\d{6})Z/', $ics, $m));

            $stamped = \DateTimeImmutable::createFromFormat('Ymd\\THis', $m[1], new \DateTimeZone('UTC'));
            self::assertInstanceOf(\DateTimeImmutable::class, $stamped);

            $skew = abs($stamped->getTimestamp() - time());
            self::assertLessThan(
                120,
                $skew,
                "DTSTAMP is {$skew}s from the real UTC instant; it is being built from local time and labelled Z"
            );
        } finally {
            date_default_timezone_set($previous);
        }
    }
}
