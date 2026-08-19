<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\ValidationsPath;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Enum\RomanMissal;
use LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\CalendarMetadataProvider;
use LiturgicalCalendar\Api\Services\ResourceExistenceChecker;

/**
 * The source data this API can validate, in one place.
 *
 * Previously the same knowledge lived in `Health`'s path-to-schema table, in a parallel branch
 * that matched on slugs instead of paths, and in each client's hardcoded copy of the layout —
 * with nothing detecting divergence. See #806.
 *
 * Half of it need not be written down at all: `RomanMissal` already registers every missal edition
 * and already knows which have a sanctorale file, so those items are derived. The rest have
 * dedicated `JsonData` constants and are listed explicitly.
 *
 * Paths always come from `JsonData` cases. `JsonData` is where this repo's layout is written down;
 * this class must not become a second copy of it.
 */
final class CheckableInventory
{
    /** @var list<string> Every item is checked the same three ways today. */
    private const STEPS = ['exists', 'parses', 'validates'];

    /** @var list<CheckableItem>|null */
    private static ?array $items = null;

    /** @var MetadataCalendars|null */
    private static ?MetadataCalendars $metadata = null;

    /**
     * The calendar index, from the same builder that serves `/calendars`.
     *
     * Memoized for the lifetime of the request only. `CalendarMetadataProvider` deliberately re-reads
     * source data on every call because the `/data` write endpoints can mutate calendar definitions at
     * runtime; caching it here for one request keeps a single `/validations` response internally
     * consistent without outliving the write that would invalidate it.
     */
    private static function metadata(): MetadataCalendars
    {
        return self::$metadata ??= CalendarMetadataProvider::create();
    }

    /** @return list<CheckableItem> */
    public static function all(): array
    {
        if (null === self::$items) {
            self::$items = array_merge(
                self::derivedRomanSanctorale(),
                self::explicitItems(),
                self::nationalCalendarItems(),
                self::widerRegionItems(),
                self::diocesanCalendarItems()
            );
        }

        return self::$items;
    }

    public static function byId(string $id): ?CheckableItem
    {
        return array_find(self::all(), static fn (CheckableItem $i): bool => $i->id === $id);
    }

    /**
     * `Health` compares against `JsonData::*->value`, i.e. repo-relative paths, while this
     * inventory stores the absolute form the `JsonData` and `RomanMissal` accessors return.
     * Normalise here so neither caller has to know which representation it is holding.
     */
    public static function byPath(string $path): ?CheckableItem
    {
        $needle = str_starts_with($path, Router::$apiFilePath)
            ? $path
            : Router::$apiFilePath . ltrim($path, '/');
        $needle = rtrim($needle, '/');

        return array_find(
            self::all(),
            static fn (CheckableItem $i): bool => rtrim($i->path, '/') === $needle
        );
    }

    /**
     * The Roman sanctorale, derived from the missal registry rather than restated.
     *
     * `getSanctoraleFileName()` returns false for the editions that have no sanctorale file on
     * disk, which is exactly how the five that do were picked in the old hand-written table. A new
     * edition with a sanctorale file joins the inventory with no edit here.
     *
     * @return list<CheckableItem>
     */
    private static function derivedRomanSanctorale(): array
    {
        $items = [];
        foreach (RomanMissal::getMissalIds() as $missalId) {
            $file = RomanMissal::getSanctoraleFileName($missalId);
            if (false === $file) {
                continue;
            }

            $name = RomanMissal::getName($missalId);
            // 'VA' in produceMetadata() means "not nation-specific"; this inventory says so with null.
            $region = str_starts_with($missalId, 'EDITIO_TYPICA_') ? null : explode('_', $missalId)[0];

            $items[] = new CheckableItem(
                "sanctorale:roman:{$missalId}",
                'file',
                Rite::ROMAN,
                $region,
                $name,
                LitSchema::PROPRIUMDESANCTIS,
                self::STEPS,
                $file
            );

            $i18n = RomanMissal::getSanctoraleI18nFilePath($missalId);
            if (false !== $i18n) {
                $items[] = new CheckableItem(
                    "sanctorale:roman:{$missalId}:i18n",
                    'folder',
                    Rite::ROMAN,
                    $region,
                    "{$name} translations",
                    LitSchema::I18N,
                    self::STEPS,
                    rtrim($i18n, '/')
                );
            }
        }

        return $items;
    }

    /** @return list<CheckableItem> */
    private static function explicitItems(): array
    {
        return [
            new CheckableItem(
                'temporale:roman',
                'file',
                Rite::ROMAN,
                null,
                'Roman Proprium de Tempore',
                LitSchema::PROPRIUMDETEMPORE,
                self::STEPS,
                JsonData::TEMPORALE_FILE->path()
            ),
            new CheckableItem(
                'temporale:roman:i18n',
                'folder',
                Rite::ROMAN,
                null,
                'Roman Proprium de Tempore translations',
                LitSchema::I18N,
                self::STEPS,
                JsonData::TEMPORALE_I18N_FOLDER->path()
            ),
            new CheckableItem(
                'decrees:roman',
                'file',
                Rite::ROMAN,
                null,
                'Memorials from Decrees',
                LitSchema::DECREES_SRC,
                self::STEPS,
                JsonData::DECREES_FILE->path()
            ),
            new CheckableItem(
                'decrees:roman:i18n',
                'folder',
                Rite::ROMAN,
                null,
                'Memorials from Decrees translations',
                LitSchema::I18N,
                self::STEPS,
                JsonData::DECREES_I18N_FOLDER->path()
            ),
            new CheckableItem(
                'temporale:ambrosian',
                'file',
                Rite::AMBROSIAN,
                null,
                'Ambrosian Proprium de Tempore',
                LitSchema::PROPRIUMDETEMPORE,
                self::STEPS,
                JsonData::AMBROSIAN_TEMPORALE_FILE->path()
            ),
            new CheckableItem(
                'temporale:ambrosian:i18n',
                'folder',
                Rite::AMBROSIAN,
                null,
                'Ambrosian Proprium de Tempore translations',
                LitSchema::I18N,
                self::STEPS,
                JsonData::AMBROSIAN_TEMPORALE_I18N_FOLDER->path()
            ),
            new CheckableItem(
                'sanctorale:ambrosian',
                'file',
                Rite::AMBROSIAN,
                null,
                'Ambrosian Proprium de Sanctis',
                LitSchema::PROPRIUMDESANCTIS,
                self::STEPS,
                JsonData::AMBROSIAN_SANCTORALE_FILE->path()
            ),
            new CheckableItem(
                'sanctorale:ambrosian:i18n',
                'folder',
                Rite::AMBROSIAN,
                null,
                'Ambrosian Proprium de Sanctis translations',
                LitSchema::I18N,
                self::STEPS,
                JsonData::AMBROSIAN_SANCTORALE_I18N_FOLDER->path()
            )
        ];
    }

