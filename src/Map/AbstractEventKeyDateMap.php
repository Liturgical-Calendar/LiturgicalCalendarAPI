<?php

namespace LiturgicalCalendar\Api\Map;

use LiturgicalCalendar\Api\DateTime;

/**
 * Abstract class that extends AbstractLiturgicalEventMap and implements the jsonSerialize() method.
 *
 * {@inheritDoc} When json encoding an AbstractLiturgicalEventMap object, serialize it as an array of arrays with keys
 * "event_key" and "date"
 */
class AbstractEventKeyDateMap extends AbstractLiturgicalEventMap implements \JsonSerializable
{
    /**
     * When json encoding an AbstractLiturgicalEventMap object, serialize it as an array of arrays with keys
     * "event_key" and "date"
     *
     * @return array<array{event_key: string, date: DateTime}>
     */
    public function jsonSerialize(): array
    {
        $serializedEvents = [];
        foreach ($this->eventMap as $event) {
            $serializedEvents[] = [
                'event_key' => $event->event_key,
                ...json_decode(json_encode($event->date), true)
            ];
        }
        return $serializedEvents;
    }
}
