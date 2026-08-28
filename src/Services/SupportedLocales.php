<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\JsonDataConstants;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Utilities;

/**
 * The curated set of locales this deployment considers officially supported.
 *
 * "Officially supported" is a promise, not a description of what happens to be
 * on disk: an official locale must have a complete set of resources, and missing
 * data for one is a defect that should fail loudly. A locale outside the list is
 * work in progress — partial data for it must degrade quietly, never error.
 *
 * The list previously lived as `CalendarMetadataProvider::FULLY_TRANSLATED_LOCALES`,
 * a private constant referenced exactly once, to filter the `/calendars` response.
 * It described the contract without enforcing it anywhere, which is how a decree
 * written with partial lectionary coverage could take down a whole calendar
 * (issues #902, #904). Moving it to a resource makes it curatable and, more
 * importantly, makes it consultable by the code that must honour it.
 *
 * Reading is memoized for the process: the file is small and, unlike calendar
 * source data, is not written by the `/data` endpoints at request time.
 */
final class SupportedLocales
{
    /**
     * Fallback used when the resource cannot be read.
     *
     * Deliberately the historical value of FULLY_TRANSLATED_LOCALES. A deployment
     * whose resource file is missing or corrupt degrades to the long-standing
     * behaviour rather than either supporting nothing (which would make every
     * calendar unofficial and silently disable enforcement) or supporting
     * everything (which would make every incomplete locale throw).
     *
     * @var list<string>
     */
    public const FALLBACK = ['en', 'fr', 'it', 'la', 'nl'];

    /** @var list<string>|null */
    private static ?array $official = null;

    /**
     * The locales officially supported for the General Roman Calendar.
     *
     * @return list<string>
     */
    public static function official(): array
    {
        if (self::$official !== null) {
            return self::$official;
        }

        try {
            $data = Utilities::jsonFileToObject(self::resourcePath());

            $grc      = $data->general_roman_calendar ?? null;
            $declared = ( $grc instanceof \stdClass && is_array($grc->official ?? null) )
                ? $grc->official
                : [];

            $locales = array_values(array_filter(
                $declared,
                static fn (mixed $v): bool => is_string($v) && $v !== ''
            ));
        } catch (\Throwable $e) {
            error_log('SupportedLocales: falling back to the built-in list — ' . $e->getMessage());
            $locales = [];
        }

        return self::$official = ( $locales === [] ? self::FALLBACK : $locales );
    }

    /**
     * Whether $locale is officially supported.
     *
     * Compares on the primary language subtag, so `en_US` and `en` both match the
     * declared `en`: the resource lists languages, while requests may carry a
     * full locale.
     */
    public static function isOfficial(string $locale): bool
    {
        if ($locale === '') {
            return false;
        }

        $primary = \Locale::getPrimaryLanguage($locale) ?? $locale;

        return in_array($primary, self::official(), true)
            || in_array($locale, self::official(), true);
    }

    /**
     * Absolute path to the curated resource.
     *
     * `JsonData::…->path()` cannot be used here: it reads `Router::$apiFilePath`,
     * a typed static that is only initialised once a request is being routed. This
     * service is also consulted from unit tests and CLI tooling, where accessing it
     * raises "must not be accessed before initialization" — which would silently
     * route every such caller to the fallback list and make enforcement untested.
     */
    private static function resourcePath(): string
    {
        $root = isset(Router::$apiFilePath) && Router::$apiFilePath !== ''
            ? Router::$apiFilePath
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;

        return $root . JsonDataConstants::SUPPORTED_LOCALES_FILE;
    }

    /**
     * Reset the memoized list. Tests only.
     */
    public static function reset(): void
    {
        self::$official = null;
    }
}
