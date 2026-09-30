<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalData;
use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionData\WiderRegionData;
use LiturgicalCalendar\Api\Utilities;

/**
 * Loads a nation's wider regions as calendar layers, in the nation's declared order (#1005).
 *
 * Shared by `CalendarHandler` and `EventsHandler`, so that loading, naming and lectionary resolution live in one
 * place. They used to be duplicated, which is how `/calendar` came to load the nation's own lectionary a second time
 * as "the wider region's".
 *
 * A missing region file, or a missing i18n file for the locale, throws: a calendar quietly missing a region's
 * patrons would be wrong output.
 */
final class WiderRegionLayers
{
    /**
     * @return list<WiderRegionLayer> In the nation's declared order, most general first.
     */
    public static function for(NationalData $nation, string $locale, WiderRegionNaming $naming): array
    {
        $layers = [];
        foreach ($nation->metadata->wider_regions as $region) {
            $substitutions = ['{wider_region}' => $region, '{locale}' => $locale];

            $data  = WiderRegionData::fromObject(Utilities::jsonFileToObject(strtr(JsonData::WIDER_REGION_FILE->path(), $substitutions)));
            $names = self::names(strtr(JsonData::WIDER_REGION_I18N_FILE->path(), $substitutions));

            if ($naming === WiderRegionNaming::Strict) {
                $data->setNames($names);
            } else {
                foreach ($data->litcal as $item) {
                    if (array_key_exists($item->liturgical_event->event_key, $names)) {
                        $item->setName($names[$item->liturgical_event->event_key]);
                    }
                }
            }

            $lectionary = strtr(JsonData::WIDER_REGION_LECTIONARY_FILE->path(), $substitutions);
            $layers[]   = new WiderRegionLayer($region, $data, is_file($lectionary) && is_readable($lectionary) ? $lectionary : null);
        }

        return $layers;
    }

    /**
     * @return array<string, string>
     */
    private static function names(string $file): array
    {
        $names = Utilities::jsonFileToArray($file);
        if (array_filter(array_keys($names), 'is_string') !== array_keys($names)) {
            throw new \Exception('We expected all the keys of the array to be strings.');
        }
        if (array_filter($names, 'is_string') !== $names) {
            throw new \Exception('We expected all the values of the array to be strings.');
        }

        /** @var array<string, string> $names */
        return $names;
    }
}
