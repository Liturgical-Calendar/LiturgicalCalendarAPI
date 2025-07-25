<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\LiturgicalEventData;

final class LitCalItemSetPropertyGrade extends LiturgicalEventData
{
    public readonly LitGrade $grade;

    private function __construct(string $event_key, LitGrade $grade)
    {
        parent::__construct($event_key);
        $this->grade = $grade;
    }

    /**
     * Creates an instance of LitCalItemSetPropertyGrade from an object containing the required properties.
     *
     * The stdClass object must have the following properties:
     * - event_key (string): The key of the event.
     * - grade (int|string): The grade of the liturgical event.
     *
     * @param \stdClass $data The stdClass object containing the properties of the class.
     * @return static The newly created instance.
     * @throws \ValueError if the required properties are not present in the stdClass object or if the properties have invalid types.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'event_key') || false === property_exists($data, 'grade')) {
            throw new \ValueError('`$liturgical_event->event_key` and `$liturgical_event->grade` properties are required for a `$metadata->action` of `setProperty` and when the property is `grade`');
        }
        return new static($data->event_key, LitGrade::from($data->grade));
    }

    /**
     * Creates a new instance from an array.
     *
     * The array must have the following keys:
     * - `event_key` (string): The event key.
     * - `grade` (string): The grade of the liturgical event.
     *
     * @param array{event_key:string,grade:string} $data The data to use to create the new instance.
     * @return static A new instance.
     * @throws \ValueError If the required keys are not present in the array or have invalid values.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (false === array_key_exists('event_key', $data) || false === array_key_exists('grade', $data)) {
            throw new \ValueError('`liturgical_event->event_key` and `liturgical_event->grade` parameters are required for a `metadata->action` of `setProperty` and when the property is `grade`');
        }
        return new static($data['event_key'], LitGrade::from($data['grade']));
    }
}
