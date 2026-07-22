<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Params\CalendarParams;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Plan 7 Task 6, Part B: `LiturgicalEventCollection::setAmbrosianHolyDaysOfObligation()`.
 *
 * Mirrors the rite-agnostic HDoO half of the Roman `setSeasonsAndHolyDaysOfObligation()`
 * (`in_array($event_key, $HolyDaysOfObligation)` plus marking every Sunday), but sources its
 * `event_key` set from `AmbrosianHolyDaysOfObligation::DEFAULT` (Ambrosian keys) instead of the
 * Roman `CalendarParams::$HolyDaysOfObligation` default (Roman keys like `StJoseph` /
 * `StsPeterPaulAp` / `CorpusChristi`, which don't exist in the Ambrosian calendar).
 *
 * This test builds a minimal, hand-populated `LiturgicalEventCollection` (three events: a known
 * Ambrosian HDoO key on a weekday, an ordinary ferial weekday, and a Sunday that carries no HDoO
 * key) rather than assembling a full Ambrosian year — the method under test only reads
 * `event_key` and the date's day-of-week, so a minimal fixture is sufficient and keeps this test
 * squarely a `Models/Calendar` unit test rather than a `Handlers` integration test.
 *
 * This still requires a real `CalendarParams` instance (required by the
 * `LiturgicalEventCollection` constructor), which in turn needs `Router::$apiFilePath` pinned to
 * read local source data - hence extending the `Handlers` layer's `AbstractHandlerTestCase`
 * rather than plain `PHPUnit\Framework\TestCase`, even though this test lives under
 * `Models/Calendar`.
 */
#[CoversClass(LiturgicalEventCollection::class)]
final class LiturgicalEventCollectionAmbrosianHdooTest extends AbstractHandlerTestCase
{
    private function makeCollection(int $year = 2025): LiturgicalEventCollection
    {
        $params = new CalendarParams();
        $params->setRite(Rite::AMBROSIAN);
        $params->setParams(['year' => $year]);

        return new LiturgicalEventCollection($params);
    }

    private function addEvent(LiturgicalEventCollection $cal, string $key, string $dayMonthYear): LiturgicalEvent
    {
        $event = new LiturgicalEvent($key, DateTime::fromFormat($dayMonthYear));
        $cal->addLiturgicalEvent($key, $event);
        return $event;
    }

    public function testKnownAmbrosianHdooKeyIsMarkedObligatory(): void
    {
        $cal = $this->makeCollection(2025);

        // 25 December 2025 is a Thursday: this exercises the event_key branch of the HDoO check,
        // not the Sunday branch.
        $christmas = $this->addEvent($cal, 'Christmas', '25-12-2025');
        self::assertFalse($christmas->holy_day_of_obligation);

        $cal->setAmbrosianHolyDaysOfObligation();

        self::assertTrue($christmas->holy_day_of_obligation);
    }

    public function testSundayIsMarkedObligatoryRegardlessOfEventKey(): void
    {
        $cal = $this->makeCollection(2025);

        // 2 November 2025 is a Sunday; `AllSouls` is not in the Ambrosian HDoO key set, so this
        // exercises the "every Sunday is obligatory" branch on its own.
        $allSouls = $this->addEvent($cal, 'AllSouls', '2-11-2025');
        self::assertSame(7, (int) $allSouls->date->format('N'));

        $cal->setAmbrosianHolyDaysOfObligation();

        self::assertTrue($allSouls->holy_day_of_obligation);
    }

    public function testOrdinaryFerialWeekdayIsNotMarkedObligatory(): void
    {
        $cal = $this->makeCollection(2025);

        // 15 July 2025 is a Tuesday, and this fixture key is not in the Ambrosian HDoO key set.
        $ferialWeekday = $this->addEvent($cal, 'OrdinaryFerialFixture', '15-7-2025');
        self::assertSame(2, (int) $ferialWeekday->date->format('N'));

        $cal->setAmbrosianHolyDaysOfObligation();

        self::assertFalse($ferialWeekday->holy_day_of_obligation);
    }
}
