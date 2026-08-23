<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\EventsHandler;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\ApiException;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Plan 7 Task 12: the `/events` endpoint becomes rite-aware. `/events/ambrosian`
 * must serve the Ambrosian comune catalog (temporale + comune sanctorale) instead
 * of the Roman one, while a bare `/events` (Rite::ROMAN, the default) must remain
 * byte-identical to its pre-Task-12 behavior.
 *
 * Mirrors {@see CalendarRiteRoutingTest}'s in-process handle()-with-Rite pattern.
 */
#[CoversClass(EventsHandler::class)]
final class EventsHandlerRiteRoutingTest extends AbstractHandlerTestCase
{
    /**
     * The Milan diocesan catalog test below is the first test in this class whose
     * request locale ends up forced to `it_IT` (milano_it only ships `it_IT`/`la_VA`
     * translations — see {@see EventsHandler::loadAmbrosianDiocesanData()}). Unlike
     * `CalendarHandler::prepareL10N()` (which explicitly skips the OS `setlocale()`
     * call for Latin requests), `EventsHandler::setLocale()` always calls it — and
     * that call is process-global (`LC_ALL`), so once it succeeds it persists for
     * any later test in the same PHPUnit process. `CalendarGoldenMasterTest`'s
     * default (no `Accept-Language`) requests resolve to Latin and implicitly rely
     * on gettext falling through to the untranslated (Latin) msgid, which breaks the
     * moment the process locale is left pinned on a real translated catalog like
     * `it_IT` by an earlier test. Reset to `C` (no gettext catalog binds to it, so
     * lookups pass through unchanged) after every test in this class so that
     * assumption holds regardless of suite execution order.
     */
    protected function tearDown(): void
    {
        setlocale(LC_ALL, 'C');
        parent::tearDown();
    }

