<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Params\CalendarParams;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The ICS validator source must neutralise every provenance stamp the representation embeds
 * about itself, not only `DTSTAMP`.
 *
 * {@see CalendarHandler::produceIcal()} emits three of them, and all three can carry a
 * per-request value:
 *
 * - `DTSTAMP` is always generation time.
 * - `LAST-MODIFIED` is the engine-cache directory's mtime, and falls back to generation time
 *   whenever that directory cannot be resolved — which is ordinary rather than exceptional,
 *   since localhost deliberately bypasses the cache and never calls `ensureCachePathExists()`,
 *   leaving `CachePath` a relative path resolved against the server's working directory.
 * - `CREATED` is the GitHub release date, and falls back to generation time when that lookup
 *   fails.
 *
 * None of the three describes the calendar; they describe when and whence this body was
 * produced. Leaving any of them in the hash hands an unchanged calendar a fresh validator, so a
 * conditional request re-transfers the whole body for nothing — precisely the defect the
 * validator exists to prevent. The validator is weak (`W/`) exactly so that bodies differing
 * only in these stamps may share it (RFC 9110 §8.8.1).
 *
 * This is a unit test rather than a live-HTTP one on purpose: whether `LAST-MODIFIED` takes its
 * mtime value or its generation-time fallback depends on whether `engineCache/<version>/` happens
 * to exist relative to the server's working directory, so an integration test would guard the bug
 * only in whichever environment it happened to run.
 */
#[CoversClass(CalendarHandler::class)]
final class CalendarIcsValidatorSourceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // CalendarParams resolves source-data paths through Router::$apiFilePath on construction,
        // which is a typed static left uninitialised outside a served request.
        Router::getApiPaths();
    }

    /**
     * A minimal but structurally faithful ICS body carrying all three provenance stamps.
     */
    private static function icsBody(string $dtstamp, string $created, string $lastModified): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:litcal-2026-easter',
            'DTSTAMP:' . $dtstamp,
            'DTSTART;VALUE=DATE:20260405',
            'SUMMARY:Easter Sunday',
            'CREATED:' . $created,
            'LAST-MODIFIED:' . $lastModified,
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);
    }

    private static function validatorSourceForIcs(string $body): string
    {
        $handler = new CalendarHandler([]);

        $params             = new CalendarParams();
        $params->ReturnType = ReturnTypeParam::ICS;

        $property = new \ReflectionProperty(CalendarHandler::class, 'CalendarParams');
        $property->setValue($handler, $params);

        $method = new \ReflectionMethod(CalendarHandler::class, 'validatorSource');
        $result = $method->invoke($handler, $body);

        self::assertIsString($result);

        return $result;
    }

    public function testBodiesDifferingOnlyInTheirProvenanceStampsShareOneValidatorSource(): void
    {
        // The same calendar generated twice: a later second, a newer release, and a rebuilt
        // cache directory. Every difference is provenance; none of it is the calendar.
        $first  = self::icsBody('20260822T100031Z', '20251216T151718Z', '20260822T100031Z');
        $second = self::icsBody('20260822T100033Z', '20260101T090000Z', '20260822T100706Z');

        self::assertNotSame($first, $second, 'the fixture must actually differ, or this proves nothing');
        self::assertSame(
            md5(self::validatorSourceForIcs($first)),
            md5(self::validatorSourceForIcs($second)),
            'DTSTAMP, CREATED and LAST-MODIFIED must all be neutralised before hashing'
        );
    }

    public function testAGenuineContentDifferenceStillChangesTheValidatorSource(): void
    {
        // The converse guard: neutralising the stamps must not flatten the calendar itself, or
        // every calendar would share one validator and a 304 would be answered for the wrong body.
        $easter    = self::icsBody('20260822T100031Z', '20251216T151718Z', '20260822T100031Z');
        $pentecost = str_replace('SUMMARY:Easter Sunday', 'SUMMARY:Pentecost', $easter);

        self::assertNotSame(
            md5(self::validatorSourceForIcs($easter)),
            md5(self::validatorSourceForIcs($pentecost))
        );
    }
}
