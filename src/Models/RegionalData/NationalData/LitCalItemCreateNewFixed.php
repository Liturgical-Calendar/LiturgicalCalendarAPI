<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitEventType;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitMassVariousNeeds;
use LiturgicalCalendar\Api\Models\Calendar\LitCommons;
use LiturgicalCalendar\Api\Models\LiturgicalEventData;

/**
 * @phpstan-type LitCalItemCreateNewFixedObject \stdClass&object{
 *      event_key:string,
 *      day:int,
 *      month:int,
 *      color:string[],
 *      grade:int,
 *      grade_display?:string|null,
 *      common:string[]
 * }
 * @phpstan-type LitCalItemCreateNewFixedArray array{
 *      event_key:string,
 *      day:int,
 *      month:int,
 *      color:string[],
 *      grade:int,
 *      grade_display?:string|null,
 *      common:string[]
 * }
 * N.B. `common` is a string array input, but will be converted to a LitCommons object or to an array of LitMassVariousNeeds enum cases upon deserialization;
 *      similarly `color` is a string array input, but will be converted to an array of LitColor enum cases upon deserialization.
 */
final class LitCalItemCreateNewFixed extends LiturgicalEventData
{
    public readonly int $day;

    public readonly int $month;

    /** @var LitColor[] */
    public readonly array $color;

    public private(set) LitGrade $grade;

    public readonly ?string $grade_display;

    /** @var LitCommons|LitMassVariousNeeds[] */
    public readonly LitCommons|array $common;

    public readonly LitEventType $type;

    public private(set) DateTime $date;


    /**
     * Creates a new LitCalItemCreateNewFixed object.
     *
     * The provided arguments must be valid. The object will be created with the provided values.
     *
     * @param string $event_key The key of the event.
     * @param int $day The day of the event.
     * @param int $month The month of the event.
     * @param LitColor[] $color The liturgical color(s) of the event.
     * @param LitGrade $grade The liturgical grade of the event.
     * @param string|null $grade_display The display string for the liturgical grade, if any.
     * @param LitCommons|LitMassVariousNeeds[] $common The liturgical common for the event.
     *
     * @throws \ValueError If the provided arguments are invalid.
     */
    private function __construct(string $event_key, int $day, int $month, array $color, LitGrade $grade, ?string $grade_display, LitCommons|array $common)
    {
        if (false === static::isValidMonthValue($month)) {
            throw new \ValueError('`$month` must be an integer between 1 and 12');
        }

        if (false === static::isValidDayValueForMonth($month, $day)) {
            throw new \ValueError("`$day` must be a valid day integer for the given month {$month}, got {$day}");
        }

        if (0 === count($color)) {
            throw new \ValueError('`$color` must be an array with at least one element');
        }

        foreach ($color as $litColor) {
            if (false === $litColor instanceof LitColor) {
                throw new \ValueError('`$color` must be an array of LitColor enum cases');
            }
        }

        parent::__construct($event_key);
        $this->day           = $day;
        $this->month         = $month;
        $this->color         = $color;
        $this->grade         = $grade;
        $this->grade_display = $grade_display;
        $this->common        = $common;
        $this->type          = LitEventType::FIXED;
    }

    /**
     * Set the date of this fixed liturgical event.
     *
     * @param DateTime $date The date of this fixed liturgical event.
     * @return void
     */
    public function setDate(DateTime $date): void
    {
        $this->unlock();
        $this->date = $date;
        $this->lock();
    }

    /**
     * Sets the liturgical grade of this fixed liturgical event.
     *
     * @param LitGrade $grade The liturgical grade of this fixed liturgical event.
     * @return void
     */
    public function setGrade(LitGrade $grade): void
    {
        $this->unlock();
        $this->grade = $grade;
        $this->lock();
    }

    /**
     * Creates an instance of LitCalItemCreateNewFixed from an object containing the required properties.
     *
     * The stdClass object must have the following properties:
     * - event_key (string): the key of the event
     * - day (int): the day of the event
     * - month (int): the month of the event
     * - color (string[]): the liturgical color(s) of the event, as an array of strings
     * - grade (int): the liturgical grade of the event
     * - common (string[]): the liturgical common of the event, as an array of strings
     *
     * @param LitCalItemCreateNewFixedObject $data The stdClass object containing the properties of the class.
     * @return static The newly created instance.
     * @throws \ValueError if the required properties are not present in the stdClass object or if the properties have invalid types.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        $commons = LitCommons::create($data->common) ?? array_values(array_map(
            function (string $value): LitMassVariousNeeds {
                return LitMassVariousNeeds::from($value);
            },
            $data->common
        ));

        $grade_display = null;
        if (property_exists($data, 'grade_display')) {
            if (!is_string($data->grade_display) && null !== $data->grade_display) {
                throw new \ValueError('invalid grade_display: expected a string or null');
            }
            $grade_display = $data->grade_display;
        }

        return new static(
            $data->event_key,
            $data->day,
            $data->month,
            array_values(array_map(
                function (string $color) {
                    return LitColor::from($color);
                },
                $data->color
            )),
            LitGrade::from($data->grade),
            $grade_display,
            $commons,
        );
    }

    /**
     * Creates an instance of LitCalItemCreateNewFixed from an associative array.
     *
     * The array must have the following keys:
     * - event_key (string): the key of the event
     * - day (int): the day of the event
     * - month (int): the month of the event
     * - color (string[]): the liturgical color(s) of the event, as an array of strings
     * - grade (int): the liturgical grade of the event
     * - common (string[]): the liturgical common of the event, as an array of strings
     *
     * @param LitCalItemCreateNewFixedArray $data The associative array containing the properties of the class.
     * @return static The newly created instance.
     * @throws \ValueError if the keys of the data parameter do not match the expected keys.
     */
    protected static function fromArrayInternal(array $data): static
    {
        $commons = LitCommons::create($data['common']) ?? array_values(array_map(
            function (string $value): LitMassVariousNeeds {
                return LitMassVariousNeeds::from($value);
            },
            $data['common']
        ));

        $grade_display = null;
        if (array_key_exists('grade_display', $data)) {
            if (!is_string($data['grade_display']) && null !== $data['grade_display']) {
                throw new \ValueError('invalid grade_display: expected a string or null');
            }
            $grade_display = $data['grade_display'];
        }

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
            LitGrade::from($data['grade']),
            $grade_display,
            $commons
        );
    }
}
