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
 * The inventory has two halves, and only the smaller one is written down here.
 *
 * The *static* half is the source data that exists once per rite: the temporale, the decrees, and
 * the Roman missal sanctorale editions. Even that is only half-listed — `RomanMissal` already
 * registers every edition and already knows which have a sanctorale file, so those items are
 * derived; the remainder have dedicated `JsonData` constants and are listed explicitly.
 *
 * The *enumerated* half is the per-calendar source data, which is not listed at all and today is
 * the larger of the two. National calendars, wider regions and diocesan calendars come from
 * `CalendarMetadataProvider::create()`, the same builder that serves `/calendars`, so a registered
 * calendar is a checkable calendar by construction and the two lists cannot disagree. Test
 * definitions come from the same glob `TestsHandler` discovers them with. Adding a calendar to
 * source data therefore needs no edit here; adding a whole new *kind* of source data does.
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
     * Memoized for the lifetime of the *process*, not the request. `CalendarMetadataProvider`
     * deliberately re-reads source data on every call because the `/data` write endpoints can mutate
     * calendar definitions at runtime; caching it here keeps a single `/validations` response
     * internally consistent. Under PHP-FPM the process is the request, so the memo cannot outlive the
     * write that would invalidate it — but `Health` is a long-running ReactPHP process and the primary
     * consumer of `byPath()`/`byId()`, and there both this and `self::$items` live until the WebSocket
     * server restarts. A calendar created via `/data` therefore does not appear to those clients until
     * then. Harmless today only because `Health`'s legacy regex arms still resolve those slugs
     * generically; #806 plan 2 replaces those arms with `byId()` and will need a reset hook.
     *
     * Building the index reads and JSON-parses every national and diocesan calendar file, so a single
     * missing or malformed one throws (`ServiceUnavailableException` / `JsonException`) and
     * `GET /validations` answers 503. That is deliberate and inherited from `/calendars`: degrading to
     * a partial list would silently omit what could not be read, which is exactly the #800 blindness
     * this endpoint exists to remove. `Health` contains the blast radius on its own side instead —
     * see the catch in `Health::getPathToSchemaFile()`.
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
                self::diocesanCalendarItems(),
                self::testDefinitionItems()
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

    /**
     * Test definitions, one per JSON file in each rite's tests folder.
     *
     * This item is the *definition* — does the file validate against LitCalTest.json. Running the test
     * against a computed calendar is a separate action with its own addressing, and the two must not be
     * conflated: a definition can be valid while the test it describes fails, and vice versa.
     *
     * Unlike every other kind here, a test has no `i18n` sibling.
     *
     * Enumerated from the filesystem rather than from `CalendarMetadataProvider`, because test
     * definitions are not calendars and the index does not carry them: `TestsHandler` itself
     * discovers them the same way (see its own `glob(... . '/*Test.json')`), so here the glob
     * *is* the registration lookup, not a stat gating an already-known item. The asymmetry this
     * buys is real: unlike every calendar item above, a deleted test file here simply drops out of
     * the inventory instead of staying listed and failing its `exists` step.
     *
     * That tradeoff covers a *deleted file*, not a *failed glob*, which is a different thing.
     * `glob()` returns an empty array — never `false` — for a readable directory with no matches, so
     * `false` always means a filesystem error; treating it as "no tests" would drop every test
     * definition from the inventory with nothing reported anywhere, which is the #800 silent absence
     * again. It raises instead, matching `CalendarMetadataProvider::globOrThrow()`.
     *
     * @return list<CheckableItem>
     */
    private static function testDefinitionItems(): array
    {
        $items = [];

        foreach (Rite::cases() as $rite) {
            $folder = JsonData::testsFolderFor($rite)->path();
            $files  = glob($folder . '/*Test.json');

            if (false === $files) {
                throw new \RuntimeException('CheckableInventory::testDefinitionItems: glob failed for ' . $folder);
            }

            foreach ($files as $file) {
                $name    = basename($file, '.json');
                $items[] = new CheckableItem(
                    "test:{$rite->value}:{$name}",
                    'file',
                    $rite,
                    null,
                    "Liturgical test: {$name}",
                    LitSchema::TEST_SRC,
                    self::STEPS,
                    $file
                );
            }
        }

        return $items;
    }
}
