<?php

namespace LiturgicalCalendar\Api\Enum;

class LitLocale
{
    public const LATIN                     = 'la_VA';
    public const LATIN_PRIMARY_LANGUAGE    = 'la';
    public static string $PRIMARY_LANGUAGE = 'la';

    /** @var string[] */
    public static array $values = [ 'la', 'la_VA' ];

    /** @var string[] */
    public static array $AllAvailableLocales = [];

    /**
     * Check if the given locale is valid.
     *
     * @param string $value The locale value to validate.
     * @return bool True if the locale is valid, false otherwise.
     */
    public static function isValid($value): bool
    {
        self::init();
        return in_array($value, self::$values) || in_array($value, self::$AllAvailableLocales);
    }

    /**
     * Check if the given array of locales is valid.
     *
     * @param string[] $values The array of locale values to validate.
     * @return bool True if all locales are valid, false otherwise.
     */
    public static function areValid(array $values): bool
    {
        foreach ($values as $value) {
            if (!self::isValid($value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get the list of locales supported by the API.
     *
     * This method returns an array of all supported locales, which are the
     * locales that are valid for use in the API. The list of supported locales
     * includes both the set of locales that are built-in to the API, plus any
     * additional locales that are available in the ICU data installed on the
     * server.
     *
     * @return string[] The list of supported locales.
     */
    public static function getSupportedLocales(): array
    {
        return self::$values + self::$AllAvailableLocales;
    }

    /**
     * Initializes the list of available locales.
     *
     * This method loads the list of locales from the ICU data available in PHP.
     * It then filters out the "POSIX" locale, which is not a valid regional locale.
     */
    public static function init(): void
    {
        if (empty(self::$AllAvailableLocales)) {
            $getLocales = \ResourceBundle::getLocales('');
            if ($getLocales === false) {
                throw new \RuntimeException('Failed to retrieve locales from ResourceBundle.');
            }
            self::$AllAvailableLocales = array_filter($getLocales, function ($value) {
                return strpos($value, 'POSIX') === false;
            });
        }
    }
}
