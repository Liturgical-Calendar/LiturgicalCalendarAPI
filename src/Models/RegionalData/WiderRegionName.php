<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\RegionalData;

/**
 * The one shape rule for a wider region name (#1005): one or more capitalised words separated by single spaces
 * (`Europe`, `Middle East`). Whether a region with that name exists is checked at runtime against
 * `wider_regions/{name}/{name}.json`, not here; the JSON schemas mirror this pattern as `WiderRegionName`.
 */
final class WiderRegionName
{
    public const PATTERN = '/^[A-Z][A-Za-z]*( [A-Z][A-Za-z]*)*$/';

    public static function isValid(string $name): bool
    {
        return 1 === preg_match(self::PATTERN, $name);
    }
}
