<?php

namespace LiturgicalCalendar\Api\Enum;

class LitSeason
{
    public const ADVENT         = 'ADVENT';
    public const CHRISTMAS      = 'CHRISTMAS';
    public const LENT           = 'LENT';
    public const EASTER_TRIDUUM = 'EASTER_TRIDUUM';
    public const EASTER         = 'EASTER';
    public const ORDINARY_TIME  = 'ORDINARY_TIME';

    /** @var string[] */
    public static array $values = [ 'ADVENT', 'CHRISTMAS', 'LENT', 'EASTER_TRIDUUM', 'EASTER', 'ORDINARY_TIME' ];

    /**
     * Check if the given string is a valid liturgical season value.
     *
     * @param string $value The value to check.
     * @return bool True if the value is valid, false otherwise.
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::$values);
    }

    /**
     * Translate a liturgical season value into the specified locale.
     *
     * @param string $value The liturgical season value to translate.
     * @param string $locale The locale for the translation.
     * @return string The translated liturgical season value.
     */
    public static function i18n(string $value, string $locale): string
    {
        switch ($value) {
            case self::ADVENT:
                /**translators: context = liturgical season */
                return $locale === LitLocale::LATIN ? 'Tempus Adventus'     : _('Advent');
            case self::CHRISTMAS:
                /**translators: context = liturgical season */
                return $locale === LitLocale::LATIN ? 'Tempus Nativitatis'  : _('Christmas');
            case self::LENT:
                /**translators: context = liturgical season */
                return $locale === LitLocale::LATIN ? 'Tempus Quadragesima' : _('Lent');
            case self::EASTER_TRIDUUM:
                /**translators: context = liturgical season */
                return $locale === LitLocale::LATIN ? 'Triduum Paschale'     : _('Easter Triduum');
            case self::EASTER:
                /**translators: context = liturgical season */
                return $locale === LitLocale::LATIN ? 'Tempus Paschale'     : _('Easter');
            case self::ORDINARY_TIME:
                /**translators: context = liturgical season */
                return $locale === LitLocale::LATIN ? 'Tempus per Annum'    : _('Ordinary Time');
            default:
                /**translators: context = liturgical season: unsupported value */
                return $locale === LitLocale::LATIN ? 'Tempus Ignotum'     : _('Unknown Season');
        }
    }
}
