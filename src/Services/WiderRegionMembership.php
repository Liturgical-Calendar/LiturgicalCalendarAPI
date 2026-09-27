<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\JsonData;

/**
 * Which wider regions a nation belongs to, from the source data.
 *
 * Two places record it, and either is enough: a wider region's own
 * `national_calendars` map (name => ISO code), and a national calendar's
 * `metadata.wider_region`. A nation with no national calendar yet (Venezuela,
 * today) can only appear in the first.
 *
 * Read-only and uncached: it answers one authorization question per request
 * (see OpenFgaAuthorizationMiddleware::forWiderRegionLocale()), over a handful of
 * small files. Wider regions and national calendars exist only in the Roman rite.
 */
final class WiderRegionMembership
{
    /**
     * @param string $nation ISO 3166-1 alpha-2 code, e.g. `CA`
     * @return list<string> the wider regions the nation belongs to, e.g. `['Americas']`; empty when none
     */
    public static function regionsOf(string $nation): array
    {
        $regions = [];

        $regionFiles = glob(JsonData::WIDER_REGIONS_FOLDER->path() . '/*/*.json') ?: [];
        foreach ($regionFiles as $file) {
            $data    = self::decode($file);
            $members = $data['national_calendars'] ?? null;
            $region  = basename($file, '.json');
            if (is_array($members) && in_array($nation, $members, true) && basename(dirname($file)) === $region) {
                $regions[] = $region;
            }
        }

        $nationalFile = strtr(JsonData::NATIONAL_CALENDAR_FILE->path(), ['{nation}' => $nation]);
        if (is_file($nationalFile)) {
            $metadata = self::decode($nationalFile)['metadata'] ?? null;
            $declared = is_array($metadata) ? ( $metadata['wider_region'] ?? null ) : null;
            if (is_string($declared) && $declared !== '') {
                $regions[] = $declared;
            }
        }

        return array_values(array_unique($regions));
    }

    /** @return array<mixed> an empty array for an unreadable or malformed file */
    private static function decode(string $file): array
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
