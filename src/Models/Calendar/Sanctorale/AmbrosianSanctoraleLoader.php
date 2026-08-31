<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Sanctorale;

use LiturgicalCalendar\Api\Enum\AmbrosianMissal;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Models\PropriumDeSanctisMap;
use LiturgicalCalendar\Api\Utilities;

/**
 * Loads the comune ambrosiano sanctorale for a given Ambrosian Missal edition
 * and locale into a {@see PropriumDeSanctisMap} with names applied.
 *
 * Rite-scoped mirror of `CalendarHandler::loadPropriumDeSanctisData()`: reads
 * the edition's `propriumdesanctis_<edition>.json` plus its `i18n/{locale}.json`
 * translations (both located via the {@see AmbrosianMissal} accessors), but
 * returns the built map rather than mutating handler state, and holds no
 * per-request state of its own (re-runnable per year/locale).
 */
final class AmbrosianSanctoraleLoader
{
    /**
     * @param string $missal one of the `AmbrosianMissal::EDITIO_*` constants
     * @param string $locale the locale to load sanctorale names for (e.g. `it`, `la`)
     * @throws ServiceUnavailableException if the edition has no associated sanctorale data or i18n path
     */
    public function load(string $missal, string $locale): PropriumDeSanctisMap
    {
        $sanctoraleFile = AmbrosianMissal::getSanctoraleFileName($missal);
        $i18nPath       = AmbrosianMissal::getSanctoraleI18nFilePath($missal);

        if (false === $sanctoraleFile || false === $i18nPath) {
            throw new ServiceUnavailableException(
                'AmbrosianMissal did not give the file or i18n path with Proprium de Sanctis data for the sanctorale from '
                . AmbrosianMissal::getName($missal)
            );
        }

        $propriumDeSanctis = Utilities::jsonFileToObjectArray($sanctoraleFile);
        $map               = PropriumDeSanctisMap::fromObject($propriumDeSanctis);

        $i18nFile = $i18nPath . $locale . '.json';
        $i18nData = Utilities::jsonFileToArray($i18nFile);
        if (array_filter(array_keys($i18nData), 'is_string') !== array_keys($i18nData)) {
            throw new \Exception('We expected all the keys of the array to be strings.');
        }
        if (array_filter($i18nData, 'is_string') !== $i18nData) {
            throw new \Exception('We expected all the values of the array to be strings.');
        }
        /** @var array<string,string> $i18nData */
        $map->setNames($i18nData);

        return $map;
    }
}
