<?php

namespace LiturgicalCalendar\Api\Models;

use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewMobile;

abstract class LiturgicalEventData extends AbstractJsonSrcData
{
    public protected(set) string $event_key;

    public string $name;

    protected function __construct(string $event_key)
    {
        $this->event_key = $event_key;
    }

    /**
     * @return bool Whether the liturgical event is an instance of a mobile event (i.e. can fall on different days, since it is relative to another date or liturgical event).
     */
    public function isMobile(): bool
    {
        return $this instanceof LitCalItemCreateNewMobile;
    }

    /**
     * Check whether a given month value is valid.
     *
     * @param int $month The month value to check.
     *
     * @return bool Whether the month value is valid. A month value is valid if it is between 1 and 12 (inclusive).
     */
    public static function isValidMonthValue(int $month): bool
    {
        return $month > 0 && $month < 13;
    }

    /**
     * Checks whether a given day value is valid for a given month.
     *
     * A day value is valid for a given month if it is between 1 and the number of days in the month (inclusive).
     * @param int $month The month value to check against.
     * @param int $day The day value to check.
     * @return bool Whether the day value is valid for the given month.
     */
    public static function isValidDayValueForMonth(int $month, int $day): bool
    {
        switch ($month) {
            case 2:
                // February
                return $day > 0 && $day < 29;
            case 4:
            case 6:
            case 9:
            case 11:
                // January, March, May, July, August, October, December
                return $day > 0 && $day < 32;
            default:
                // September, April, June, November
                return $day > 0 && $day < 31;
        }
    }

    /**
     * @param \stdClass $data
     * @return static
     */
    abstract protected static function fromObjectInternal(\stdClass $data): static;

    abstract protected static function fromArrayInternal(array $data): static;
}
