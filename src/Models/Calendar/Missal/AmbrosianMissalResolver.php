<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Missal;

use LiturgicalCalendar\Api\Enum\AmbrosianMissal;

/**
 * Resolves the Ambrosian Missal edition(s) applicable to a civil year.
 *
 * Both post-conciliar editions are declared ({@see AmbrosianMissal}), so a year resolves to
 * whichever one governs it, read from the declared `since_year`/`until_year` windows rather than
 * hard-coded here. A year below the rite's floor year never reaches this resolver: it is rejected
 * earlier, with a 400, by `CalendarParams::validateRiteCompatibility()`.
 */
final class AmbrosianMissalResolver implements MissalResolver
{
    /**
     * @return list<string>
     */
    public function resolve(int $year): array
    {
        $editions = self::editionsBySinceYear();

        foreach ($editions as $id => $limits) {
            if ($year < $limits['since_year']) {
                continue;
            }
            // `until_year` is EXCLUSIVE — the successor's `since_year` is the same number.
            if (array_key_exists('until_year', $limits) && $year >= $limits['until_year']) {
                continue;
            }

            return [$id];
        }

        $earliest = array_key_first($editions);
        if (null === $earliest) {
            throw new \LogicException('AmbrosianMissal declares no editions; AmbrosianMissalResolver cannot resolve a year.');
        }

        return [$earliest];
    }

    /**
     * Every declared Ambrosian edition with its year limits, ascending by `since_year`.
     *
     * Sorted rather than trusting declaration order, so adding an edition to `AmbrosianMissal` anywhere in its
     * maps cannot change which edition a year resolves to.
     *
     * @return array<string,array{since_year:int,until_year?:int}>
     */
    private static function editionsBySinceYear(): array
    {
        $editions = [];
        foreach (AmbrosianMissal::getMissalIds() as $id) {
            $editions[$id] = AmbrosianMissal::getYearLimits($id);
        }

        uasort($editions, static fn (array $a, array $b): int => $a['since_year'] <=> $b['since_year']);

        return $editions;
    }
}
