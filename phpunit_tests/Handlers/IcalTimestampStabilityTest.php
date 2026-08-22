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
 * The ICS representation must carry timestamps that identify the calendar, not the moment it was
 * serialized (#849).
 *
 * `CREATED` and `LAST-MODIFIED` are hashed into the ETag — unlike `DTSTAMP`, which
 * `CalendarHandler::validatorSource()` blanks out first. So filling them with `gmdate()` when the
 * GitHub release lookup failed gave an unchanged calendar a fresh validator on every request, and a
 * conditional GET could never answer 304.
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

    public function testAKnownReleaseDateStampsAllThreeFields(): void
    {
        $ics = self::ics((object) ['published_at' => '2025-12-16T15:17:18Z']);

        self::assertStringContainsString('DTSTAMP:20251216T151718Z', $ics);
        self::assertStringContainsString('CREATED:20251216T151718Z', $ics);
        self::assertStringContainsString('LAST-MODIFIED:20251216T151718Z', $ics);
    }

    // ----------------------------------------------------------------- an unknown release date

    /**
     * The regression. With no release date, CREATED/LAST-MODIFIED used to be filled with the
     * current instant, which is both untrue and hashed into the ETag.
     */
    public function testAnUnknownReleaseDateOmitsCreatedAndLastModified(): void
    {
        $ics = self::ics(null);

        self::assertStringNotContainsString('CREATED:', $ics, 'CREATED must be omitted rather than filled with the current time');
        self::assertStringNotContainsString('LAST-MODIFIED:', $ics, 'LAST-MODIFIED must be omitted rather than filled with the current time');
        self::assertMatchesRegularExpression('/DTSTAMP:\d{8}T\d{6}Z/', $ics, 'DTSTAMP is REQUIRED by RFC 5545 and must still be present');
    }

    /**
     * What the ETag is actually computed over must not move between two generations of the same
     * calendar, even while the release date is unknown — the case that was broken.
     */
    public function testTheEtagInputIsIdenticalAcrossGenerationsWhenTheReleaseIsUnknown(): void
    {
        $first  = self::validatorSource(self::ics(null));
        $second = self::validatorSource(self::ics(null));

        self::assertSame($first, $second, 'two generations of the same calendar must hash to the same validator');
    }

    // ----------------------------------------------------------------- DTSTAMP is genuine UTC

    /**
     * DTSTAMP carried a literal `Z` while being built from `date()` — server-local time. On a
     * Europe/Vatican host that is up to two hours ahead of the instant it claims.
     */
    public function testDtstampIsGenuineUtcNotLocalTimeWearingAZ(): void
    {
        $ics = self::ics(null);
        self::assertSame(1, preg_match('/DTSTAMP:(\d{8}T\d{6})Z/', $ics, $m));

        $stamped = \DateTimeImmutable::createFromFormat('Ymd\THis', $m[1], new \DateTimeZone('UTC'));
        self::assertInstanceOf(\DateTimeImmutable::class, $stamped);

        $skew = abs($stamped->getTimestamp() - time());
        self::assertLessThan(
            120,
            $skew,
            "DTSTAMP is {$skew}s from the real UTC instant; it is being built from local time and labelled Z"
        );
    }
}
