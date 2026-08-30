<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\Rite;

/**
 * Which JSON Schema governs a source-data file, given only its repo-relative path.
 *
 * This exists because a change request stores a *path*, not a category: by the time a
 * reviewer approves a batch, the request that produced it is long gone, and the only
 * thing left saying what a row's bytes are supposed to look like is where they are going.
 *
 * **Why not {@see \LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory}.**
 * That inventory is the registry of source data that *exists* — it enumerates national,
 * wider-region and diocesan calendars from `CalendarMetadataProvider::create()`, and
 * registers `i18n` as a folder rather than per-locale files. Both properties are wrong for
 * this job: a change request routinely proposes a calendar that does not exist yet (the
 * whole point of a `PUT`), and it always names an individual locale file. Asking the
 * inventory would answer `null` for every newly-created calendar, i.e. exactly the rows
 * most worth checking.
 *
 * **Paths still come from {@see JsonData}.** The templates below are the very constants the
 * write handlers build their paths from, with each `{placeholder}` widened to one path
 * segment. This class must not become a second copy of the repository layout — if a family
 * of source data moves, it moves in `JsonData` and this table follows for free.
 *
 * A path this table does not recognise resolves to `null`, meaning "nothing here claims to
 * know what shape these bytes should have". See
 * {@see ChangeRequestSchemaValidator::violations()} for why that is treated as "not
 * validated" rather than "invalid".
 */
final class SourceDataSchemaResolver
{
    /**
     * Compiled `pattern => schema`, built once per process.
     *
     * @var array<string, LitSchema>|null
     */
    private static ?array $patterns = null;

    /**
     * The schema governing `$repoRelativePath`, or null when no family claims it.
     *
     * `$repoRelativePath` is the form the `path` column stores — `jsondata/...`, with no
     * leading slash and no deployment prefix (see
     * {@see ChangeRequestSourceDataWriter::repoRelativePath()}). A leading slash is
     * tolerated so a caller holding either spelling gets the same answer.
     */
    public static function forPath(string $repoRelativePath): ?LitSchema
    {
        $needle = ltrim($repoRelativePath, '/');

        foreach (self::patterns() as $pattern => $schema) {
            if (1 === preg_match($pattern, $needle)) {
                return $schema;
            }
        }

        return null;
    }

    /**
     * Discard the compiled pattern table so the next call rebuilds it.
     *
     * @internal Exists for the test suite. The memo below survives for the life of the process,
     *           so in a full-suite run whichever test touches this class FIRST builds the table
     *           and every later test — including this class's own — takes the early return. That
     *           left the construction path untested in practice while looking covered in
     *           isolation, and it makes the dedicated unit test order-dependent on a handler test
     *           in a different directory. Resetting in setUp() keeps that test self-contained.
     */
    public static function resetPatternCache(): void
    {
        self::$patterns = null;
    }

    /**
     * Every path family a write handler can stage, most specific first.
     *
     * Order is defensive rather than load-bearing: no two templates here admit the same
     * path, because a `{placeholder}` never crosses a `/` and the families differ in
     * segment count. Listing the sidecars before their owning calendar keeps that true by
     * construction if a template ever gains a segment.
     *
     * @return array<string, LitSchema>
     */
    private static function patterns(): array
    {
        if (self::$patterns !== null) {
            return self::$patterns;
        }

        /** @var array<int, array{JsonData, LitSchema}> $templates */
        $templates = [
            // Calendar sidecars — one file per locale, under each calendar tier.
            [JsonData::NATIONAL_CALENDAR_I18N_FILE, LitSchema::I18N],
            [JsonData::NATIONAL_CALENDAR_LECTIONARY_FILE, LitSchema::LECTIONARY],
            [JsonData::DIOCESAN_CALENDAR_I18N_FILE, LitSchema::I18N],
            [JsonData::DIOCESAN_CALENDAR_LECTIONARY_FILE, LitSchema::LECTIONARY],
            [JsonData::AMBROSIAN_DIOCESAN_CALENDAR_I18N_FILE, LitSchema::I18N],
            [JsonData::WIDER_REGION_I18N_FILE, LitSchema::I18N],
            [JsonData::WIDER_REGION_LECTIONARY_FILE, LitSchema::LECTIONARY],

            // The decrees corpus and its two aggregate sidecar families.
            [JsonData::DECREES_I18N_FILE, LitSchema::I18N],
            [JsonData::LECTIONARY_DECREES_FILE, LitSchema::LECTIONARY],
            [JsonData::DECREES_FILE, LitSchema::DECREES_SRC],

            // The one curated reference resource a write handler can stage. It lives at the
            // top of `jsondata/`, not under `jsondata/sourcedata` — which changes nothing
            // here: this table answers "which schema governs this location", and the path
            // still comes from the same JsonData constant the handler writes through.
            // Without this row `forPath()` answers null, which
            // ChangeRequestSchemaValidator reads as "not validated" rather than "invalid",
            // and a malformed promotion would be approved unchecked (issue #926).
            [JsonData::SUPPORTED_LOCALES_FILE, LitSchema::SUPPORTED_LOCALES],

            // The calendar files themselves.
            [JsonData::NATIONAL_CALENDAR_FILE, LitSchema::NATIONAL],
            [JsonData::DIOCESAN_CALENDAR_FILE, LitSchema::DIOCESAN],
            [JsonData::AMBROSIAN_DIOCESAN_CALENDAR_FILE, LitSchema::DIOCESAN],
            [JsonData::WIDER_REGION_FILE, LitSchema::WIDERREGION],
        ];

        $patterns = [];
        foreach ($templates as [$template, $schema]) {
            $patterns[self::templateToPattern($template->value)] = $schema;
        }

        // Test definitions have no `{name}.json` template of their own — TestsHandler
        // composes `testsFolderFor($rite)->path() . '/' . $name . '.json'` — so the
        // per-rite folders are turned into one-file-deep patterns here, from the same
        // enum cases that handler resolves through.
        foreach (Rite::cases() as $rite) {
            $folder                                                           = JsonData::testsFolderFor($rite)->value;
            $patterns[self::templateToPattern($folder . '/{test_name}.json')] = LitSchema::TEST_SRC;
        }

        self::$patterns = $patterns;

        return $patterns;
    }

    /**
     * A `JsonData` template such as `…/nations/{nation}/i18n/{locale}.json` as an anchored
     * regex, with every placeholder widened to exactly one path segment.
     *
     * Placeholders are deliberately NOT narrowed to a locale/nation grammar. This resolver
     * answers "which schema governs this location", and a path whose segment is malformed
     * is still governed by that schema — narrowing here would silently downgrade a bad
     * filename from "validated" to "unrecognised", which is the wrong direction for a gate.
     */
    private static function templateToPattern(string $template): string
    {
        $quoted = preg_quote($template, '#');

        // preg_quote escapes `{` and `}`, so match them with or without the backslash rather
        // than depending on which PHP version's escape set is in force.
        $widened = preg_replace('#\\\\?\{[a-z_]+\\\\?\}#', '[^/]+', $quoted);

        if (!is_string($widened)) {
            throw new \RuntimeException('Could not compile a source-data path pattern from ' . $template);
        }

        return '#^' . $widened . '$#';
    }
}
