<?php

namespace LiturgicalCalendar\Api\Map;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\LiturgicalEvent;

/**
 * A map of liturgical events.
 *
 * {@inheritDoc}
 */
class LiturgicalEventsMap extends AbstractLiturgicalEventMap
{
    /**
     * Returns the first weekday (ferial) event occurring on the given day, if it exists.
     *
     * @param DateTime $date The date for which to find the ferial event.
     * @return LiturgicalEvent|null The ferial event on the given date, or null if no event exists.
     */
    public function getSameDayFerialEvent(DateTime $date): ?LiturgicalEvent
    {
        return array_find($this->eventMap, fn ($litEvent) => $litEvent->grade === LitGrade::WEEKDAY && $litEvent->date == $date);
    }
}
