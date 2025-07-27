<?php

namespace LiturgicalCalendar\Api\Models;

use LiturgicalCalendar\Api\Enum\LitGrade;

/**
 * Represents a collection of PropriumDeSanctisEvent objects.
 *
 * @implements \IteratorAggregate<string,PropriumDeSanctisEvent>
 * @implements \ArrayAccess<string,PropriumDeSanctisEvent>
 */
final class PropriumDeSanctisMap extends AbstractJsonSrcDataArray implements \IteratorAggregate, \ArrayAccess
{
    /** @var array<string,PropriumDeSanctisEvent> */
    private readonly array $propriumDeSanctisEvents;

    /**
     * @param array<string,PropriumDeSanctisEvent> $propriumDeSanctisEvents
     */
    private function __construct(array $propriumDeSanctisEvents)
    {
        $this->propriumDeSanctisEvents = $propriumDeSanctisEvents;
    }

    /**
     * Sets the names of the PropriumDeSanctisEvents in the collection based on the translations provided.
     *
     * @param array<string,string> $translations The translations to use for setting the names.
     * @throws \InvalidArgumentException If some of the event keys in the collection are not present in the translations.
     */
    public function setNames(array $translations): void
    {
        $propriumDeTemporeKeys = array_keys($this->propriumDeSanctisEvents);
        $translationKeys       = array_keys($translations);
        $missingKeys           = array_diff($propriumDeTemporeKeys, $translationKeys);
        if (count($missingKeys) > 0) {
            throw new \InvalidArgumentException(sprintf(
                'The following event keys from the collection are missing from the translations: %s',
                implode(', ', $missingKeys)
            ));
        }

        foreach ($this->propriumDeSanctisEvents as $event) {
            $event->setName($translations[$event->event_key]);
        }
    }

    /**
     * @return \Traversable<string,PropriumDeSanctisEvent> An iterator for the items in the collection.
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->propriumDeSanctisEvents);
    }

    /**
     * Retrieves the PropriumDeSanctisEvent at the specified offset.
     *
     * @param string $offset The offset to retrieve the event from.
     * @return PropriumDeSanctisEvent The event at the specified offset.
     */
    public function offsetGet($offset): PropriumDeSanctisEvent
    {
        return $this->propriumDeSanctisEvents[$offset];
    }

    /**
     * Throws a BadMethodCallException, as PropriumDeTemporeCollection is immutable and cannot be modified.
     *
     * @param string $offset The offset to set the value at.
     * @param PropriumDeSanctisEvent $value The value to set.
     *
     * @throws \BadMethodCallException Always thrown, as PropriumDeTemporeCollection is immutable.
     */
    public function offsetSet($offset, $value): void
    {
        throw new \BadMethodCallException('PropriumDeTemporeCollection is immutable');
    }

    /**
     * Throws a BadMethodCallException, as PropriumDeTemporeCollection is immutable and cannot be modified.
     *
     * @param string $offset The offset to unset.
     *
     * @throws \BadMethodCallException Always thrown, as PropriumDeTemporeCollection is immutable.
     */
    public function offsetUnset($offset): void
    {
        throw new \BadMethodCallException('PropriumDeTemporeCollection is immutable');
    }

    /**
     * Checks if an event exists at the specified offset.
     *
     * @param string $offset The offset to check.
     * @return bool True if the event exists, false otherwise.
     */
    public function offsetExists($offset): bool
    {
        return isset($this->propriumDeSanctisEvents[$offset]);
    }

    /**
     * Filters the collection to include only PropriumDeSanctisEvents that have the specified grade.
     *
     * @param LitGrade $grade The grade to filter the events by.
     * @return array<string,PropriumDeSanctisEvent> An array of PropriumDeSanctisEvents where the grade matches the specified grade.
     */
    public function filterByGrade(LitGrade $grade): array
    {
        return array_filter($this->propriumDeSanctisEvents, fn (PropriumDeSanctisEvent $event): bool => $event->grade === $grade);
    }

    /**
     * Creates an instance of PropriumDeTemporeCollection from an array of stdClass objects.
     *
     * @param array<\stdClass> $data
     * @return static
     */
    protected static function fromObjectInternal(array $data): static
    {
        $values = array_map(fn (\stdClass $event): PropriumDeSanctisEvent => PropriumDeSanctisEvent::fromObject($event), $data);
        $keys   = array_column($values, 'event_key');
        return new static(array_combine($keys, $values));
    }

    /**
     * Creates an instance of PropriumDeTemporeCollection from an array of associative arrays.
     *
     * @param array<array{event_key:string,grade:int,type:int,color:string[],readings:array{first_reading:string,responsorial_psalm:string,second_reading?:string,alleluia_verse:string,gospel:string,palm_gospel?:string,responsorial_psalm_2?:string}}> $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        $values = array_map(fn (array $event): PropriumDeSanctisEvent => PropriumDeSanctisEvent::fromArray($event), $data);
        $keys   = array_column($values, 'event_key');
        return new static(array_combine($keys, $values));
    }
}
