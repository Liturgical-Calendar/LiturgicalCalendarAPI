<?php

namespace LiturgicalCalendar\Api\Models\Decrees;

use LiturgicalCalendar\Api\Models\Calendar\LitCommons;

final class DecreeItemMakeDoctor extends DecreeEventData
{
    public readonly LitCommons $common;

    private function __construct(string $event_key, string $name, string $calendar, LitCommons $common)
    {

        parent::__construct($event_key, $calendar);
        $this->name   = $name;
        $this->common = $common;
    }

    /**
     * Creates an instance of LitCalItemMakePatron from a stdClass object.
     *
     * The stdClass object must have the following properties:
     * - event_key (string): The key of the event.
     * - common (array): The liturgical common of the event.
     *
     * @param \stdClass $data The stdClass object containing the properties of the class.
     * @return static The newly created instance.
     * @throws \ValueError if the required properties are not present in the stdClass object or if the properties have invalid types.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        $required_properties = ['event_key', 'name', 'calendar', 'common'];
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
            $data->calendar,
            LitCommons::create($data->common)
        );
    }

    /**
     * Creates an instance of LitCalItemMakePatron from an associative array.
     *
     * The array must have the following keys:
     * - event_key (string): The key of the event.
     * - name (string): The name of the event.
     * - calendar (string): The calendar of the event.
     * - common (array): The liturgical common of the event.
     *
     * @param array{event_key:string,name:string,calendar:string,common:string[]} $data The associative array containing the properties of the class.
     * @return static The newly created instance.
     * @throws \ValueError if the required keys are not present in the array or have invalid values.
     */
    protected static function fromArrayInternal(array $data): static
    {
        $required_properties = ['event_key', 'name', 'calendar', 'common'];
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
            $data['calendar'],
            LitCommons::create($data['common'])
        );
    }
}
