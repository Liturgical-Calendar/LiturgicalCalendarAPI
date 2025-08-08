<?php

namespace LiturgicalCalendar\Api\Models\Decrees;

final class DecreeItemSetPropertyName extends DecreeEventData
{
    private function __construct(string $event_key, string $name, string $calendar)
    {
        parent::__construct($event_key, $calendar);
        $this->name = $name;
    }

    /**
     * Creates an instance of DecreeItemSetPropertyName from an object containing the required properties.
     *
     * The stdClass object must have the following properties:
     * - `event_key` (string): The key of the event.
     * - `name` (string): The new name of the liturgical event.
     * - `calendar` (string): The calendar of the event.
     *
     * @param \stdClass&object{event_key:string,calendar:string,name:string} $data The stdClass object containing the properties of the class.
     * @return static The newly created instance.
     * @throws \ValueError if the required properties are not present in the stdClass object or if the properties have invalid types.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        $required_properties = ['event_key', 'name', 'calendar'];
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
            $data->name,
            $data->calendar
        );
    }

    /**
     * Creates a new instance from an array.
     *
     * The array must have the following keys:
     * - `event_key` (string): The event key.
     * - `name` (string): The new name of the liturgical event.
     * - `calendar` (string): The calendar.
     *
     * @param array{event_key:string,calendar:string,name:string} $data The associative array containing the properties of the class.
     * @return static A new instance.
     * @throws \ValueError If the required keys are not present in the array or have invalid values.
     */
    protected static function fromArrayInternal(array $data): static
    {
        $required_properties = ['event_key', 'name', 'calendar'];
        $current_properties  = array_keys($data);
        $missing_properties  = array_diff($required_properties, $current_properties);

        if (!empty($missing_properties)) {
            throw new \ValueError(sprintf(
                'The following properties are required: %s. Found properties: %s',
                implode(', ', $missing_properties),
                implode(', ', $current_properties)
            ));
        }

        return new static(
            $data['event_key'],
            $data['name'],
            $data['calendar']
        );
    }
}
