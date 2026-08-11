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
                'The following sanctorale liturgical event keys were lost in translation: %s',
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
     * Throws a BadMethodCallException, as PropriumDeSanctisMap is immutable and cannot be modified.
     *
     * @param string $offset The offset to set the value at.
     * @param PropriumDeSanctisEvent $value The value to set.
     *
     * @throws \BadMethodCallException Always thrown, as PropriumDeSanctisMap is immutable.
     */
    public function offsetSet($offset, $value): void
    {
        throw new \BadMethodCallException('PropriumDeSanctisMap is immutable');
    }

    /**
     * Throws a BadMethodCallException, as PropriumDeSanctisMap is immutable and cannot be modified.
     *
     * @param string $offset The offset to unset.
     *
     * @throws \BadMethodCallException Always thrown, as PropriumDeSanctisMap is immutable.
     */
    public function offsetUnset($offset): void
    {
        throw new \BadMethodCallException('PropriumDeSanctisMap is immutable');
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
     * Creates an instance of PropriumDeSanctisMap from an array of stdClass objects.
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
     * Creates an instance of PropriumDeSanctisMap from an array of associative arrays.
     *
     * Each element is handed to {@see PropriumDeSanctisEvent::fromArray()}, so this shape mirrors
     * that method's: `day`, `month` and `common` are required alongside the rest, and `readings`
     * is not among the keys it reads.
     *
     * @param array<array{event_key:string,day:int,month:int,color:string[],common:string[],grade:int,grade_display?:string|null,type?:string|null,calendar?:string|null,is_dominical?:bool|null,is_bvm?:bool|null}> $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        $values = array_map(fn (array $event): PropriumDeSanctisEvent => PropriumDeSanctisEvent::fromArray($event), $data);
        $keys   = array_column($values, 'event_key');
        return new static(array_combine($keys, $values));
    }
}
