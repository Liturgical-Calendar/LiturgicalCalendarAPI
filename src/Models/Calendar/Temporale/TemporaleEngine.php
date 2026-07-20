<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Temporale;

/**
 * Computes a rite's temporal cycle (movable/major-season events) into the
 * shared LiturgicalEventCollection carried by the TemporaleContext.
 * Implementations MUST be re-runnable per year (the calendar handler runs
 * the pipeline twice for LITURGICAL year_type) and MUST NOT hold per-request
 * state between calls.
 */
interface TemporaleEngine
{
    public function buildTemporale(TemporaleContext $ctx): void;
}
