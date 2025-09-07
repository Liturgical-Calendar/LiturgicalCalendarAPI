<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Models\LiturgicalEventData;

final class LitCalItemSetPropertyName extends LiturgicalEventData
{
    private function __construct(string $event_key, string $name)
    {
        parent::__construct($event_key);
        $this->name = $name;
    }

    /**
     * Creates a new instance from an array.
     *
     * The array must have the following keys:
     * - `event_key` (string): The event key.
     * - `name` (string): The new name of the liturgical event.
     *
     * @param array{event_key:string,name:string} $data The associative array containing the properties of the class.
     * @return static A new instance.
     * @throws \ValueError If the required keys are not present in the array or have invalid values.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (false === array_key_exists('event_key', $data) || false === array_key_exists('name', $data)) {
            throw new \ValueError('`liturgical_event->event_key` and `liturgical_event->name` parameters are required for a `metadata->action` of `setProperty` and when the property is `name`');
        }
        return new static($data['event_key'], $data['name']);
    }

    /**
     * Creates an instance of LitCalItemSetPropertyName from an object containing the required properties.
     *
     * The stdClass object must have the following properties:
     * - `event_key` (string): The key of the event.
     * - `name` (string): The new name of the liturgical event.
     *
     * @param \stdClass&object{event_key:string,name:string} $data The stdClass object containing the properties of the class.
     * @return static The newly created instance.
     * @throws \ValueError if the required properties are not present in the stdClass object or if the properties have invalid types.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'event_key') || false === property_exists($data, 'name')) {
            throw new \ValueError('`liturgical_event->event_key` and `liturgical_event->name` properties are required for a `metadata->action` of `setProperty` and when the property is `name`');
        }
        return new static($data->event_key, $data->name);
    }
}
