<?php

namespace LiturgicalCalendar\Api\Enum;

enum LitSeason: string
{
    use EnumToArrayTrait;

    case ADVENT         = 'ADVENT';
    case CHRISTMAS      = 'CHRISTMAS';
    case LENT           = 'LENT';
    case EASTER_TRIDUUM = 'EASTER_TRIDUUM';
    case EASTER         = 'EASTER';
    case ORDINARY_TIME  = 'ORDINARY_TIME';

    /**
     * Translate a liturgical season value into the specified locale.
     *
     * @param LitSeason $value The liturgical season value to translate.
     * @param string $locale The locale for the translation.
     * @return string The translated liturgical season value.
     */
    public static function i18n(LitSeason $value, string $locale): string
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
