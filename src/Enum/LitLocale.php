<?php

namespace LiturgicalCalendar\Api\Enum;

class LitLocale
{
    public const LATIN                     = 'la_VA';
    public const LATIN_PRIMARY_LANGUAGE    = 'la';
    public static string $PRIMARY_LANGUAGE = 'la';

    /** @var array<string> */
    public static array $values = [ 'la', 'la_VA' ];

    /** @var array<string>|null */
    public static ?array $AllAvailableLocales = null;

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
     * @param array<string> $values The array of locale values to validate.
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
     * Initializes the list of available locales.
     *
     * This method loads the list of locales from the ICU data available in PHP.
     * It then filters out the "POSIX" locale, which is not a valid regional locale.
     */
    public static function init(): void
    {
        if (null === self::$AllAvailableLocales) {
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
