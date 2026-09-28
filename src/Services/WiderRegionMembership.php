<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\JsonData;

/**
 * Which wider regions a nation belongs to, from the source data.
 *
 * Two places record it, and either is enough: a wider region's own
 * `national_calendars` map (name => ISO code), and a national calendar's
 * `metadata.wider_regions`. A nation with no national calendar yet (Venezuela,
 * today) can only appear in the first.
 *
 * Read-only and uncached: it answers one authorization question per request
 * (see OpenFgaAuthorizationMiddleware::forWiderRegionLocale()), over a handful of
 * small files. Wider regions and national calendars exist only in the Roman rite.
 *
 * An answer of "no region" grants something there — a nation in no region may join
 * one — so a file it cannot read must never look like one. Anything unreadable or
 * malformed throws instead, and the caller fails closed.
 */
final class WiderRegionMembership
{
    /**
     * @param string $nation ISO 3166-1 alpha-2 code, e.g. `CA`
     * @return list<string> the wider regions the nation belongs to, e.g. `['Americas']`; empty when none
     * @throws \RuntimeException when a membership record cannot be read or is malformed
     */
    public static function regionsOf(string $nation): array
    {
        $regions = [];

        $regionFiles = glob(JsonData::WIDER_REGIONS_FOLDER->path() . '/*/*.json');
        if ($regionFiles === false) {
            throw new \RuntimeException('Unable to list the wider region files.');
        }
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
            $declared = is_array($metadata) ? ( $metadata['wider_regions'] ?? [] ) : [];
            if (is_array($declared)) {
                foreach ($declared as $region) {
                    if (is_string($region) && $region !== '') {
                        $regions[] = $region;
                    }
                }
            }
        }

        return array_values(array_unique($regions));
    }

    /**
     * @return array<mixed>
     * @throws \RuntimeException when the file cannot be read or is not a JSON object
     */
    private static function decode(string $file): array
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new \RuntimeException("Unable to read the membership record {$file}.");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException("The membership record {$file} is not valid JSON.");
        }
        return $data;
    }
}
