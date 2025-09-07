<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\LiturgicalEventData;

final class LitCalItemMakePatron extends LiturgicalEventData
{
    public readonly LitGrade $grade;

    private function __construct(string $event_key, LitGrade $grade)
    {

        parent::__construct($event_key);

        $this->grade = $grade;
    }

    /**
     * Creates an instance of LitCalItemMakePatron from a stdClass object.
     *
     * The stdClass object must have the following properties:
     * - event_key (string): The key of the event.
     * - grade (integer): The liturgical grade of the event.
     *
     * @param \stdClass&object{event_key:string,grade:int} $data The stdClass object containing the properties of the class.
     * @return static The newly created instance.
     * @throws \ValueError if the required properties are not present in the stdClass object or if the properties have invalid types.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'event_key') || false === property_exists($data, 'grade')) {
            throw new \ValueError('`event_key` and `grade` properties are required');
        }

        return new static($data->event_key, LitGrade::from($data->grade));
    }

    /**
     * Creates an instance of LitCalItemMakePatron from an associative array.
     *
     * The array must have the following keys:
     * - event_key (string): The key of the event.
     * - grade (integer): The liturgical grade of the event.
     *
     * @param array{event_key:string,grade:int} $data The associative array containing the properties of the class.
     * @return static The newly created instance.
     * @throws \ValueError if the required keys are not present in the array or have invalid values.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (false === array_key_exists('event_key', $data) || false === array_key_exists('grade', $data)) {
            throw new \ValueError('`event_key` and `grade` parameters are required');
        }

        return new static($data['event_key'], LitGrade::from($data['grade']));
    }
}
