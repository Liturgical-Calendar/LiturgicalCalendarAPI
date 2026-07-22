<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

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

    public function testAmbrosianRejectsDiocesanCalendarWith400(): void
    {
        $result = $this->handle(['diocese', 'boston_us'], Rite::AMBROSIAN, '/events/ambrosian/diocese/boston_us');
        self::assertSame(StatusCode::BAD_REQUEST->value, $result['status']);
    }
}
