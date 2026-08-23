<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\ValidationsPath;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LectionaryCategory;
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
    /** @var list<string> The three ways every item is checked: is it there, does it decode, does it validate. */
    private const STEPS = ['exists', 'parses', 'validates'];

    /**
     * @var list<string> The four steps of an item that also declares which locales it should hold.
     *
     * `covers` asks a different question from the other three: they ask whether what is present is
     * well-formed, it asks whether anything is missing. An item gets it exactly when it carries an
     * expected locale set — see {@see self::folderItemIfPresent()}, which derives one from the other so
     * the two can never be given different answers.
     */
    private const STEPS_WITH_COVERAGE = ['exists', 'parses', 'validates', 'covers'];

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
     * then. That is what {@see self::reset()} is for: `Health` calls it as each run begins, because
     * its `validateSource` action resolves solely through `byId()` and cannot fall back on the
     * legacy regex arms that used to resolve those slugs generically.
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

    /**
     * Drop the memoized index.
     *
     * The memo is per-process, which is a request under PHP-FPM but the whole server lifetime in
     * `Health`'s long-running ReactPHP process. A v2 `validateSource` resolves solely through
     * {@see self::byId()}, so without this a calendar created via `/data` would stay invisible to
     * the WebSocket until restart — the legacy slug arms used to mask that by resolving
     * generically.
     *
     * An invalidation hook on the write path cannot work: `/data` writes happen in the HTTP
     * process, which `Health` never observes. `Health` therefore resets once per run, bounding
     * staleness to a single run at the cost of one rebuild per run rather than one per lookup.
     */
    public static function reset(): void
    {
        self::$items    = null;
        self::$metadata = null;
    }

    /** @return list<CheckableItem> */
    public static function all(): array
    {
        if (null === self::$items) {
            self::$items = array_merge(
                self::staticItems(),
                self::lectionaryCorpusItems(),
                self::nationalCalendarItems(),
                self::widerRegionItems(),
                self::diocesanCalendarItems(),
                self::testDefinitionItems()
            );
        }

        return self::$items;
    }

    /**
     * A folder item, or null when the folder is not on disk.
     *
     * Conditional by design. Most calendars have no lectionary folder — three of ten nations, one wider
     * region, two of five missals — and advertising one anyway would mean an item whose `exists` step is
     * guaranteed to fail: a red that reports nothing but its own existence. The `is_dir()` here is a stat
     * on a path already known from the registry, not a discovery glob, so it cannot fail the way
     * {@see self::testDefinitionItems()}'s glob can.
     *
     * `$expectedLocales` and the `covers` step are derived from one another here, in one place, so an item
     * cannot advertise a step it carries no expectation for or an expectation it never reports on.
     *
     * `$expectedLocales` is normalised to a list here rather than at each call site: the metadata models
     * type theirs as `string[]`, and an array with non-sequential keys would serialize as a JSON *object*
     * on `/validations`, which the schema declares as an array. One `array_values()` at the boundary is
     * what makes the wire shape right by construction.
     *
     * @param ?array<string> $expectedLocales
     */
    private static function folderItemIfPresent(
        string $id,
        Rite $rite,
        ?string $region,
        string $label,
        LitSchema $schema,
        string $path,
        ?array $expectedLocales
    ): ?CheckableItem {
        $path = rtrim($path, '/');

        if (!is_dir($path)) {
            return null;
        }

        $expectedLocales = null === $expectedLocales ? null : array_values($expectedLocales);

        return new CheckableItem(
            $id,
            'folder',
            $rite,
            $region,
            $label,
            $schema,
            null === $expectedLocales ? self::STEPS : self::STEPS_WITH_COVERAGE,
            $path,
            $expectedLocales
        );
    }

    /**
     * The half of the inventory that does not read calendar source data.
     *
     * `derivedRomanSanctorale()` and `explicitItems()` build their paths from the `RomanMissal` and
     * `JsonData` registries, both in-memory: neither calls {@see self::metadata()}, so neither can
     * fail for the reason the enumerating producers can. That is what makes this half usable as a
     * fallback when the full lookup throws — see {@see self::staticByPath()}.
     *
     * `testDefinitionItems()` is deliberately NOT part of this. It does not depend on
     * `CalendarMetadataProvider` either, but it globs, and a failed glob raises here by design; a
     * fallback that can itself throw is not a fallback.
     *
     * Not memoized: these are registry lookups and string joins with no I/O, and `all()` memoizes
     * the merged list anyway.
     *
     * @return list<CheckableItem>
     */
    private static function staticItems(): array
    {
        return array_merge(
            self::derivedRomanSanctorale(),
            self::explicitItems()
        );
    }

    public static function byId(string $id): ?CheckableItem
    {
        return array_find(self::all(), static fn (CheckableItem $i): bool => $i->id === $id);
    }

    /**
     * As {@see self::byId()}, but over the static half alone.
     *
     * For callers that must still answer something when the full inventory is unavailable. It
     * resolves strictly fewer ids, never different ones: these items are present in `all()` too,
     * identical, so a hit here is a hit there.
     */
    public static function staticById(string $id): ?CheckableItem
    {
        return array_find(self::staticItems(), static fn (CheckableItem $i): bool => $i->id === $id);
    }

    /**
     * `Health` compares against `JsonData::*->value`, i.e. repo-relative paths, while this
     * inventory stores the absolute form the `JsonData` and `RomanMissal` accessors return.
     * Normalise here so neither caller has to know which representation it is holding.
     */
    public static function byPath(string $path): ?CheckableItem
    {
        return self::matchPath(self::all(), $path);
    }

    /**
     * As {@see self::byPath()}, but over the static half alone — the same narrowing, for the same
     * reason, as {@see self::staticById()}.
     */
    public static function staticByPath(string $path): ?CheckableItem
    {
        return self::matchPath(self::staticItems(), $path);
    }

    /**
     * @param list<CheckableItem> $items
     */
    private static function matchPath(array $items, string $path): ?CheckableItem
    {
        $needle = str_starts_with($path, Router::$apiFilePath)
            ? $path
            : Router::$apiFilePath . ltrim($path, '/');
        $needle = rtrim($needle, '/');

        return array_find(
            $items,
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

            // The missal's lectionary sits beside its sanctorale file rather than at a path derived from
            // the missal id: the folder for EDITIO_TYPICA_1970 is `propriumdesanctis_1970`, so splitting
            // the id would build the wrong path. `$file` is the registry's own answer for this missal.
            $i18n          = RomanMissal::getSanctoraleI18nFilePath($missalId);
            $missalLocales = false === $i18n
                ? null
                : array_values(array_map(
                    static fn (string $f): string => basename($f, '.json'),
                    glob(rtrim($i18n, '/') . '/*.json') ?: []
                ));

            $lectionary = self::folderItemIfPresent(
                "sanctorale:roman:{$missalId}:lectionary",
                Rite::ROMAN,
                $region,
                "{$name} lectionary structure",
                LitSchema::LECTIONARY,
                dirname($file) . '/lectionary',
                $missalLocales
            );

            if (null !== $lectionary) {
                $items[] = $lectionary;
            }

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
     * The rite's own lectionary corpus: ten sections, plus the lectionary that accompanies the decrees.
     *
     * The sections are enumerated from {@see LectionaryCategory}, which already registers every one of
     * them and already knows which carry a year cycle — the three-year A/B/C for Sundays and solemnities,
     * the two-year I/II for Ordinary Time weekdays. Restating the folder names here would be a second
     * copy of a list that already exists, and the copy is what goes stale.
     *
     * Deliberately *not* part of {@see self::staticItems()}, despite reading only registries for its
     * paths: the expected locale set comes from {@see self::metadata()}, so this producer can fail for the
     * same reason the enumerating ones can, and a fallback that can itself throw is not a fallback. The
     * cost is that a `lectionary:` id does not resolve through {@see self::staticById()} when the full
     * index is unavailable, exactly as a national or diocesan id does not.
     *
     * The expectation is the General Roman Calendar's own locale set — the fully translated one, which is
     * five locales and not the fourteen gettext folders that `buildLocales()` intersects down. The
     * sections carry six, so this is green; no expectation had to be invented to make it so.
     *
     * @return list<CheckableItem>
     */
    private static function lectionaryCorpusItems(): array
    {
        $locales = self::metadata()->locales;
        $folders = [];

        foreach (LectionaryCategory::cases() as $category) {
            if ($category->hasYearCycle()) {
                foreach (['A', 'B', 'C'] as $cycle) {
                    $folders[] = $category->folderForYear($cycle);
                }
            } elseif ($category->hasTwoYearCycle()) {
                foreach (['I', 'II'] as $cycle) {
                    $folders[] = $category->folderForTwoYearCycle($cycle);
                }
            } else {
                $folders[] = $category->folder();
            }
        }

        $items = [];

        foreach ($folders as $folder) {
            $path    = $folder->path();
            $section = basename($path);
            $item    = self::folderItemIfPresent(
                "lectionary:roman:{$section}",
                Rite::ROMAN,
                null,
                "Lectionary structure: {$section}",
                LitSchema::LECTIONARY,
                $path,
                $locales
            );

            if (null !== $item) {
                $items[] = $item;
            }
        }

        $decrees = self::folderItemIfPresent(
            'decrees:roman:lectionary',
            Rite::ROMAN,
            null,
            'Lectionary structure: memorials from decrees',
            LitSchema::LECTIONARY,
            JsonData::LECTIONARY_DECREES_FOLDER->path(),
            $locales
        );

        if (null !== $decrees) {
            $items[] = $decrees;
        }

        return $items;
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

            // The nation's own file declares `locales`; it is not scanned from the folder being checked,
            // so asking whether the folder keeps up with it is a real question rather than a tautology.
            $i18n = self::folderItemIfPresent(
                "nation:roman:{$id}:i18n",
                Rite::ROMAN,
                $id,
                "National calendar translations: {$id}",
                LitSchema::I18N,
                strtr(JsonData::NATIONAL_CALENDAR_I18N_FOLDER->path(), ['{nation}' => $id]),
                $nation->locales
            );

            if (null !== $i18n) {
                $items[] = $i18n;
            }

            $lectionary = self::folderItemIfPresent(
                "nation:roman:{$id}:lectionary",
                Rite::ROMAN,
                $id,
                "National lectionary structure: {$id}",
                LitSchema::LECTIONARY,
                strtr(JsonData::NATIONAL_CALENDAR_LECTIONARY_FOLDER->path(), ['{nation}' => $id]),
                $nation->locales
            );

            if (null !== $lectionary) {
                $items[] = $lectionary;
            }
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

            // No expectation on the i18n folder: a wider region's declared `locales` are scanned from that
            // very folder (see CalendarMetadataProvider::buildWiderRegionData()), so the comparison could
            // only ever pass. The lectionary folder is a different matter — it is measured against them.
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

            $lectionary = self::folderItemIfPresent(
                "widerregion:roman:{$name}:lectionary",
                Rite::ROMAN,
                null,
                "Wider region lectionary structure: {$name}",
                LitSchema::LECTIONARY,
                strtr(JsonData::WIDER_REGION_LECTIONARY_FOLDER->path(), ['{wider_region}' => $name]),
                $region->locales
            );

            if (null !== $lectionary) {
                $items[] = $lectionary;
            }
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

            // As with a nation: the diocese's own file declares `locales`.
            $i18n = self::folderItemIfPresent(
                "diocese:{$diocese->rite->value}:{$diocese->calendar_id}:i18n",
                $diocese->rite,
                $diocese->nation,
                "Diocesan calendar translations: {$diocese->diocese}",
                LitSchema::I18N,
                strtr($i18nTemplate, $replacements),
                $diocese->locales
            );

            if (null !== $i18n) {
                $items[] = $i18n;
            }

            // DIOCESAN_CALENDAR_LECTIONARY_FOLDER is Roman-only and has no Ambrosian counterpart, because
            // no Ambrosian diocese has a lectionary folder. Rather than invent one, the Roman template is
            // used for every rite and folderItemIfPresent() returns null where nothing is there — which is
            // every Ambrosian diocese today, and would remain correct if that changed for a Roman one.
            $lectionary = self::folderItemIfPresent(
                "diocese:{$diocese->rite->value}:{$diocese->calendar_id}:lectionary",
                $diocese->rite,
                $diocese->nation,
                "Diocesan lectionary structure: {$diocese->diocese}",
                LitSchema::LECTIONARY,
                strtr(JsonData::DIOCESAN_CALENDAR_LECTIONARY_FOLDER->path(), $replacements),
                $diocese->locales
            );

            if (null !== $lectionary) {
                $items[] = $lectionary;
            }
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
