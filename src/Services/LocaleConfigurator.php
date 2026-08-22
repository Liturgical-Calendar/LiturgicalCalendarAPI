<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;

/**
 * Deterministically applies the process-global locale state — setlocale(LC_ALL),
 * the LANGUAGE env var (which glibc gettext() reads above LC_MESSAGES), and ICU's
 * default — for a single request, in a leak-free way.
 *
 * Region resolution uses CLDR likely subtags (via {@see LikelySubtags}) so a
 * region-less request locale (e.g. 'en', 'fr') maps to the installed system locale
 * ('en_US', 'fr_FR'). A real language whose system locale is not installed at all
 * throws — it must never silently fall through to English. Latin ('la'/'la_VA') is
 * the sole special case: it cannot be installed, so it takes the reset branch and
 * never throws (downstream code emits hardcoded Latin).
 *
 * Centralizes logic previously duplicated across CalendarHandler::prepareL10N(),
 * EventsHandler::setLocale(), and FerialEventNameGenerator::initializeGettext() (#745).
 * Also publishes the resolved locale to LitLocale::$PRIMARY_LANGUAGE and
 * LitLocale::$RUNTIME_LOCALE, so every route gets them set, not just /calendar (#865).
 */
final class LocaleConfigurator
{
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
            return self::publish(new ConfiguredLocale(LitLocale::LATIN_PRIMARY_LANGUAGE, LitLocale::LATIN_PRIMARY_LANGUAGE, true));
        }

        $region = \Locale::getRegion($canonical);
        if ($region === null || $region === '') {
            $region = LikelySubtags::regionFor($primaryLanguage);
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

        return self::publish(new ConfiguredLocale($primaryLanguage, $normalizedLocale, false));
    }

    /**
     * Publish the resolved locale to the LitLocale statics that downstream code reads,
     * then return it.
     *
     * Done here rather than in each caller so that no caller can forget: CalendarHandler
     * set them by hand while EventsHandler and the temporale path did not, leaving those
     * routes reading whatever a previous request in the same persistent worker left
     * behind — or the mutually inconsistent class defaults ('la' and 'en_US') on a cold
     * worker (#865).
     */
    private static function publish(ConfiguredLocale $configured): ConfiguredLocale
    {
        LitLocale::$PRIMARY_LANGUAGE = $configured->primaryLanguage;
        LitLocale::$RUNTIME_LOCALE   = $configured->runtimeLocale;

        return $configured;
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
}
