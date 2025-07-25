<?php

namespace LiturgicalCalendar\Api\Models;

use LiturgicalCalendar\Api\Enum\DateRelation;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\LatinUtils;
use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;

final class RelativeLiturgicalDate extends AbstractJsonSrcData
{
    public string $day_of_the_week;
    public DateRelation $relative_time;
    public string $event_key;

    /**
     * Constructs a new RelativeLiturgicalDate object.
     *
     * The RelativeLiturgicalDate class is used to specify the date of a liturgical event that is relative to another liturgical event.
     * PHP can easily handle relative dates that are relative to other dates, such as 'Monday after November 1',
     * however it cannot handle dates that are relative to a liturgical event such as 'Monday after Pentecost'.
     * This class is used to handle relative dates that are relative to a liturgical event.
     *
     * @param string $day_of_the_week the day of the week, as a string (e.g. 'Monday')
     * @param DateRelation $relative_time whether the event is before or after the relative event (e.g. 'before' or 'after')
     * @param string $event_key the key of the event to which the relative date is relative (e.g. 'Pentecost')
     */
    private function __construct(string $day_of_the_week, DateRelation $relative_time, string $event_key)
    {
        $this->day_of_the_week = $day_of_the_week;
        $this->relative_time   = $relative_time;
        $this->event_key       = $event_key;
    }

    /**
     * Creates an instance of RelativeLiturgicalDate from a stdClass object.
     *
     * The stdClass object must have the following properties:
     * - day_of_the_week (string): the day of the week, as a string (e.g. 'Monday')
     * - relative_time (string): whether the event is before or after the relative event (e.g. 'before' or 'after')
     * - event_key (string): the key of the relative event (e.g. 'Pentecost')
     *
     * @param \stdClass $data The stdClass object containing the properties of the class.
     * @return static The newly created instance.
     * @throws \ValueError if the required properties are not present in the stdClass object.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (
            false === property_exists($data, 'day_of_the_week')
            || false === property_exists($data, 'relative_time')
            || false === property_exists($data, 'event_key')
        ) {
            throw new \ValueError('`$data->day_of_the_week`, `$data->relative_time`, and `$data->event_key` properties are required');
        }

        $dateRelation = DateRelation::from($data->relative_time);

        return new static($data->day_of_the_week, $dateRelation, $data->event_key);
    }

    /**
     * Creates an instance of RelativeLiturgicalDate from an associative array.
     *
     * The array must have the following keys:
     * - day_of_the_week (string): the day of the week, as a string (e.g. 'Monday')
     * - relative_time (string): whether the event is before or after the relative event (e.g. 'before' or 'after')
     * - event_key (string): the key of the relative event (e.g. 'Pentecost')
     *
     * @param array{day_of_the_week:string,relative_time:string,event_key:string} $data
     * @return static
     * @throws \ValueError if the keys of the data parameter do not match the expected keys.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (
            false === array_key_exists('day_of_the_week', $data)
            || false === array_key_exists('relative_time', $data)
            || false === array_key_exists('event_key', $data)
        ) {
            throw new \ValueError('`$data[\'day_of_the_week\']`, `$data[\'relative_time\']`, and `$data[\'event_key\']` keys are required');
        }

        $dateRelation = DateRelation::from($data['relative_time']);

        return new static($data['day_of_the_week'], $dateRelation, $data['event_key']);
    }

    public function __toString(): string
    {
        /*
        $dayOfTheWeekFmt = \IntlDateFormatter::create(
            LitLocale::$PRIMARY_LANGUAGE,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            'EEEE'
        );
        */
        //$relString = $this->relative_time === DateRelation::Before
        //    /**translators: e.g. 'Monday before PalmSunday' */
        //    ? _('before')
        //    /**translators: e.g. 'Monday after Pentecost' */
        //    : _('after');

        /*
        $dayOfTheWeek = LitLocale::$PRIMARY_LANGUAGE === LitLocale::LATIN_PRIMARY_LANGUAGE
            ? LatinUtils::LATIN_DAYOFTHEWEEK[$liturgicalEvent->date->format('w')]
            : ucfirst($dayOfTheWeekFmt->format($liturgicalEvent->date->format('U')));
        */
        //return sprintf('%s %s %s', $dayOfTheWeek, $relString, $litEvent->name);
        return sprintf('%s %s %s', $this->day_of_the_week, $this->relative_time->value, $this->event_key);
    }
}
