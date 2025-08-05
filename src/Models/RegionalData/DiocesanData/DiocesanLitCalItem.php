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

    /**
     * Creates an instance of DiocesanLitCalItem from an object containing the required properties.
     *
     * The object must have the following properties:
     * - liturgical_event (object): The liturgical event data.
     * - metadata (object): The metadata for the liturgical event.
     *
     * @param \stdClass $data The object containing the properties of the class.
     * @return static A new instance of the class.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'liturgical_event') || false === property_exists($data, 'metadata')) {
            throw new \ValueError('`liturgical_event` and `metadata` parameters are required');
        }

        return new static($data->liturgical_event, $data->metadata);
    }

    /**
     * Creates an instance of DiocesanLitCalItem from an associative array.
     *
     * The array must have the following keys:
     * - liturgical_event (array): The liturgical event data.
     * - metadata (array): The metadata for the liturgical event.
     *
     * @param array{liturgical_event:array{event_key?:string,name?:string,grade?:int,color?:string[],common?:string[],day?:int,month?:int,strtotime?:string},metadata:array{action?:string,since_year?:int|null,until_year?:int|null} } $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (false === array_key_exists('liturgical_event', $data) || false === array_key_exists('metadata', $data)) {
            throw new \ValueError('`liturgical_event` and `metadata` parameters are required');
        }
        $liturgicalEvent = json_encode($data['liturgical_event'], JSON_THROW_ON_ERROR);
        $metadata        = json_encode($data['metadata'], JSON_THROW_ON_ERROR);

        /** @var \stdClass */
        $liturgicalEvent = json_decode($liturgicalEvent, true, 512, JSON_THROW_ON_ERROR);

        /** @var \stdClass */
        $metadata = json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
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

    public function setKey(string $key): void
    {
        $this->unlock();
        $this->liturgical_event->setKey($key);
        $this->lock();
    }
}
