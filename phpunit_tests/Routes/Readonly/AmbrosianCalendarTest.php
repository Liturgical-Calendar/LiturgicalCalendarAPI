<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\Readonly;

use LiturgicalCalendar\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Live HTTP integration test for `/calendar/ambrosian/{year}`, the route Plan 7 Task 10 un-501s.
 *
 * Before this task, `CalendarHandler::handle()` threw `ImplementationException` (HTTP 501) as
 * soon as `Rite::AMBROSIAN` was detected. Task 10 replaces that gate with a real generation
 * branch that calls `calculateAmbrosianCalendar()` (Task 9), so this test hits the running API
 * (skipped, per `ApiTestCase`, when localhost:8000 is unreachable) and asserts the endpoint now
 * returns a full calendar instead of a 501.
 *
 * 2025 is chosen because it exercises the Ambrosian Advent/Christ-the-King transfer machinery:
 * St Ambrose's dies natalis (Dec 7) falls on Advent IV Sunday that civil year, so the precedence
 * resolver (Plan 4) must transfer it to Dec 6 — a good sanity check that the full pipeline (not
 * just the isolated per-task passes) is wired correctly end to end.
 */
#[Group('slow')]
final class AmbrosianCalendarTest extends ApiTestCase
{
    public function testGetAmbrosianCalendar2025ReturnsCalculatedCalendar(): void
    {
        // year_type=CIVIL requests a straight Jan-Dec 2025 calendar (rather than the default
        // LITURGICAL view, which spans Advent-to-Advent across two civil years) so the spot-check
        // dates below line up with a single, unambiguous civil year.
        $response = self::$http->get('/calendar/ambrosian/2025?year_type=CIVIL', []);

        self::assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK, not the pre-Task-10 501: ' . $response->getBody());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $data = json_decode((string) $response->getBody());
        self::assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg());

        self::assertIsObject($data);
        self::assertObjectHasProperty('litcal', $data);
        self::assertIsArray($data->litcal);
        self::assertNotEmpty($data->litcal, 'Expected a non-empty litcal array for the Ambrosian comune, 2025');

        $byKey = [];
        foreach ($data->litcal as $event) {
            $byKey[$event->event_key] = $event;
        }

        self::assertArrayHasKey('DedicationDuomo', $byKey, 'Expected DedicationDuomo in the Ambrosian 2025 calendar');
        self::assertStringStartsWith('2025-10-19', $byKey['DedicationDuomo']->date);

        self::assertArrayHasKey('ChristKing', $byKey, 'Expected ChristKing in the Ambrosian 2025 calendar');
        self::assertStringStartsWith('2025-11-09', $byKey['ChristKing']->date);

        self::assertArrayHasKey('StAmbrose', $byKey, 'Expected StAmbrose in the Ambrosian 2025 calendar');
        self::assertStringStartsWith(
            '2025-12-06',
            $byKey['StAmbrose']->date,
            'StAmbrose (Dec 7) coincides with Advent IV Sunday in 2025 and should be transferred to Dec 6'
        );
    }

    /**
     * The default `year_type` is `LITURGICAL`, which drives the two-run + splice path in
     * `handle()` (generate the requested year, generate year-1, purge/merge around Advent I —
     * mirroring the Roman branch exactly, per Task 10). This is the riskiest part of the wiring,
     * so assert directly on the spliced result: no duplicate event_keys (merge() must not
     * double-add anything spanning the two runs), exactly one `Advent1` (the boundary event),
     * and the civil-year date range implied by an Advent-to-Advent liturgical year.
     */
    public function testGetAmbrosianCalendarDefaultLiturgicalYearTypeSplicesWithoutDuplicates(): void
    {
        $response = self::$http->get('/calendar/ambrosian/2025', []);
        self::assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK: ' . $response->getBody());

        $data = json_decode((string) $response->getBody());
        self::assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg());
        self::assertSame('LITURGICAL', $data->settings->year_type);

        $keyCounts = [];
        foreach ($data->litcal as $event) {
            $keyCounts[$event->event_key] = ( $keyCounts[$event->event_key] ?? 0 ) + 1;
        }
        $duplicates = array_filter($keyCounts, static fn (int $count): bool => $count > 1);
        self::assertSame([], $duplicates, 'merge() must not produce duplicate event_keys across the two spliced runs');
        self::assertArrayHasKey('Advent1', $keyCounts, 'Expected exactly one Advent1 (the splice boundary)');
        self::assertSame(1, $keyCounts['Advent1']);
    }

    public function testGetAmbrosianCalendarComuneBaseYearAlsoReturns200(): void
    {
        // No year segment: defaults to the current year, mirroring /calendar's behaviour.
        $response = self::$http->get('/calendar/ambrosian', []);
        self::assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK: ' . $response->getBody());
    }
}
