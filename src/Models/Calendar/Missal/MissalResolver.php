<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Missal;

/**
 * Resolves which Missal edition(s) are applicable to a given civil year, for a
 * rite that extracts this decision into its own strategy (currently Ambrosian
 * only; the Roman path resolves missals inline in CalendarHandler).
 *
 * Implementations MUST be re-runnable per year and MUST NOT hold per-request
 * state between calls.
 */
interface MissalResolver
{
    /**
     * @param int $year the civil year the calendar is being computed for
     * @return list<string> the Missal edition id(s) (e.g. `AmbrosianMissal::EDITIO_*`
     *   constants) applicable to $year
     */
    public function resolve(int $year): array;
}
