<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Utilities;

/**
 * Deterministically applies the process-global locale state — setlocale(LC_ALL),
 * the LANGUAGE env var (which glibc gettext() reads above LC_MESSAGES), and ICU's
 * default — for a single request, in a leak-free way.
 *
 * Region resolution uses CLDR likely subtags (jsondata/likelySubtags.json) so a
 * region-less request locale (e.g. 'en', 'fr') maps to the installed system locale
 * ('en_US', 'fr_FR'). A real language whose system locale is not installed at all
 * throws — it must never silently fall through to English. Latin ('la'/'la_VA') is
 * the sole special case: it cannot be installed, so it takes the reset branch and
 * never throws (downstream code emits hardcoded Latin).
 *
 * Centralizes logic previously duplicated across CalendarHandler::prepareL10N(),
 * EventsHandler::setLocale(), and FerialEventNameGenerator::initializeGettext() (#745).
 */
final class LocaleConfigurator
{
    /** @var array<string,string>|null Cached CLDR likelySubtags map (language => "lang-Script-Region"). */
    private static ?array $likelySubtags = null;

    /**
     * Apply the process-global locale for the given request locale and return the
     * resolved runtime information.
     *
     * @param string $requestLocale The request locale (e.g. 'it_IT', 'en', 'la_VA').
     * @throws ServiceUnavailableException When a non-Latin language has no installed system locale.
     */
    public static function configure(string $requestLocale): ConfiguredLocale
    {
        $canonical       = \Locale::canonicalize($requestLocale);
        $canonical       = ( $canonical === null || $canonical === '' ) ? $requestLocale : $canonical;
        $primaryLanguage = \Locale::getPrimaryLanguage($canonical);
        $primaryLanguage = ( $primaryLanguage === null || $primaryLanguage === '' ) ? $canonical : $primaryLanguage;

        if ($primaryLanguage === LitLocale::LATIN_PRIMARY_LANGUAGE) {
            self::reset(LitLocale::LATIN_PRIMARY_LANGUAGE);
            return new ConfiguredLocale(LitLocale::LATIN_PRIMARY_LANGUAGE, LitLocale::LATIN_PRIMARY_LANGUAGE, true);
        }

        $region = \Locale::getRegion($canonical);
        if ($region === null || $region === '') {
            $region = self::likelyRegion($primaryLanguage);
        }

        $candidates = [];
        if ($region !== '') {
            $langRegion = $primaryLanguage . '_' . $region;
            $candidates = [$langRegion . '.utf8', $langRegion . '.UTF-8', $langRegion];
        }
        $candidates = array_merge($candidates, [
            $primaryLanguage . '.utf8',
            $primaryLanguage . '.UTF-8',
            $primaryLanguage,
        ]);

        $runtimeLocale = setlocale(LC_ALL, $candidates);
        if ($runtimeLocale === false) {
            throw new ServiceUnavailableException('Could not set locale to ' . $requestLocale . '.');
        }

        // Example: "it_IT.UTF-8" → "it_IT"
        $normalizedLocale = strtok($runtimeLocale, '.') ?: $runtimeLocale;
        if ($normalizedLocale === 'C' || $normalizedLocale === 'POSIX') {
            $normalizedLocale = $region !== '' ? $primaryLanguage . '_' . $region : $primaryLanguage;
        }

        // Pin gettext's catalog for THIS request, overriding any LANGUAGE a prior
        // request in this persistent worker left behind (glibc reads it above LC_MESSAGES).
        $languageEnv = implode(':', array_unique([
            $runtimeLocale,
            $normalizedLocale,
            $primaryLanguage,
            'en',
        ]));
        putenv("LANGUAGE={$languageEnv}");
        \Locale::setDefault($normalizedLocale);

        return new ConfiguredLocale($primaryLanguage, $normalizedLocale, false);
    }

    /**
     * Reset the process-global locale state so gettext output falls through to the
     * untranslated (English) msgid base, and update ICU's default. Used for Latin.
     */
    private static function reset(string $icuDefaultLocale): void
    {
        setlocale(LC_ALL, 'C');
        putenv('LANGUAGE');
        \Locale::setDefault($icuDefaultLocale);
    }

    /**
     * Resolve the likely region subtag for a region-less language via CLDR likely
     * subtags (e.g. 'en' → 'US', 'pt' → 'BR'). Returns '' when unknown.
     *
     * Only the region subtag is used: glibc rejects script-bearing locale names
     * like "en_Latn_US", so the caller builds "en_US" from language + region.
     */
    private static function likelyRegion(string $language): string
    {
        if (self::$likelySubtags === null) {
            /** @var array{supplemental:array{likelySubtags:array<string,string>}} $data */
            $data                = Utilities::jsonFileToArray(JsonData::FOLDER->path() . '/likelySubtags.json');
            self::$likelySubtags = $data['supplemental']['likelySubtags'];
        }

        $maximized = self::$likelySubtags[$language] ?? null;
        if ($maximized === null) {
            return '';
        }

        $canonical = \Locale::canonicalize($maximized);
        $region    = \Locale::getRegion(( $canonical === null || $canonical === '' ) ? $maximized : $canonical);
        return ( $region === null ) ? '' : $region;
    }
}
