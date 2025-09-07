<?php

namespace LiturgicalCalendar\Api\Models\EventsPath;

/**
 * Events map for the Events Path
 *
 * Maps event keys to LiturgicalEventFixed|LiturgicalEventMobile objects.
 * @implements \IteratorAggregate<string,LiturgicalEventFixed|LiturgicalEventMobile>
 */
final class LiturgicalEventMap implements \IteratorAggregate
{
    /**
     * @var array<string,LiturgicalEventFixed|LiturgicalEventMobile> Map of event keys to LiturgicalEvent objects.
     */
    protected array $eventMap = [];

    /**
     * Adds a LiturgicalEvent to the map.
     *
     * @param LiturgicalEventFixed|LiturgicalEventMobile $event The event to add.
     */
    public function addEvent(LiturgicalEventFixed|LiturgicalEventMobile $event): void
    {
        $this->eventMap[$event->event_key] = $event;
    }

    /**
     * Retrieves a LiturgicalEvent by its key.
     *
     * @param string $key The key of the event to retrieve.
     * @return LiturgicalEventFixed|LiturgicalEventMobile|null The event if found, null otherwise.
     */
    public function getEvent(string $key): LiturgicalEventFixed|LiturgicalEventMobile|null
    {
        return $this->eventMap[$key] ?? null;
    }

    /**
     * Checks if a given key exists in the event map.
     *
     * @param string $key The key to check.
     * @return bool True if the key exists, false otherwise.
     */
    public function hasKey(string $key): bool
    {
        return array_key_exists($key, $this->eventMap);
    }

    /**
     * Retrieves all LiturgicalEvent objects in the map.
     *
     * @return array<string,LiturgicalEventFixed|LiturgicalEventMobile> An array of LiturgicalEvent objects.
     */
    public function getEvents(): array
    {
        return $this->eventMap;
    }

    /**
     * Returns the event map as a collection.
     *
     * @return LiturgicalEventAbstract[] The event map.
     */
    public function toCollection(): array
    {
        return array_values($this->eventMap);
    }

    /**
     * Returns an array of keys for the events in the map.
     *
     * @return string[] An array of event keys.
     */
    public function getKeys(): array
    {
        return array_keys($this->eventMap);
    }

    /**
     * Returns an iterator for the events in the map.
     *
     * @return \Traversable<string,LiturgicalEventFixed|LiturgicalEventMobile> An iterator for the events in the map.
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->eventMap);
    }
}
