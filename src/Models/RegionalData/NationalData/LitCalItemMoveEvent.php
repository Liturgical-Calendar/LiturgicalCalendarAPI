<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Models\RegionalData\LiturgicalEventData;

final class LitCalItemMoveEvent extends LiturgicalEventData
{
    public readonly int $day;
    public readonly int $month;

    private function __construct(string $event_key, int $day, int $month)
    {

        parent::__construct($event_key);
        $this->month = $month;
        $this->day   = $day;
    }

    protected static function fromArrayInternal(array $data): static
    {
        if (false === array_key_exists('event_key', $data) || false === array_key_exists('day', $data) || false === array_key_exists('month', $data)) {
            throw new \ValueError('`liturgical_event->event_key`, `liturgical_event->day` and `liturgical_event->month` parameters are required for a `metadata->action` of `moveEvent`');
        }

        if (false === self::isValidMonthValue($data['month'])) {
            throw new \ValueError('`liturgical_event.month` must be an integer between 1 and 12');
        }

        if (false === self::isValidDayValueForMonth($data['month'], $data['day'])) {
            throw new \ValueError('`liturgical_event.day` must be an integer between 1 and 31 and it must be a valid day value for the given month');
        }

        return new static($data['event_key'], $data['day'], $data['month']);
    }

    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'event_key') || false === property_exists($data, 'day') || false === property_exists($data, 'month')) {
            throw new \ValueError('`liturgical_event->event_key`, `liturgical_event->day` and `liturgical_event->month` properties are required for a `metadata->action` of `moveEvent`');
        }

        if (false === self::isValidMonthValue($data->month)) {
            throw new \ValueError('`liturgical_event.month` must be an integer between 1 and 12');
        }

        if (false === self::isValidDayValueForMonth($data->month, $data->day)) {
            throw new \ValueError('`liturgical_event.day` must be an integer between 1 and 31 and it must be a valid day value for the given month');
        }

        return new static($data->event_key, $data->day, $data->month);
    }
}
