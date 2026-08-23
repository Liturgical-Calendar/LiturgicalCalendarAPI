<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\LocaleDateFormatter;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeMap;
use LiturgicalCalendar\Api\Params\CalendarParams;

/**
 * Carries the handler's shared mutable state into a TemporaleEngine so the
 * extracted computation behaves identically to the inline version.
 *
 * The message sink is passed by reference so that any message appended by an
 * engine lands in the handler's own `$this->Messages` array, in the exact same
 * order it would have been emitted by the original inline code.
 */
final class TemporaleContext
{
    /**
     * @param array<string> $messages message sink held by reference so that
     *                                     appends land in the handler's own
     *                                     collection in emission order
     */
    public function __construct(
        public readonly LiturgicalEventCollection $cal,
        public readonly CalendarParams $params,
        public readonly PropriumDeTemporeMap $propriumDeTempore,
        public readonly LocaleDateFormatter $localeDateFormatter,
        public array &$messages
    ) {
    }

    public function addMessage(string $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * Dates the Proprium de Tempore entry for `$key` and adds the resulting
     * LiturgicalEvent to the calendar, in one guarded call.
     *
     * Thin delegation to {@see PropriumDeTemporeEventFactory::create()} over
     * this context's own Proprium de Tempore and calendar, so that engines
     * need not thread those two collaborators through every call site.
     *
     * @param ?string   $key  The key of the event in the Proprium de Tempore
     * @param ?DateTime $date The event's date; `null` when the entry has already been dated
     * @return LiturgicalEvent The newly created LiturgicalEvent
     */
    public function createPropriumDeTemporeEvent(?string $key, ?DateTime $date = null): LiturgicalEvent
    {
        return PropriumDeTemporeEventFactory::create($this->propriumDeTempore, $this->cal, $key, $date);
    }
}
