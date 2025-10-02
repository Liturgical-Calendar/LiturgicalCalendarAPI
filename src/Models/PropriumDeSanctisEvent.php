<?php

namespace LiturgicalCalendar\Api\Models;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitEventType;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitMassVariousNeeds;
use LiturgicalCalendar\Api\Models\Calendar\LitCommons;

final class PropriumDeSanctisEvent extends AbstractJsonSrcData
{
    private const REQUIRED_PROPS = [
        'event_key',
        'day',
        'month',
        'color',
        'common',
        'grade'
    ];

    public readonly string $event_key;
    public readonly int $day;
    public readonly int $month;
    /** @var LitColor[] $color */
    public readonly array $color;
    /** @var LitCommons|LitMassVariousNeeds[] $common */
    public readonly LitCommons|array $common;
    public private(set) LitGrade $grade;
    public readonly ?string $grade_display;
    public readonly LitEventType $type;
    public readonly string $calendar;
    public private(set) DateTime $date;
    public private(set) string $name;
    public private(set) string $decree;
    public private(set) int $since_year;

    /**
     * Constructor for the PropriumDeSanctisEvent class.
     *
     * @param string $event_key The key identifying the event.
     * @param int $day The day of the month when the event occurs.
     * @param int $month The month when the event occurs.
     * @param LitColor[] $color An array of liturgical colors associated with the event.
     * @param LitCommons|LitMassVariousNeeds[] $common The liturgical common for the event.
     * @param LitGrade $grade The liturgical grade of the event.
     * @param LitEventType $type The type of the event, with a default value of LitEventType::FIXED.
     * @param string $calendar The calendar for the event, with a default value of 'GENERAL ROMAN'.
     */
    public function __construct(
        string $event_key,
        int $day,
        int $month,
        array $color,
        LitCommons|array $common,
        LitGrade $grade,
        ?string $grade_display,
        LitEventType $type = LitEventType::FIXED,
        string $calendar = 'GENERAL ROMAN'
    ) {
        $this->event_key     = $event_key;
        $this->day           = $day;
        $this->month         = $month;
        $this->color         = $color;
        $this->common        = $common;
        $this->grade         = $grade;
        $this->grade_display = $grade_display;
        $this->type          = $type;
        $this->calendar      = $calendar;
    }

    /**
     * Sets the name of the PropriumDeSanctisEvent.
     *
     * @param string $name The name to set for the event.
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Sets the liturgical grade of the PropriumDeSanctisEvent.
     *
     * @param LitGrade $grade The new grade to set for the event.
     * @return void
     */
    public function setGrade(LitGrade $grade): void
    {
        $this->grade = $grade;
    }

    /**
     * Sets the date for the PropriumDeSanctisEvent.
     *
     * @param DateTime $date The date to set for the event.
     * @return void
     */
    public function setDate(DateTime $date): void
    {
        $this->date = $date;
    }

    /**
     * Sets the decree that introduced the PropriumDeSanctisEvent in the calendar,
     * or modified it in some way.
     *
     * @param string $decree The decree that introduced or modified the event.
     * @return void
     */
    public function setDecree(string $decree): void
    {
        $this->decree = $decree;
    }

    /**
     * Sets the year since which the PropriumDeSanctisEvent has been applicable or modified.
     *
     * Usually would be the year the Missal was published.
     *
     * @param int $since_year The year to set as the starting point for the event's applicability.
     * @return void
     */
    public function setSinceYear(int $since_year): void
    {
        $this->since_year = $since_year;
    }

    /**
     * Creates an instance of PropriumDeSanctisEvent from an associative array.
     *
     * The array should have the following keys:
     * - event_key (string): The key of the event
     * - day (int): The day of the event
     * - month (int): The month of the event
     * - color (string[]): The liturgical color(s) of the event, as an array of strings
     * - common (string[]): The liturgical common of the event, as an array of strings
     * - grade (int): The liturgical grade of the event
     *
     * @param array{event_key:string,day:int,month:int,color:string[],common:string[],grade:int,grade_display:?string,type:?string,calendar:?string} $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        static::validateRequiredKeys($data, static::REQUIRED_PROPS);

        $litCommons = LitCommons::create($data['common']) ?? array_values(array_map(
            function (string $value): LitMassVariousNeeds {
                return LitMassVariousNeeds::from($value);
            },
            $data['common']
        ));

        return new static(
            $data['event_key'],
            $data['day'],
            $data['month'],
            array_values(array_map(
                function (string $color): LitColor {
                    return LitColor::from($color);
                },
                $data['color']
            )),
            $litCommons,
            LitGrade::from($data['grade']),
            isset($data['grade_display']) ? $data['grade_display'] : null,
            isset($data['type']) ? LitEventType::from($data['type']) : LitEventType::FIXED,
            isset($data['calendar']) ? $data['calendar'] : 'GENERAL ROMAN'
        );
    }

    /**
     * Creates an instance of the class from a stdClass object.
     *
     * The stdClass object must have the following properties:
     * - event_key (string): The event key.
     * - day (int): The day of the event.
     * - month (int): The month of the event.
     * - color (array): The liturgical colors for the event.
     * - common (array): The liturgical common for the event.
     * - grade (int): The liturgical grade of the event.
     * - grade_display (string): The liturgical grade display of the event.
     *
     * @param \stdClass&object{event_key:string,day:int,month:int,color:string[],common:string[],grade:int,grade_display:?string,type:?string,calendar:?string} $data The stdClass object or array containing event data.
     * @return static The newly created instance(s).
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        static::validateRequiredProps($data, static::REQUIRED_PROPS);

        $litCommons = LitCommons::create($data->common) ?? array_values(array_map(
            function (string $value): LitMassVariousNeeds {
                return LitMassVariousNeeds::from($value);
            },
            $data->common
        ));

        return new static(
            $data->event_key,
            $data->day,
            $data->month,
            array_values(array_map(
                function (string $color): LitColor {
                    return LitColor::from($color);
                },
                $data->color
            )),
            $litCommons,
            LitGrade::from($data->grade),
            isset($data->grade_display) ? $data->grade_display : null,
            isset($data->type) ? LitEventType::from($data->type) : LitEventType::FIXED,
            isset($data->calendar) ? $data->calendar : 'GENERAL ROMAN'
        );
    }
}
