<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Rite;

/**
 * Decides whether the backing data for an OpenFGA object still exists on disk.
 *
 * Used by the reconciler sweep to distinguish orphaned operational tuples from
 * live ones. GRC fixed objects always exist; unknown types are not resources.
 *
 * Resource types and their backing-data locations:
 *   general_roman_calendar       — fixed; always exists
 *   general_roman_calendar_test  — fixed; always exists
 *   rite_calendar_test           — fixed catalog; exists iff the id is a known Rite
 *   national_calendar            — jsondata/sourcedata/rite/roman/calendars/nations/{id}/{id}.json
 *   wider_region                 — jsondata/sourcedata/rite/roman/calendars/wider_regions/{id}/ (directory)
 *
 * Ids that name a calendar are rite-qualified as `<rite>/<calendarId>` (issue #786).
 * The qualifier is stripped before the filesystem lookup rather than used to select a
 * partition: this method decides what the reconciler PURGES, and unqualified legacy ids
 * are still in the store for the whole migration window, so it answers "does a calendar
 * of this id exist at all" and never destroys a grant over a format mismatch.
 *   diocesan_calendar            — jsondata/sourcedata/rite/{rite}/calendars/dioceses/{nation}/{id}/ (directory, glob across rites)
 *   national_calendar_test       — governance scope (id `<rite>/<calendarId>`); always treated as existing
 *   diocesan_calendar_test       — governance scope (id `<rite>/<calendarId>`); always treated as existing
 */
final class ResourceExistenceChecker implements ResourceExistenceCheckerInterface
{
    /**
     * The Vatican is announced as a national calendar but is still served by the
     * General Roman Calendar, so it has no source folder of its own yet.
     *
     * Public: {@see \LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory} excludes this
     * id from the checkable inventory for the same reason, and references this constant rather than
     * repeating the literal.
     */
    public const VATICAN_NATIONAL_CALENDAR_ID = 'VA';

    /** @var list<string> */
    private const RESOURCE_TYPES = [
        'national_calendar',
        'diocesan_calendar',
        'wider_region',
        'general_roman_calendar',
        'national_calendar_test',
        'diocesan_calendar_test',
        'general_roman_calendar_test',
        'rite_calendar_test',
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

            case 'rite_calendar_test':
                // Fixed catalog, one entry per rite the API can compute.
                return null !== Rite::tryFrom($objectId);

            case 'national_calendar':
                return self::nationalCalendarExists(RiteScopedObjectId::calendarId($objectId));

            case 'wider_region':
                return is_dir(
                    JsonData::WIDER_REGIONS_FOLDER->path() . '/' . RiteScopedObjectId::calendarId($objectId)
                );

            case 'diocesan_calendar':
                return self::diocesanCalendarExists($objectId);

            case 'national_calendar_test':
            case 'diocesan_calendar_test':
                // Scoped test objects are governance scopes, not file-backed resources.
                // Treat as always existing to avoid false purges. Deliberately not
                // validating the `<rite>/<calendarId>` shape here: during the migration
                // window legacy unqualified ids are still in the store, and this method
                // decides what the reconciler *purges*.
                return true;

            default:
                return false;
        }
    }

    /**
     * True when a diocesan calendar of this id is defined under any rite.
     *
     * Diocesan files live at `dioceses/<NATION>/<dioceseId>/`, and the source tree is
     * partitioned by rite. Globbing only the Roman partition reported every Ambrosian
     * diocese as gone, and because the reconciler purges on this predicate that
     * silently revoked live editor grants on the four Ambrosian dioceses (issue #786).
     *
     * Deliberately answers "under ANY rite" rather than a specific one: this decides
     * what gets purged, so a false negative destroys a grant while a false positive
     * merely keeps a stale tuple around for the next sweep.
     */
    private static function diocesanCalendarExists(string $objectId): bool
    {
        $objectId = RiteScopedObjectId::calendarId($objectId);

        // A glob pattern is being built from this value, so anything outside the
        // diocese-id character set is rejected rather than escaped. This also stops an
        // empty id from matching every nation directory and reporting as existing.
        if (1 !== preg_match('/\A[A-Za-z0-9_-]+\z/', $objectId)) {
            return false;
        }

        foreach ([JsonData::DIOCESAN_CALENDARS_FOLDER, JsonData::AMBROSIAN_DIOCESAN_CALENDARS_FOLDER] as $folder) {
            $matches = glob($folder->path() . "/*/{$objectId}", GLOB_ONLYDIR);
            if ($matches !== false && $matches !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when a national calendar of this id is announced by the API.
     *
     * Normally that means a source folder, but the Vatican is announced as a national
     * calendar while still being served by the General Roman Calendar — it has no
     * `nations/VA/VA.json` of its own yet. Without the special case a live
     * `national_calendar:VA` grant reads as orphaned and the reconciler purges it, the
     * same silent revocation the Ambrosian dioceses suffered (issue #786).
     *
     * Remove the special case once the Vatican gains its own folder; the `is_file()`
     * check below will cover it from then on.
     */
    private static function nationalCalendarExists(string $calendarId): bool
    {
        if ($calendarId === self::VATICAN_NATIONAL_CALENDAR_ID) {
            return true;
        }

        return is_file(
            JsonData::NATIONAL_CALENDARS_FOLDER->path() . "/{$calendarId}/{$calendarId}.json"
        );
    }
}
