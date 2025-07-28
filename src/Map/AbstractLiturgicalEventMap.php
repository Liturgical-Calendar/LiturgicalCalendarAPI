<?php

namespace LiturgicalCalendar\Api\Map;

use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use LiturgicalCalendar\Api\DateTime;

/**
 * Abstract class for liturgical event maps.
 *
 * Maps event keys to LiturgicalEvent objects.
 * @implements \IteratorAggregate<string,LiturgicalEvent>
 */
abstract class AbstractLiturgicalEventMap implements \IteratorAggregate
{
    /**
     * @var array<string,LiturgicalEvent> Map of event keys to LiturgicalEvent objects.
     */
    protected array $eventMap = [];

    /**
     * Adds a LiturgicalEvent to the map.
     *
     * @param LiturgicalEvent $event The event to add.
     */
    public function addEvent(LiturgicalEvent $event): void
    {
        $this->eventMap[$event->event_key] = $event;
    }

    /**
     * Retrieves a LiturgicalEvent by its key.
     *
     * @param string $key The key of the event to retrieve.
     * @return LiturgicalEvent|null The event if found, null otherwise.
     */
    public function getEvent(string $key): ?LiturgicalEvent
    {
        return $this->eventMap[$key] ?? null;
    }

    /**
     * Retrieves the first LiturgicalEvent that occures on the given date.
     *
     * @param DateTime $date The date of the event to retrieve.
     * @return LiturgicalEvent|null The event if found, null otherwise.
     */
    public function getEventByDate(DateTime $date): ?LiturgicalEvent
    {
        return array_find($this->eventMap, fn ($el) => $el->date == $date);
    }

    /**
     * Removes a LiturgicalEvent from the map by its key.
     *
     * @param string $key The key of the event to remove.
     * @return bool True if the event was removed, false if it did not exist.
     */
    public function removeEvent(string $key): bool
    {
        if (isset($this->eventMap[$key])) {
            unset($this->eventMap[$key]);
            return true;
        }
        return false;
    }

    /**
     * Clears the event map.
     */
    public function clearEvents(): void
    {
        $this->eventMap = [];
    }

    /**
     * Returns the number of events in the map.
     *
     * @return int The number of events.
     */
    public function countEvents(): int
    {
        return count($this->eventMap);
    }

    /**
     * Checks if the event map is empty.
     *
     * @return bool True if the map is empty, false otherwise.
     */
    public function isEmpty(): bool
    {
        return empty($this->eventMap);
    }

    /**
     * Checks if an event exists in the map.
     *
     * @param string $key The key of the event to check.
     * @return bool True if the event exists, false otherwise.
     */
    public function hasEvent(string $key): bool
    {
        return isset($this->eventMap[$key]);
    }

    /**
     * Checks if there is any event in the map that occurs on the specified date.
     *
     * @param DateTime $date The date to check for events.
     * @return bool True if an event occurs on the given date, false otherwise.
     */
    public function hasDate(DateTime $date): bool
    {
        // important: DateTime objects cannot use strict comparison!
        return array_find($this->eventMap, fn ($el) => $el->date == $date) !== null;
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
     * @return array<string,LiturgicalEvent> An array of LiturgicalEvent objects.
     */
    public function getEvents(): array
    {
        return $this->eventMap;
    }

    /**
     * Retrieves all events that occur on the specified date.
     *
     * This method filters the event map and returns an array of events
     * whose date matches the given DateTime object.
     *
     * @param DateTime $date The date for which to retrieve events.
     * @return array<string,LiturgicalEvent> An array of events occurring on the specified date.
     */
    public function getEventsByDate(DateTime $date): array
    {
        // important: DateTime objects cannot use strict comparison!
        return array_filter($this->eventMap, fn ($el) => $el->date == $date);
    }

    /**
     * Returns the event map as a collection.
     *
     * @return LiturgicalEvent[] The event map.
     */
    public function toCollection(): array
    {
        return array_values($this->eventMap);
    }

    /**
     * Returns the key of the first event that occurs on the given date.
     *
     * @param DateTime $date The date for which to find the event key.
     * @return string|null The key of the event at the given date, or null if no event exists.
     */
    public function getEventKeyByDate(DateTime $date): ?string
    {
        // important: DateTime objects cannot use strict comparison!
        return array_find_key($this->eventMap, fn ($el) => $el->date == $date);
    }

    /**
     * Moves the date of a LiturgicalEvent identified by its key to a new date.
     *
     * If the event with the specified key exists in the event map, its date is updated
     * to the provided new date.
     *
     * @param string $key The key of the event to update.
     * @param DateTime $date The new date for the event.
     * @return void
     */
    public function moveEventDateByKey(string $key, DateTime $date): void
    {
        if (array_key_exists($key, $this->eventMap)) {
            $this->eventMap[$key]->date = $date;
        }
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
     * Sorts the event map by date and liturgical grade.
     *
     * The sort order is by date, and for events with the same date, by liturgical grade.
     */
    public function sort(): void
    {
        uasort($this->eventMap, [self::class, 'compDateAndGrade']);
    }

    /**
     * Compares two LiturgicalEvent objects based on their date and liturgical grade.
     *
     * If the two LiturgicalEvent objects have the same date, the comparison is based on their grade.
     * If the two LiturgicalEvent objects have the same grade, the comparison result is 0.
     * If the two LiturgicalEvent objects have different grades, the object with the higher grade is considered higher.
     * If the two LiturgicalEvent objects have different dates, the comparison is based on their date.
     * If the two LiturgicalEvent objects have different dates, the object with the later date is considered higher.
     *
     * @param LiturgicalEvent $a The first LiturgicalEvent object to compare.
     * @param LiturgicalEvent $b The second LiturgicalEvent object to compare.
     *
     * @return int A value indicating the result of the comparison.
     *  -1 if $a is less than $b
     *   0 if $a is equal to $b
     *   1 if $a is greater than $b
     */
    public static function compDateAndGrade(LiturgicalEvent $a, LiturgicalEvent $b)
    {
        if ($a->date == $b->date) {
            if ($a->grade == $b->grade) {
                return 0;
            }
            return ( $a->grade > $b->grade ) ? +1 : -1;
        }
        return ( $a->date > $b->date ) ? +1 : -1;
    }

    /**
     * Merges the events in the current map with the events in another AbstractLiturgicalEventMap.
     *
     * The events in the current map are updated with the events in the other map.
     * If an event with the same key exists in both maps, the event in the current map is replaced with the event from the other map.
     *
     * @param AbstractLiturgicalEventMap $litEvents The map of events to merge with the current map.
     * @return void
     */
    public function merge(AbstractLiturgicalEventMap $litEvents): void
    {
        $this->eventMap = array_merge($this->eventMap, $litEvents->getEvents());
    }

    /**
     * Returns an iterator for the events in the map.
     *
     * @return \Traversable<string,LiturgicalEvent> An iterator for the events in the map.
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->eventMap);
    }
}
