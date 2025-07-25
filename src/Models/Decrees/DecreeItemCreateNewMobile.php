<?php

namespace LiturgicalCalendar\Api\Models\Decrees;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitEventType;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\Calendar\LitCommons;
use LiturgicalCalendar\Api\Models\RelativeLiturgicalDate;

final class DecreeItemCreateNewMobile extends DecreeEventData
{
    public readonly string|RelativeLiturgicalDate $strtotime;

    /** @var LitColor[] */
    public readonly array $color;

    public private(set) LitGrade $grade;

    public readonly LitCommons $common;

    public readonly LitEventType $type;

    public private(set) DateTime $date;

    /**
     * @param string $event_key The key of the liturgical event.
     * @param string $name The name of the liturgical event.
     * @param string $calendar The calendar of the liturgical event.
     * @param string|RelativeLiturgicalDate $strtotime A strtotime string or an object representing a RelativeLiturgicalDate.
     * @param LitColor[] $color An array of LitColor enum cases.
     * @param LitGrade $grade The liturgical grade of the event.
     * @param LitCommons $common The liturgical commons of the event.
     *
     * @throws \ValueError if the required properties are not present in the stdClass object or if the properties have invalid types.
     */
    private function __construct(string $event_key, string $name, string $calendar, string|RelativeLiturgicalDate $strtotime, array $color, LitGrade $grade, LitCommons $common)
    {

        if (0 === count($color)) {
            throw new \ValueError('`$color` must be an array with at least one element');
        }

        foreach ($color as $litColor) {
            if (false === $litColor instanceof LitColor) {
                throw new \ValueError('`$color` must be an array of LitColor enum cases');
            }
        }

        parent::__construct($event_key, $calendar);
        $this->name      = $name;
        $this->strtotime = $strtotime;
        $this->color     = $color;
        $this->grade     = $grade;
        $this->common    = $common;
        $this->type      = LitEventType::MOBILE;
    }

    /**
     * Sets the date of this mobile liturgical event.
     *
     * @param DateTime $date The date of the mobile liturgical event.
     * @return void
     */
    public function setDate(DateTime $date): void
    {
        $this->unlock();
        $this->date = $date;
        $this->lock();
    }

    /**
     * Sets the liturgical grade of this mobile liturgical event.
     *
     * @param LitGrade $grade The liturgical grade of this mobile liturgical event.
     * @return void
     */
    public function setGrade(LitGrade $grade): void
    {
        $this->unlock();
        $this->grade = $grade;
        $this->lock();
    }

    /**
     * Creates an instance of LitCalItemCreateNewMobile from an object containing the required properties.
     *
     * The stdClass object must have the following properties:
     * - event_key (string): the key of the event
     * - calendar (string): the calendar of the event
     * - strtotime (string|object): the strtotime string or an object containing the properties of a RelativeLiturgicalDate
     * - color (array): the liturgical color(s) of the event, as an array of strings
     * - grade (integer): the liturgical grade of the event
     * - common (array): the liturgical common of the event, as an array of strings
     *
     * @param \stdClass $data The stdClass object containing the properties of the class.
     * @return static The newly created instance.
     * @throws \ValueError if the required properties are not present in the stdClass object or if the properties have invalid types.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        $required_properties = ['event_key', 'name', 'calendar', 'strtotime', 'color', 'grade', 'common'];
        $current_properties  = array_keys(get_object_vars($data));
        $missing_properties  = array_diff($required_properties, $current_properties);

        if (!empty($missing_properties)) {
            throw new \ValueError(sprintf(
                'The following properties are required: %s. Found properties: %s',
                implode(', ', $missing_properties),
                implode(', ', $current_properties)
            ));
        }

        /** @var string|RelativeLiturgicalDate $strToTime */
        $strToTime = '';
        if (is_string($data->strtotime)) {
            if ('' === $data->strtotime) {
                throw new \ValueError('When `$strtotime` is of type string, it must not be empty');
            }
            $strToTime = $data->strtotime;
        } elseif ($data->strtotime instanceof \stdClass) {
            $strToTime = RelativeLiturgicalDate::fromObject($data->strtotime);
        }

        return new static(
            $data->event_key,
            $data->name,
            $data->calendar,
            $strToTime,
            array_map(fn($color) => LitColor::from($color), $data->color),
            LitGrade::from($data->grade),
            LitCommons::create($data->common)
        );
    }

    /**
     * Creates an instance of LitCalItemCreateNewMobile from an associative array.
     *
     * The array must have the following keys:
     * - event_key (string): The key of the event.
     * - name (string): The name of the event.
     * - calendar (string): The calendar of the event.
     * - strtotime (string|array): The strtotime string or an array representing a RelativeLiturgicalDate.
     * - color (array): The liturgical color(s) of the event, as an array of strings.
     * - grade (integer): The liturgical grade of the event.
     * - common (array): The liturgical common of the event, as an array of strings.
     *
     * @param array{event_key:string,name:string,calendar:string,strtotime:string|array{day_of_the_week:string,relative_time:string,event_key:string},color:string[],grade:int,common:string[]} $data The associative array containing the properties of the class.
     * @return static The newly created instance.
     * @throws \ValueError if the required keys are not present in the array or have invalid values.
     */
    protected static function fromArrayInternal(array $data): static
    {
        $required_properties = ['event_key', 'name', 'calendar', 'strtotime', 'color', 'grade', 'common'];
        $current_properties  = array_keys($data);
        $missing_properties  = array_diff($required_properties, $current_properties);

        if (!empty($missing_properties)) {
            throw new \ValueError(sprintf(
                'The following properties are required: %s. Found properties: %s',
                implode(', ', $missing_properties),
                implode(', ', $current_properties)
            ));
        }

        /** @var string|RelativeLiturgicalDate $strToTime */
        $strToTime = '';
        if (is_string($data['strtotime'])) {
            if ('' === $data['strtotime']) {
                throw new \ValueError('When `$strtotime` is of type string, it must not be empty');
            }
            $strToTime = $data['strtotime'];
        } elseif (is_array($data['strtotime'])) {
            $strToTime = RelativeLiturgicalDate::fromArray($data['strtotime']);
        }

        return new static(
            $data['event_key'],
            $data['name'],
            $data['calendar'],
            $strToTime,
            array_map(fn($color) => LitColor::from($color), $data['color']),
            LitGrade::from($data['grade']),
            LitCommons::create($data['common'])
        );
    }
}