    /**
     * @param string[] $pathParts
     * @return array{status:int,body:array<string,mixed>}
     */
    private function handle(array $pathParts, Rite $rite, string $uri): array
    {
        $handler = new EventsHandler($pathParts, $rite);
        try {
            $response = $handler->handle($this->requestFor('GET', $uri, ['Accept-Language' => 'en']));
        } catch (ApiException $e) {
            return ['status' => $e->getStatus(), 'body' => []];
        }

        return [
            'status' => $response->getStatusCode(),
            'body'   => $this->decodeJsonBody($response),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $events
     * @return array<string,array<string,mixed>>
     */
    private static function byKey(array $events): array
    {
        $byKey = [];
        foreach ($events as $event) {
            $byKey[$event['event_key']] = $event;
        }
        return $byKey;
    }

    public function testRomanCatalogStillContainsRomanKeys(): void
    {
        // Regression guard: the Roman (default) branch must remain byte-identical.
        $result = $this->handle([], Rite::ROMAN, '/events');
        self::assertSame(200, $result['status']);

        $byKey = self::byKey($result['body']['litcal_events']);
        self::assertArrayHasKey('AshWednesday', $byKey, 'Roman-only temporale anchor must be present');
        self::assertArrayNotHasKey('DedicationDuomo', $byKey, 'Ambrosian-only temporale event must NOT leak into the Roman catalog');
    }

    public function testAmbrosianCatalogContainsAmbrosianOnlyKeysNotRomanOnes(): void
    {
        $result = $this->handle([], Rite::AMBROSIAN, '/events/ambrosian');
        self::assertSame(200, $result['status']);

        $byKey = self::byKey($result['body']['litcal_events']);

        // Ambrosian-only temporale anchor (Dedication of the Duomo of Milan) — absent from the Roman catalog.
        self::assertArrayHasKey('DedicationDuomo', $byKey);
        // Ambrosian comune sanctorale event.
        self::assertArrayHasKey('Circoncisione', $byKey);
        // Roman-only temporale anchor must NOT appear in the Ambrosian catalog.
        self::assertArrayNotHasKey('AshWednesday', $byKey);

        self::assertNull($result['body']['settings']['national_calendar']);
        self::assertNull($result['body']['settings']['diocesan_calendar']);
    }

    public function testAmbrosianRejectsNationalCalendarWith400(): void
    {
        $result = $this->handle(['nation', 'US'], Rite::AMBROSIAN, '/events/ambrosian/nation/US');
        self::assertSame(StatusCode::BAD_REQUEST->value, $result['status']);
    }

    public function testAmbrosianRejectsVANationalCalendarWith400(): void
    {
        // VA normalizes NationalCalendar to null in EventsParams, so this exercises the
        // separate "VA was requested" marker rather than the plain non-null check.
        $result = $this->handle(['nation', 'VA'], Rite::AMBROSIAN, '/events/ambrosian/nation/VA');
        self::assertSame(StatusCode::BAD_REQUEST->value, $result['status']);
    }

    public function testAmbrosianRejectsRomanDiocesanCalendarWith400(): void
    {
        // boston_us is a Roman-rite diocese requested under the Ambrosian rite — a
        // rite mismatch, not "the Ambrosian rite has no diocesan layer at all"
        // (Task 12 gives the Ambrosian rite its own diocesan catalogs).
        $result = $this->handle(['diocese', 'boston_us'], Rite::AMBROSIAN, '/events/ambrosian/diocese/boston_us');
        self::assertSame(StatusCode::BAD_REQUEST->value, $result['status']);
    }

    public function testAmbrosianRejectsUnknownDiocesanCalendarWith400(): void
    {
        $result = $this->handle(['diocese', 'nowhere_zz'], Rite::AMBROSIAN, '/events/ambrosian/diocese/nowhere_zz');
        self::assertSame(StatusCode::BAD_REQUEST->value, $result['status']);
    }

    public function testAmbrosianDiocesanCatalogContainsDiocesanEventsAndComune(): void
    {
        // Task 12: /events/ambrosian/diocese/{diocese_id} must merge the diocese's own
        // sanctorale (event_key prefixed with the diocese id, per
        // EventsHandler::processAmbrosianDiocesanCalendarData()) into the comune
        // Ambrosian catalog (temporale + comune sanctorale) already served by
        // /events/ambrosian.
        $result = $this->handle(['diocese', 'milano_it'], Rite::AMBROSIAN, '/events/ambrosian/diocese/milano_it');
        self::assertSame(200, $result['status']);

        $byKey = self::byKey($result['body']['litcal_events']);

        // Milan-diocese-only sanctorale events (see jsondata/sourcedata/rite/ambrosian/calendars/dioceses/IT/milano_it).
        self::assertArrayHasKey('milano_it_SanLuigiGuanella', $byKey);
        self::assertArrayHasKey('milano_it_BeatoCarloAcutis', $byKey);

        // The comune Ambrosian catalog (temporale + comune sanctorale) must still be present.
        self::assertArrayHasKey('DedicationDuomo', $byKey);
        self::assertArrayHasKey('Circoncisione', $byKey);

        // Roman-only temporale anchor must still be excluded.
        self::assertArrayNotHasKey('AshWednesday', $byKey);

        self::assertSame('milano_it', $result['body']['settings']['diocesan_calendar']);
        // Ambrosian dioceses are not layered on top of a national calendar.
        self::assertNull($result['body']['settings']['national_calendar']);
    }

    /**
     * A diocesan `setProperty` override modifies the comune catalog entry under its plain key.
     * Emitting a `{diocese}_{key}` duplicate alongside the untouched comune entry — which is what
     * the old re-declare mechanism did — would leave `/events` disagreeing with `/calendar`.
     */
    public function testAmbrosianDiocesanOverrideHasNoPrefixedDuplicate(): void
    {
        $result = $this->handle(['diocese', 'lugano_ch'], Rite::AMBROSIAN, '/events/ambrosian/diocese/lugano_ch');
        self::assertSame(200, $result['status']);

        $byKey = self::byKey($result['body']['litcal_events']);

        // The overridden comune keys stay, under their plain keys.
        self::assertArrayHasKey('StsProtaseGervase', $byKey);
        self::assertArrayHasKey('StFrancisOfAssisi', $byKey);

        // No phantom prefixed duplicates for the overridden keys.
        self::assertArrayNotHasKey('lugano_ch_StsProtaseGervase', $byKey);
        self::assertArrayNotHasKey('lugano_ch_StFrancisOfAssisi', $byKey);

        // A genuine createNew diocesan row is still prefixed, as before.
        self::assertArrayHasKey('lugano_ch_BeatoManfredoSettala', $byKey);

        // The override applied to the comune entry.
        self::assertSame(LitGrade::MEMORIAL->value, $byKey['StsProtaseGervase']['grade']);
        self::assertSame(['Proper'], $byKey['StsProtaseGervase']['common']);
    }
}