    /**
     * National calendar definitions, enumerated from the calendar index rather than listed.
     *
     * A national calendar is specific to its own nation, so `region` is its calendar id — that is what
     * lets a client scoping to one calendar keep it and drop the other nine.
     *
     * @return list<CheckableItem>
     */
    private static function nationalCalendarItems(): array
    {
        $items = [];
        foreach (self::metadata()->national_calendars as $nation) {
            $id = $nation->calendar_id;

            // The Vatican is announced as a national calendar but is served by the General Roman
            // Calendar and has no source folder of its own — see ResourceExistenceChecker, which
            // special-cases the same id for the same reason. There is nothing here to check, so it
            // is excluded rather than listed with a target that could never exist.
            if (ResourceExistenceChecker::VATICAN_NATIONAL_CALENDAR_ID === $id) {
                continue;
            }

            $file = strtr(JsonData::NATIONAL_CALENDAR_FILE->path(), ['{nation}' => $id]);

            $items[] = new CheckableItem(
                "nation:roman:{$id}",
                'file',
                Rite::ROMAN,
                $id,
                "National calendar: {$id}",
                LitSchema::NATIONAL,
                self::STEPS,
                $file
            );

            $items[] = new CheckableItem(
                "nation:roman:{$id}:i18n",
                'folder',
                Rite::ROMAN,
                $id,
                "National calendar translations: {$id}",
                LitSchema::I18N,
                self::STEPS,
                rtrim(strtr(JsonData::NATIONAL_CALENDAR_I18N_FOLDER->path(), ['{nation}' => $id]), '/')
            );
        }

        return $items;
    }

    /**
     * Wider region definitions.
     *
     * `region` is null: a wider region spans several nations, which a scalar cannot express. Clients
     * scoping to one calendar use the `wider_region` field `/calendars` already gives them, rather than
     * this field. Widening `region` into a list would be a wire-contract change, not a fix here.
     *
     * @return list<CheckableItem>
     */
    private static function widerRegionItems(): array
    {
        $items = [];
        foreach (self::metadata()->wider_regions as $region) {
            $name = $region->name;
            $file = strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => $name]);

            $items[] = new CheckableItem(
                "widerregion:roman:{$name}",
                'file',
                Rite::ROMAN,
                null,
                "Wider region: {$name}",
                LitSchema::WIDERREGION,
                self::STEPS,
                $file
            );

            $items[] = new CheckableItem(
                "widerregion:roman:{$name}:i18n",
                'folder',
                Rite::ROMAN,
                null,
                "Wider region translations: {$name}",
                LitSchema::I18N,
                self::STEPS,
                rtrim(strtr(JsonData::WIDER_REGION_I18N_FOLDER->path(), ['{wider_region}' => $name]), '/')
            );
        }

        return $items;
    }

    /**
     * Diocesan calendar definitions.
     *
     * The rite selects the path template, not just a label: an Ambrosian diocese lives under
     * `rite/ambrosian/calendars/dioceses/`, and the file name is the diocese *name* rather than its id,
     * which is why the template carries three placeholders.
     *
     * @return list<CheckableItem>
     */
    private static function diocesanCalendarItems(): array
    {
        $items = [];
        foreach (self::metadata()->diocesan_calendars as $diocese) {
            $fileTemplate = JsonData::diocesanCalendarFileFor($diocese->rite)->path();
            $i18nTemplate = JsonData::diocesanCalendarI18nFolderFor($diocese->rite)->path();

            $replacements = [
                '{nation}'       => $diocese->nation,
                '{diocese}'      => $diocese->calendar_id,
                '{diocese_name}' => $diocese->diocese
            ];

            $items[] = new CheckableItem(
                "diocese:{$diocese->rite->value}:{$diocese->calendar_id}",
                'file',
                $diocese->rite,
                $diocese->nation,
                "Diocesan calendar: {$diocese->diocese}",
                LitSchema::DIOCESAN,
                self::STEPS,
                strtr($fileTemplate, $replacements)
            );

            $items[] = new CheckableItem(
                "diocese:{$diocese->rite->value}:{$diocese->calendar_id}:i18n",
                'folder',
                $diocese->rite,
                $diocese->nation,
                "Diocesan calendar translations: {$diocese->diocese}",
                LitSchema::I18N,
                self::STEPS,
                rtrim(strtr($i18nTemplate, $replacements), '/')
            );
        }

        return $items;
    }
}
