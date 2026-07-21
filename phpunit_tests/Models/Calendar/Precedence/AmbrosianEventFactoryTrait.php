<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Precedence;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitSeason;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;

/**
 * Shared `LiturgicalEvent` construction helper for the Ambrosian precedence
 * test suite. Extracted from `AmbrosianLiturgicalDayRankTest` (Task 4) so
 * `AmbrosianPrecedenceResolverTest` (Task 5) does not duplicate the same
 * event-builder verbatim.
 */
trait AmbrosianEventFactoryTrait
{
    /**
     * @param array{
     *     key?: string,
     *     date?: string,
     *     grade?: LitGrade,
     *     season?: ?LitSeason,
     *     dominical?: ?bool,
     *     proper?: ?bool,
     * } $opts
     */
    private function makeEvent(array $opts = []): LiturgicalEvent
    {
        $event = new LiturgicalEvent(
            'Test Event',
            new DateTime(( $opts['date'] ?? '2026-07-20' ) . 'T00:00:00+00:00'),
            grade: $opts['grade'] ?? LitGrade::WEEKDAY
        );

        $event->event_key         = $opts['key'] ?? 'TestEvent';
        $event->liturgical_season = array_key_exists('season', $opts) ? $opts['season'] : null;
        $event->is_dominical      = array_key_exists('dominical', $opts) ? $opts['dominical'] : null;
        $event->is_proper         = array_key_exists('proper', $opts) ? $opts['proper'] : null;

        return $event;
    }
}
