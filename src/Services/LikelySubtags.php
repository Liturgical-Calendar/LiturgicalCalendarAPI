<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Utilities;

/**
 * Single reader and cache for the CLDR `supplemental.likelySubtags` data
 * (`jsondata/likelySubtags.json`), shared by the two consumers that need it in
 * different shapes (#749):
 *
 * - {@see maximize()} returns the full maximized tag ('en' → 'en_Latn_US'), used by
 *   CalendarParams to normalize a region-less request locale for the echoed
 *   `settings.locale`.
 * - {@see regionFor()} returns the region subtag only ('en' → 'US'), used by
 *   LocaleConfigurator to build a glibc-acceptable `lang_REGION` candidate — glibc
 *   rejects script-bearing names such as "en_Latn_US".
 *
 * The map is read from disk and cached once per process.
 */
final class LikelySubtags
{
    /** @var array<string,string>|null CLDR map: language subtag => maximized tag ("lang-Script-Region"). */
    private static ?array $map = null;

    /**
     * Maximize a language subtag to its canonicalized likely tag
     * (e.g. 'en' → 'en_Latn_US'). Returns $language unchanged when CLDR has no
     * entry for it, or when the entry fails to canonicalize.
     */
    public static function maximize(string $language): string
    {
        $maximized = self::map()[$language] ?? null;
        if ($maximized === null) {
            return $language;
        }

        return \Locale::canonicalize($maximized) ?? $language;
    }

    /**
     * Resolve the likely region subtag for a region-less language
     * (e.g. 'en' → 'US', 'pt' → 'BR'). Returns '' when unknown.
     */
    public static function regionFor(string $language): string
    {
        $maximized = self::map()[$language] ?? null;
        if ($maximized === null) {
            return '';
        }

        $canonical = \Locale::canonicalize($maximized);
        $region    = \Locale::getRegion(( $canonical === null || $canonical === '' ) ? $maximized : $canonical);
        return ( $region === null ) ? '' : $region;
    }

    /**
     * @return array<string,string>
     */
    private static function map(): array
    {
        if (self::$map === null) {
            /** @var array{supplemental:array{likelySubtags:array<string,string>}} $data */
            $data      = Utilities::jsonFileToArray(JsonData::FOLDER->path() . '/likelySubtags.json');
            self::$map = $data['supplemental']['likelySubtags'];
        }

        return self::$map;
    }
}
