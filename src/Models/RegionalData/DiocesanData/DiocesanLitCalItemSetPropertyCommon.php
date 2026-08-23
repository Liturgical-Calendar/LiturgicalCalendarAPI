<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\DiocesanData;

use LiturgicalCalendar\Api\Models\Calendar\LitCommons;
use LiturgicalCalendar\Api\Models\LiturgicalEventData;

/**
 * A diocesan `setProperty:common` item: replaces the Common of an event that already exists in
 * the calendar. Used where a suffragan diocese celebrates a comune feast as `Proper`.
 */
final class DiocesanLitCalItemSetPropertyCommon extends LiturgicalEventData
{
    public readonly LitCommons $common;

    private function __construct(string $event_key, LitCommons $common)
    {
        parent::__construct($event_key);
        $this->common = $common;
    }

    /**
     * @param \stdClass&object{event_key:string,common:string[]} $data
     * @return static
     * @throws \ValueError if `event_key` or `common` is missing or invalid.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'event_key') || false === property_exists($data, 'common')) {
            throw new \ValueError('`liturgical_event->event_key` and `liturgical_event->common` are required for a `metadata->action` of `setProperty` when the property is `common`');
        }
        $commons = LitCommons::create($data->common);
        if (null === $commons) {
            throw new \ValueError('invalid common: expected an array of LitCommon enum cases, LitCommon enum values, or LitMassVariousNeeds instances');
        }
        return new static($data->event_key, $commons);
    }

    /**
     * @param array{event_key:string,common:string[]} $data
     * @return static
     * @throws \ValueError if `event_key` or `common` is missing or invalid.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (false === array_key_exists('event_key', $data) || false === array_key_exists('common', $data)) {
            throw new \ValueError('`liturgical_event->event_key` and `liturgical_event->common` are required for a `metadata->action` of `setProperty` when the property is `common`');
        }
        $commons = LitCommons::create($data['common']);
        if (null === $commons) {
            throw new \ValueError('invalid common: expected an array of LitCommon enum cases, LitCommon enum values, or LitMassVariousNeeds instances');
        }
        return new static($data['event_key'], $commons);
    }
}
