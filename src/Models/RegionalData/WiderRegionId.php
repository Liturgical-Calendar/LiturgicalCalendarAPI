<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\RegionalData;

/**
 * A wider region's identifier (#1018): lowercase words joined by single hyphens (`europe`, `german-language-area`).
 *
 * It is the region's key everywhere: its folder and file names, `/data/widerregion/{id}`, a nation's `wider_regions`,
 * and OpenFGA object ids. It is URL-safe and OpenFGA-safe by construction, and it never changes once the region exists;
 * what users see is the region's label (WiderRegionLabels). The JSON schemas mirror PATTERN as `WiderRegionId`.
 *
 * Until #1018, the identifier was a capitalised display name (`Europe`, `Middle East`, LEGACY_PATTERN). Such a name
 * is still accepted on input for a transition period and mapped to its id by normalize(): lowercased, spaces to
 * hyphens. The mapping is deterministic, so no table of old names is needed.
 */
final class WiderRegionId
{
    public const PATTERN = '/^[a-z]+(-[a-z]+)*$/D';

    public const LEGACY_PATTERN = '/^[A-Z][A-Za-z]*( [A-Z][A-Za-z]*)*$/D';

    public static function isValid(string $id): bool
    {
        return 1 === preg_match(self::PATTERN, $id);
    }

    public static function isLegacy(string $name): bool
    {
        return 1 === preg_match(self::LEGACY_PATTERN, $name);
    }

    /**
     * The id an input denotes, and whether it arrived as a legacy name. Null when it is neither shape.
     *
     * @return array{0: string, 1: bool}|null
     */
    public static function normalize(string $input): ?array
    {
        if (self::isValid($input)) {
            return [$input, false];
        }
        if (self::isLegacy($input)) {
            return [strtolower(str_replace(' ', '-', $input)), true];
        }

        return null;
    }

    /** Words for an id with no usable label: `german-language-area` → `German Language Area`. */
    public static function humanize(string $id): string
    {
        return ucwords(str_replace('-', ' ', $id));
    }
}
