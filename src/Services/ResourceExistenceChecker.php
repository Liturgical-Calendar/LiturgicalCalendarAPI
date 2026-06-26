<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\JsonData;

/**
 * Decides whether the backing data for an OpenFGA object still exists on disk.
 *
 * Used by the reconciler sweep to distinguish orphaned operational tuples from
 * live ones. GRC fixed objects always exist; unknown types are not resources.
 *
 * Resource types and their backing-data locations:
 *   general_roman_calendar       — fixed; always exists
 *   general_roman_calendar_test  — fixed; always exists
 *   national_calendar            — jsondata/sourcedata/calendars/nations/{id}/{id}.json
 *   wider_region                 — jsondata/sourcedata/calendars/wider_regions/{id}/ (directory)
 *   diocesan_calendar            — jsondata/sourcedata/calendars/dioceses/{nation}/{id}/ (directory, glob)
 *   national_calendar_test       — governance scope; always treated as existing
 *   diocesan_calendar_test       — governance scope; always treated as existing
 */
final class ResourceExistenceChecker
{
    /** @var list<string> */
    private const RESOURCE_TYPES = [
        'national_calendar',
        'diocesan_calendar',
        'wider_region',
        'general_roman_calendar',
        'national_calendar_test',
        'diocesan_calendar_test',
        'general_roman_calendar_test',
    ];

    public function isResourceType(string $objectType): bool
    {
        return in_array($objectType, self::RESOURCE_TYPES, true);
    }

    public function exists(string $objectType, string $objectId): bool
    {
        switch ($objectType) {
            case 'general_roman_calendar':
            case 'general_roman_calendar_test':
                // Fixed catalog ids — always present.
                return true;

            case 'national_calendar':
                return is_file(
                    JsonData::NATIONAL_CALENDARS_FOLDER->path() . "/{$objectId}/{$objectId}.json"
                );

            case 'wider_region':
                return is_dir(
                    JsonData::WIDER_REGIONS_FOLDER->path() . "/{$objectId}"
                );

            case 'diocesan_calendar':
                // Diocesan files live at dioceses/<NATION>/<dioceseId>/; check for the folder
                // across all nation sub-directories via glob.
                $matches = glob(
                    JsonData::DIOCESAN_CALENDARS_FOLDER->path() . "/*/{$objectId}",
                    GLOB_ONLYDIR
                );
                return $matches !== false && $matches !== [];

            case 'national_calendar_test':
            case 'diocesan_calendar_test':
                // Scoped test objects are governance scopes, not file-backed resources.
                // Treat as always existing to avoid false purges.
                return true;

            default:
                return false;
        }
    }
}
