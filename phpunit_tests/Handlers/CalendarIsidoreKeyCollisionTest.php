<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;

/**
 * Regression test for #939: `StIsidore` was the `event_key` for two different saints.
 *
 * `propriumdesanctis_1970` declares **Isidore of Seville** (4 April, "Bishop and Doctor of
 * the Church"); `propriumdesanctis_US_2011` declared **Isidore the Farmer** (15 May) under
 * the very same key. Two consequences followed, both silent:
 *
 *  1. `LiturgicalEventCollection::addLiturgicalEvent()` is keyed on `event_key`, so the
 *     national missal's 15 May row *overwrote* the 4 April row in the US calendar — Isidore
 *     of Seville simply vanished from the calendar of a country that celebrates him.
 *  2. The sanctorale lectionary is keyed on `event_key` alone, and the per-missal tier is
 *     loaded over the rite-level tier. The US missal's all-empty placeholder therefore
 *     erased Seville's readings from the rite-level `lectionary/sanctorum/{locale}.json`.
 *
 * The fix gives the US row its own key, `StIsidoreFarmer`. These tests pin both halves:
 * the two saints occupy two distinct keys on two distinct dates, and their readings no
 * longer contend.
 *
 * 2025 is chosen because 4 April 2025 is a free Lenten weekday (Easter is 20 April), so
 * Seville is neither suppressed by Holy Week nor swallowed by the Easter octave — the
 * collision, if reintroduced, is visible rather than accidentally masked.
 */
final class CalendarIsidoreKeyCollisionTest extends AbstractHandlerTestCase
{
    private const SEVILLE_KEY = 'StIsidore';
    private const FARMER_KEY  = 'StIsidoreFarmer';

    /**
     * @return array<int,array<string,mixed>>
     */
    private function litcalForUsa2025(): array
    {
        $handler = new CalendarHandler(['nation', 'US', '2025']);
        $handler->setAllowedReturnTypes([
            ReturnTypeParam::JSON,
            ReturnTypeParam::YAML,
            ReturnTypeParam::XML,
            ReturnTypeParam::ICS,
        ]);
        $response = $handler->handle(
            $this->requestFor('GET', '/calendar/nation/US/2025', ['Accept' => 'application/json'])
        );
        self::assertSame(200, $response->getStatusCode());

        $decoded = $this->decodeJsonBody($response);
        self::assertArrayHasKey('litcal', $decoded);
        self::assertIsArray($decoded['litcal']);

        /** @var array<int,array<string,mixed>> $litcal */
        $litcal = $decoded['litcal'];
        return $litcal;
    }

    /**
     * @param array<int,array<string,mixed>> $litcal
     * @return array<string,mixed>
     */
    private function eventWithKey(array $litcal, string $eventKey): array
    {
        foreach ($litcal as $event) {
            if (( $event['event_key'] ?? null ) === $eventKey) {
                return $event;
            }
        }
        self::fail("No liturgical event with event_key '{$eventKey}' in the US 2025 calendar.");
    }

    /**
     * Both saints are present, on their own dates. Before the fix the US calendar carried a
     * single `StIsidore` — the Farmer's 15 May row, having overwritten Seville's 4 April one.
     */
    public function testBothIsidoresArePresentOnTheirOwnDates(): void
    {
        $litcal = $this->litcalForUsa2025();

        $seville = $this->eventWithKey($litcal, self::SEVILLE_KEY);
        $farmer  = $this->eventWithKey($litcal, self::FARMER_KEY);

        self::assertStringStartsWith('2025-04-04', (string) $seville['date'], 'Isidore of Seville is 4 April');
        self::assertStringStartsWith('2025-05-15', (string) $farmer['date'], 'Isidore the Farmer is 15 May');
    }

    /**
     * Exactly one event per key: a reintroduced collision would show up here as either a
     * missing key or a duplicated one.
     */
    public function testEachIsidoreKeyOccursExactlyOnce(): void
    {
        $litcal = $this->litcalForUsa2025();

        $keys = array_map(
            static fn (array $event): mixed => $event['event_key'] ?? null,
            $litcal
        );

        self::assertSame(1, count(array_keys($keys, self::SEVILLE_KEY, true)));
        self::assertSame(1, count(array_keys($keys, self::FARMER_KEY, true)));
    }

    /**
     * The readings no longer contend. Seville keeps the rite-level sanctorale readings that
     * the US missal's empty placeholder used to erase; the Farmer carries his own (as yet
     * unpopulated) per-missal entry rather than borrowing Seville's.
     */
    public function testTheTwoIsidoresDoNotShareReadings(): void
    {
        $litcal = $this->litcalForUsa2025();

        $seville = $this->eventWithKey($litcal, self::SEVILLE_KEY);
        $farmer  = $this->eventWithKey($litcal, self::FARMER_KEY);

        self::assertArrayHasKey('readings', $seville);
        self::assertArrayHasKey('readings', $farmer);
        self::assertIsArray($seville['readings']);
        self::assertIsArray($farmer['readings']);

        self::assertNotSame(
            $seville['readings'],
            $farmer['readings'],
            'Isidore of Seville and Isidore the Farmer must not resolve to the same readings.'
        );

        self::assertNotSame(
            '',
            $seville['readings']['first_reading'] ?? '',
            "Isidore of Seville's readings must survive the US missal's empty placeholder."
        );
    }
}
