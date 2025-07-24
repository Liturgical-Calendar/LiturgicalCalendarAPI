<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Models\RegionalData\LiturgicalEventData;

final class LitCalItemSetPropertyName extends LiturgicalEventData
{
    private function __construct(string $event_key, string $name)
    {
        parent::__construct($event_key);
        $this->name = $name;
    }

    protected static function fromArrayInternal(array $data): static
    {
        if (false === array_key_exists('event_key', $data) || false === array_key_exists('name', $data)) {
            throw new \ValueError('`liturgical_event->event_key` and `liturgical_event->name` parameters are required for a `metadata->action` of `setProperty` and when the property is `name`');
        }
        return new static($data['event_key'], $data['name']);
    }

    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'event_key') || false === property_exists($data, 'name')) {
            throw new \ValueError('`liturgical_event->event_key` and `liturgical_event->name` properties are required for a `metadata->action` of `setProperty` and when the property is `name`');
        }
        return new static($data->event_key, $data->name);
    }
}
