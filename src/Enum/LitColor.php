<?php

namespace LiturgicalCalendar\Api\Enum;

class LitColor
{
    public const GREEN  = 'green';
    public const PURPLE = 'purple';
    public const WHITE  = 'white';
    public const RED    = 'red';
    public const PINK   = 'pink';

    /**
     * @var string[] An array of valid liturgical colors.
     */
    public static array $values = [ 'green', 'purple', 'white', 'red', 'pink' ];

    /**
     * Checks if the given string is a valid liturgical color.
     *
     * If the given string contains a comma, it is split into an array and passed
     * to {@see LitColor::areValid()}.
     * @param string $value The value to check.
     * @return bool True if the value is valid, otherwise false.
     */
    public static function isValid(string $value): bool
    {
        if (strpos($value, ',')) {
            return self::areValid(explode(',', $value));
        }
        return in_array($value, self::$values);
    }

    /**
     * Returns true if all of the given values are valid liturgical colors.
     * @param array<string> $values The values to check.
     * @return bool True if all of the values are valid, otherwise false.
     */
    public static function areValid(array $values)
    {
        return empty(array_diff($values, self::$values));
    }

    /**
     * Translates a liturgical color to the specified locale.
     *
     * This method returns the translation of a given liturgical color based on
     * the specified locale. If the locale is Latin, it returns the Latin
     * translation; otherwise, it returns the translation in the current locale.
     * If the color value is not supported, a default "unknown" value is returned.
     *
     * @param string $value The liturgical color to translate.
     * @param string $locale The locale for the translation.
     * @return string The translated liturgical color.
     */
    public static function i18n(string $value, string $locale): string
    {
        switch ($value) {
            case self::GREEN:
                /**translators: context = liturgical color */
                return $locale === LitLocale::LATIN ? 'viridis'     : _('green');
            case self::PURPLE:
                /**translators: context = liturgical color */
                return $locale === LitLocale::LATIN ? 'purpura'     : _('purple');
            case self::WHITE:
                /**translators: context = liturgical color */
                return $locale === LitLocale::LATIN ? 'albus'       : _('white');
            case self::RED:
                /**translators: context = liturgical color */
                return $locale === LitLocale::LATIN ? 'ruber'       : _('red');
            case self::PINK:
                /**translators: context = liturgical color */
                return $locale === LitLocale::LATIN ? 'rosea'       : _('pink');
            default:
                /**translators: context = liturgical color: unsupported value */
                return $locale === LitLocale::LATIN ? 'ignotus'     : _('unknown');
        }
    }
}
