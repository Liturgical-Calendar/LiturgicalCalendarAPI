<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\DiocesanData;

use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;

final class DiocesanLitCalItem extends AbstractJsonSrcData
{
    public readonly LitCalItemCreateNewFixed|LitCalItemCreateNewMobile $liturgical_event;
    public readonly DiocesanLitCalItemMetadata $metadata;

    private function __construct(\stdClass $liturgical_event, \stdClass $metadata)
    {
        if (false === property_exists($liturgical_event, 'event_key')) {
            throw new \ValueError('litcalItem.liturgical_event must have an `event_key` property');
        }

        if (property_exists($metadata, 'strtotime')) {
            $this->liturgical_event = LitCalItemCreateNewMobile::fromObject($liturgical_event);
        } else {
            $this->liturgical_event = LitCalItemCreateNewFixed::fromObject($liturgical_event);
        }
        $this->metadata = DiocesanLitCalItemMetadata::fromObject($metadata);
    }

    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'liturgical_event') || false === property_exists($data, 'metadata')) {
            throw new \ValueError('`liturgical_event` and `metadata` parameters are required');
        }

        return new static($data->liturgical_event, $data->metadata);
    }

    protected static function fromArrayInternal(array $data): static
    {
        if (false === array_key_exists('liturgical_event', $data) || false === array_key_exists('metadata', $data)) {
            throw new \ValueError('`liturgical_event` and `metadata` parameters are required');
        }
        $liturgicalEvent = json_encode($data['liturgical_event']);
        $metadata        = json_encode($data['metadata']);
        if (false === $liturgicalEvent || false === $metadata) {
            throw new \ValueError('`liturgical_event` or `metadata` parameter could not be re-encoded to JSON');
        }
        /** @var \stdClass */
        $liturgicalEvent = json_decode($liturgicalEvent);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \ValueError('`liturgical_event` parameter could not be re-encoded to JSON: ' . json_last_error_msg());
        }
        /** @var \stdClass */
        $metadata = json_decode($metadata);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \ValueError('`metadata` parameter could not be re-encoded to JSON: ' . json_last_error_msg());
        }
        return new static($liturgicalEvent, $metadata);
    }

    /**
     * Set the name of the liturgical event.
     *
     * @param string $name The new name for the liturgical event.
     */
    public function setName(string $name): void
    {
        $this->unlock();
        $this->liturgical_event->name = $name;
        $this->lock();
    }

    /**
     * Return the event_key of the liturgical event.
     *
     * @return string The event_key of the liturgical event.
     */
    public function getEventKey(): string
    {
        return $this->liturgical_event->event_key;
    }
}
