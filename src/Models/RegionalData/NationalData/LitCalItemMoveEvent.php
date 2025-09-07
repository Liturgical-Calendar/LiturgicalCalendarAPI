<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Models\LiturgicalEventData;

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

    /**
     * Creates an instance of LitCalItemMoveEvent from an associative array.
     *
     * The array must have the following keys:
     * - event_key (string): the key of the event
     * - day (int): the day of the event
     * - month (int): the month of the event
     *
     * @param array{event_key:string,day:int,month:int} $data The associative array containing the properties of the class.
     * @return static The newly created instance.
     * @throws \ValueError if the keys of the data parameter do not match the expected keys.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (false === array_key_exists('event_key', $data) || false === array_key_exists('day', $data) || false === array_key_exists('month', $data)) {
            throw new \ValueError('`liturgical_event->event_key`, `liturgical_event->day` and `liturgical_event->month` parameters are required for a `metadata->action` of `moveEvent`');
        }

        if (false === static::isValidMonthValue($data['month'])) {
            throw new \ValueError('`liturgical_event.month` must be an integer between 1 and 12');
        }

        if (false === static::isValidDayValueForMonth($data['month'], $data['day'])) {
            throw new \ValueError("`liturgical_event.day` must be a valid day integer for the given month {$data['month']}, got {$data['day']}");
        }

        return new static($data['event_key'], $data['day'], $data['month']);
    }

    /**
     * Creates an instance of LitCalItemMoveEvent from an object containing the required properties.
     *
     * The stdClass object must have the following properties:
     * - event_key (string): the key of the event
     * - day (int): the day of the event
     * - month (int): the month of the event
     *
     * @param \stdClass&object{event_key:string,day:int,month:int} $data The stdClass object containing the properties of the class.
     * @return static The newly created instance(s).
     * @throws \ValueError if the required properties are not present in the stdClass object or if the properties have invalid types.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'event_key') || false === property_exists($data, 'day') || false === property_exists($data, 'month')) {
            throw new \ValueError('`liturgical_event->event_key`, `liturgical_event->day` and `liturgical_event->month` properties are required for a `metadata->action` of `moveEvent`');
        }

        if (false === static::isValidMonthValue($data->month)) {
            throw new \ValueError('`liturgical_event.month` must be an integer between 1 and 12');
        }

        if (false === static::isValidDayValueForMonth($data->month, $data->day)) {
            throw new \ValueError("`liturgical_event.day` must be a valid day integer for the given month {$data->month}, got {$data->day}");
        }

        return new static($data->event_key, $data->day, $data->month);
    }
}
