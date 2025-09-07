<?php

namespace LiturgicalCalendar\Api\Models;

/**
 * Represents a collection of MissalsMap objects.
 *
 * @implements \IteratorAggregate<string,PropriumDeSanctisMap>
 * @implements \ArrayAccess<string,PropriumDeSanctisMap>
 */
final class MissalsMap implements \IteratorAggregate, \ArrayAccess
{
    /** @var array<string,PropriumDeSanctisMap> */
    private array $missals;

    /**
     * @param array<string,PropriumDeSanctisMap> $missals
     */
    private function __construct(array $missals)
    {
        $this->missals = $missals;
    }


    /**
     * @return \Traversable<string,PropriumDeSanctisMap> An iterator for the items in the collection.
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->missals);
    }

    /**
     * Retrieves the PropriumDeSanctisMap at the specified offset.
     *
     * @param string $offset The offset to retrieve the event from.
     * @return PropriumDeSanctisMap The event at the specified offset.
     */
    public function offsetGet($offset): PropriumDeSanctisMap
    {
        return $this->missals[$offset];
    }

    /**
     * Throws a BadMethodCallException, as PropriumDeTemporeCollection is immutable and cannot be modified.
     *
     * @param string $offset The offset to set the value at.
     * @param PropriumDeSanctisMap $value The value to set.
     */
    public function offsetSet($offset, $value): void
    {
        $this->missals[$offset] = $value;
    }

    /**
     * @param string $offset The offset to unset.
     */
    public function offsetUnset($offset): void
    {
        unset($this->missals[$offset]);
    }

    /**
     * Checks if an event exists at the specified offset.
     *
     * @param string $offset The offset to check.
     * @return bool True if the event exists, false otherwise.
     */
    public function offsetExists($offset): bool
    {
        return isset($this->missals[$offset]);
    }

    /**
     * Initializes a new instance of MissalsMap with the given array of missals.
     *
     * @param array<string,PropriumDeSanctisMap> $missals The missals to use for the new instance.
     * @return MissalsMap The initialized instance.
     */
    public static function initWithMissals(array $missals): self
    {
        return new self($missals);
    }
}
