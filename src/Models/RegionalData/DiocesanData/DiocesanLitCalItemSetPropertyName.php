<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\DiocesanData;

use LiturgicalCalendar\Api\Models\LiturgicalEventData;

/**
 * A diocesan `setProperty:name` item.
 *
 * Diocesan calendars keep their names in the per-locale i18n tree, not in the calendar row, so
 * this item carries only an `event_key`. `DiocesanData::setNames()` stamps the inherited `$name`
 * property from the i18n file before the overlay runs.
 */
final class DiocesanLitCalItemSetPropertyName extends LiturgicalEventData
{
    private function __construct(string $event_key)
    {
        parent::__construct($event_key);
    }

    /**
     * @param \stdClass&object{event_key:string} $data
     * @return static
     * @throws \ValueError if `event_key` is missing.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'event_key')) {
            throw new \ValueError('`liturgical_event->event_key` is required for a `metadata->action` of `setProperty` when the property is `name`');
        }
        return new static($data->event_key);
    }

    /**
     * @param array{event_key:string} $data
     * @return static
     * @throws \ValueError if `event_key` is missing.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (false === array_key_exists('event_key', $data)) {
            throw new \ValueError('`liturgical_event->event_key` is required for a `metadata->action` of `setProperty` when the property is `name`');
        }
        return new static($data['event_key']);
    }
}
