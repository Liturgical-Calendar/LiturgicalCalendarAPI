<?php

namespace LiturgicalCalendar\Api\Models\Decrees;

use LiturgicalCalendar\Api\Enum\LitGrade;

final class DecreeItemSetPropertyGrade extends DecreeEventData
{
    public readonly LitGrade $grade;

    private function __construct(string $event_key, string $calendar, LitGrade $grade)
    {
        parent::__construct($event_key, $calendar);
        $this->grade = $grade;
    }

    /**
     * Creates an instance of LitCalItemSetPropertyGrade from an object containing the required properties.
     *
     * The stdClass object must have the following properties:
     * - event_key (string): The key of the event.
     * - grade (int|string): The liturgical grade of the event.
     *
     * @param \stdClass $data The stdClass object containing the properties of the class.
     * @return static The newly created instance(s).
     * @throws \ValueError if the required properties are not present in the stdClass object or if the properties have invalid types.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        $required_properties = ['event_key', 'calendar', 'grade'];
        $current_properties  = array_keys(get_object_vars($data));
        $missing_properties  = array_diff($required_properties, $current_properties);

        if (!empty($missing_properties)) {
            throw new \ValueError(sprintf(
                'The following properties are required: %s. Found properties: %s',
                implode(', ', $missing_properties),
                implode(', ', $current_properties)
            ));
        }

        return new static(
            $data->event_key,
            $data->calendar,
            LitGrade::from($data->grade)
        );
    }

    /**
     * Creates a new instance from an array.
     *
     * The array must have the following keys:
     * - `event_key` (string): The event key.
     * - `calendar` (string): The calendar.
     * - `grade` (string): The grade of the liturgical event.
     *
     * @param array{event_key:string,calendar:string,grade:string} $data The data to use to create the new instance.
     * @return static A new instance.
     * @throws \ValueError If the required keys are not present in the array or have invalid values.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (
            false === array_key_exists('event_key', $data)
            || false === array_key_exists('calendar', $data)
            || false === array_key_exists('grade', $data)
        ) {
            throw new \ValueError('`liturgical_event->event_key`, `liturgical_event->calendar` and `liturgical_event->grade` parameters are required for a `metadata->action` of `setProperty` and when the property is `grade`');
        }
        return new static(
            $data['event_key'],
            $data['calendar'],
            LitGrade::from($data['grade'])
        );
    }
}
