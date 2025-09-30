<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitEventType;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\Calendar\LitCommons;
use LiturgicalCalendar\Api\Models\ConditionalRule;
use LiturgicalCalendar\Api\Models\LiturgicalEventData;

/**
 * @phpstan-type LitCalItemCreateNewFixedObject \stdClass&object{event_key:string,day:int,month:int,color:string[],grade:int,common:string[],rules?:\stdClass[]}
 * @phpstan-type LitCalItemCreateNewFixedArray array{event_key:string,day:int,month:int,color:string[],grade:int,common:string[],rules?:array[]}
 */
final class LitCalItemCreateNewFixed extends LiturgicalEventData
{
    public readonly int $day;

    public readonly int $month;

    /** @var LitColor[] */
    public readonly array $color;

    public private(set) LitGrade $grade;

    public readonly LitCommons $common;

    public readonly LitEventType $type;

    public private(set) DateTime $date;

    /** @var ConditionalRule[] */
    public readonly array $rules;

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
     * @param LitCommons $common The liturgical common of the event.
     *
     * @throws \ValueError If the provided arguments are invalid.
     */
    private function __construct(string $event_key, int $day, int $month, array $color, LitGrade $grade, LitCommons $common, array $rules = [])
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

        foreach ($rules as $rule) {
            if (false === $rule instanceof ConditionalRule) {
                throw new \ValueError('`$rules` must be an array of ConditionalRule instances');
            }
        }

        parent::__construct($event_key);
        $this->day    = $day;
        $this->month  = $month;
        $this->color  = $color;
        $this->grade  = $grade;
        $this->common = $common;
        $this->type   = LitEventType::FIXED;
        $this->rules  = $rules;
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
        $commons = LitCommons::create($data->common);

        if (null === $commons) {
            throw new \ValueError('invalid common: expected an array of LitCommon enum cases, LitCommon enum values, or LitMassVariousNeeds instances');
        }

        $rules = [];
        if (property_exists($data, 'rules') && is_array($data->rules)) {
            foreach ($data->rules as $ruleData) {
                $rules[] = ConditionalRule::fromObject($ruleData);
            }
        }

        return new static(
            $data->event_key,
            $data->day,
            $data->month,
            array_map(
                function (string $color) {
                    return LitColor::from($color);
                },
                $data->color
            ),
            LitGrade::from($data->grade),
            $commons,
            $rules
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
        $commons = LitCommons::create($data['common']);

        if (null === $commons) {
            throw new \ValueError('invalid common: expected an array of LitCommon enum cases, LitCommon enum values, or LitMassVariousNeeds instances');
        }

        $rules = [];
        if (array_key_exists('rules', $data) && is_array($data['rules'])) {
            foreach ($data['rules'] as $ruleData) {
                $rules[] = ConditionalRule::fromObject((object) $ruleData);
            }
        }

        return new static(
            $data['event_key'],
            $data['day'],
            $data['month'],
            array_map(
                function (string $color): LitColor {
                    return LitColor::from($color);
                },
                $data['color']
            ),
            LitGrade::from($data['grade']),
            $commons,
            $rules
        );
    }
}
