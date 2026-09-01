<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Missal;

use LiturgicalCalendar\Api\Enum\AmbrosianMissal;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;

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
     * The edition that governs `$year`, paired with the edition whose sanctorale is actually read.
     *
     * `resolve()` stays a pure statement about which edition is in force. This method adds the separate
     * question of whether this codebase holds that edition's proper, and where it does not, walks FORWARD
     * to the nearest later edition that ships one. Forward, not backward: a later edition is a revision of
     * this rite's own proper and is the closest thing held to the missing one, whereas walking backward
     * reaches for an edition that is itself absent.
     *
     * The day the missing edition's data lands, this method simply stops substituting — no caller changes.
     *
     * @throws ServiceUnavailableException if neither the governing edition nor any later one ships a sanctorale
     */
    public function selectSanctoraleEdition(int $year): MissalEditionSelection
    {
        $requested = $this->resolve($year)[0];

        if (false !== AmbrosianMissal::getSanctoraleFileName($requested)) {
            return new MissalEditionSelection($requested, $requested);
        }

        $editions       = self::editionsBySinceYear();
        $requestedSince = $editions[$requested]['since_year'];

        foreach ($editions as $id => $limits) {
            if ($limits['since_year'] <= $requestedSince) {
                continue;
            }
            if (false !== AmbrosianMissal::getSanctoraleFileName($id)) {
                return new MissalEditionSelection($requested, $id);
            }
        }

        throw new ServiceUnavailableException(sprintf(
            'No Ambrosian Missal edition with sanctorale data is available for the year %d: the %s governs it and ships none, and neither does any later edition.',
            $year,
            AmbrosianMissal::getName($requested)
        ));
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
