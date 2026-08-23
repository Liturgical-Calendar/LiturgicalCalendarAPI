<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeMap;

/**
 * Single source of truth for "date a Proprium de Tempore entry, build a
 * LiturgicalEvent from it, and add it to the calendar".
 *
 * Previously each of `RomanTemporale`, `AmbrosianTemporale` and
 * `CalendarHandler` carried its own copy of this helper, and every caller had
 * to write the event key twice — once to set the date on the map entry, once
 * to create the event:
 *
 * ```php
 * $ctx->propriumDeTempore['HolyThurs']->setDate($date);   // key, first time
 * $this->createPropriumDeTemporeLiturgicalEventByKey('HolyThurs', $ctx); // again
 * ```
 *
 * Folding the date into the create call makes the key appear once, and — more
 * importantly — moves the mutation *behind* the existence check. In the old
 * two-statement form an unknown key null-dereferenced on the `setDate()` line,
 * before the guard in the helper ever ran; now it raises the intended
 * {@see ServiceUnavailableException}.
 */
final class PropriumDeTemporeEventFactory
{
    /**
     * Optionally dates the Proprium de Tempore entry for `$key`, builds a
     * LiturgicalEvent from it and adds it to `$cal`.
     *
     * The operation order (`setDate` -> `fromObject` -> `addLiturgicalEvent`)
     * is load-bearing and must not be rearranged: the golden-master fixtures
     * for the Roman calendar depend on it.
     *
     * @param PropriumDeTemporeMap      $propriumDeTempore The rite's Proprium de Tempore
     * @param LiturgicalEventCollection $cal               The calendar to add the event to
     * @param ?string                   $key               The key of the event in the Proprium de Tempore
     * @param ?DateTime                 $date              The event's date; `null` when the caller has
     *                                                     already dated the entry by another path
     * @return LiturgicalEvent The newly created LiturgicalEvent
     * @throws ServiceUnavailableException If `$key` is null or absent from the Proprium de Tempore
     */
    public static function create(
        PropriumDeTemporeMap $propriumDeTempore,
        LiturgicalEventCollection $cal,
        ?string $key,
        ?DateTime $date = null
    ): LiturgicalEvent {
        if (null === $key || false === $propriumDeTempore->offsetExists($key)) {
            throw new ServiceUnavailableException("createPropriumDeTemporeLiturgicalEventByKey requires a key from the Proprium de Tempore, instead got $key");
        }

        if (null !== $date) {
            $propriumDeTempore[$key]->setDate($date);
        }

        $event = LiturgicalEvent::fromObject($propriumDeTempore[$key]);
        $cal->addLiturgicalEvent($key, $event);
        return $event;
    }
}
