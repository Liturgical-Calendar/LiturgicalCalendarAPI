<?php

namespace LiturgicalCalendar\Api\Models;

/**
 * Represents a collection of PropriumDeTemporeEvent objects.
 *
 * @implements \IteratorAggregate<PropriumDeTemporeEvent>
 * @implements \ArrayAccess<PropriumDeTemporeEvent>
 */
final class PropriumDeTemporeCollection extends AbstractJsonSrcDataArray implements \IteratorAggregate, \ArrayAccess
{
    /** @var array<PropriumDeTemporeEvent> */
    private readonly array $propriumDeTemporeEvents;

    /**
     * @param PropriumDeTemporeEvent[] $propriumDeTemporeEvents
     */
    private function __construct(array $propriumDeTemporeEvents)
    {
        $this->propriumDeTemporeEvents = $propriumDeTemporeEvents;
    }

    /**
     * Gets the event keys from the collection.
     *
     * @return string[] The list of event keys.
     */
    private function getKeys(): array
    {
        return array_column($this->propriumDeTemporeEvents, 'event_key');
    }

    /**
     * Sets the names of the PropriumDeTemporeEvents in the collection based on the translations provided.
     *
     * @param array<string,string> $translations The translations to use for setting the names.
     * @throws \InvalidArgumentException If some of the event keys in the collection are not present in the translations.
     */
    public function setNames(array $translations): void
    {
        $eventKeysInCollection   = $this->getKeys();
        $eventKeysInTranslations = array_keys($translations);
        $missingKeys             = array_diff($eventKeysInCollection, $eventKeysInTranslations);
        if (count($missingKeys) > 0) {
            throw new \InvalidArgumentException(sprintf(
                'The following event keys from the collection are missing from the translations: %s',
                implode(', ', $missingKeys)
            ));
        }

        foreach ($this->propriumDeTemporeEvents as $event) {
            $event->setName($translations[$event->event_key]);
        }
    }

    /**
     * @return \Traversable<PropriumDeTemporeEvent> An iterator for the items in the collection.
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->propriumDeTemporeEvents);
    }

    /**
     * Retrieves the PropriumDeTemporeEvent at the specified offset.
     *
     * @param mixed $offset The offset to retrieve the event from.
     * @return PropriumDeTemporeEvent The event at the specified offset.
     * @throws \OutOfBoundsException If the offset does not exist.
     */
    public function offsetGet($offset): PropriumDeTemporeEvent
    {
        return array_find($this->propriumDeTemporeEvents, fn ($el) => $el->event_key == $offset);
    }

    /**
     * Throws a BadMethodCallException, as PropriumDeTemporeCollection is immutable and cannot be modified.
     *
     * @param mixed $offset The offset to set the value at.
     * @param mixed $value The value to set.
     *
     * @throws \BadMethodCallException Always thrown, as PropriumDeTemporeCollection is immutable.
     */
    public function offsetSet($offset, $value): void
    {
        throw new \BadMethodCallException('PropriumDeTemporeCollection is immutable');
        //$this->propriumDeTemporeEvents[$offset] = $value;
    }

    /**
     * Throws a BadMethodCallException, as PropriumDeTemporeCollection is immutable and cannot be modified.
     *
     * @param mixed $offset The offset to unset.
     *
     * @throws \BadMethodCallException Always thrown, as PropriumDeTemporeCollection is immutable.
     */
    public function offsetUnset($offset): void
    {
        throw new \BadMethodCallException('PropriumDeTemporeCollection is immutable');
        //unset($this->propriumDeTemporeEvents[$offset]);
    }

    /**
     * Checks if an event exists at the specified offset.
     *
     * @param mixed $offset The offset to check.
     * @return bool True if the event exists, false otherwise.
     */
    public function offsetExists($offset): bool
    {
        return array_find($this->propriumDeTemporeEvents, fn ($el) => $el->event_key == $offset) !== null;
    }

    /**
     * Creates an instance of PropriumDeTemporeCollection from an array of stdClass objects.
     *
     * @param array<\stdClass> $data
     */
    protected static function fromObjectInternal(array $data): static
    {
        return new static(array_map(fn (\stdClass $event): PropriumDeTemporeEvent => PropriumDeTemporeEvent::fromObject($event), $data));
    }

    /**
     * Creates an instance of PropriumDeTemporeCollection from an array of associative arrays.
     *
     * @param array<array{event_key:string,grade:int,type:int,color:string[],readings:array{first_reading:string,responsorial_psalm:string,second_reading?:string,alleluia_verse:string,gospel:string,palm_gospel?:string,responsorial_psalm_2?:string}}> $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        return new static(array_map(fn (array $event): PropriumDeTemporeEvent => PropriumDeTemporeEvent::fromArray($event), $data));
    }
}
