<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Missal;

use LiturgicalCalendar\Api\Enum\AmbrosianMissal;

/**
 * Resolves the Ambrosian Missal edition(s) applicable to a civil year.
 *
 * Only the 2024 edition is defined so far ({@see AmbrosianMissal}); every
 * in-range year resolves to it. The 1976 edition and its `since_year`/
 * `until_year` historical split are deferred to a later plan. A year below
 * the rite's floor year never reaches this resolver: it is rejected earlier,
 * with a 400, by `CalendarParams::validateRiteCompatibility()`.
 */
final class AmbrosianMissalResolver implements MissalResolver
{
    /**
     * @return list<string>
     */
    public function resolve(int $year): array
    {
        return [AmbrosianMissal::EDITIO_TYPICA_2024];
    }
}
