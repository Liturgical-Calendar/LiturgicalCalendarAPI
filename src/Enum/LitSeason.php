<?php

namespace LiturgicalCalendar\Api\Enum;

use LiturgicalCalendar\Api\Router;

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
     * @param string $locale The locale for the translation.
     * @return string The translated liturgical season value.
     */
    public function i18n(string $locale): string
    {
        return match ($this) {
            /**translators: context = liturgical season */
            LitSeason::ADVENT         => in_array($locale, [LitLocale::LATIN, LitLocale::LATIN_PRIMARY_LANGUAGE]) ? 'Tempus Adventus'     : _('Advent'),
            /**translators: context = liturgical season */
            LitSeason::CHRISTMAS      => in_array($locale, [LitLocale::LATIN, LitLocale::LATIN_PRIMARY_LANGUAGE]) ? 'Tempus Nativitatis'  : _('Christmas'),
            /**translators: context = liturgical season */
            LitSeason::LENT           => in_array($locale, [LitLocale::LATIN, LitLocale::LATIN_PRIMARY_LANGUAGE]) ? 'Tempus Quadragesima' : _('Lent'),
            /**translators: context = liturgical season */
            LitSeason::EASTER_TRIDUUM => in_array($locale, [LitLocale::LATIN, LitLocale::LATIN_PRIMARY_LANGUAGE]) ? 'Triduum Paschale'    : _('Easter Triduum'),
            /**translators: context = liturgical season */
            LitSeason::EASTER         => in_array($locale, [LitLocale::LATIN, LitLocale::LATIN_PRIMARY_LANGUAGE]) ? 'Tempus Paschale'     : _('Easter'),
            /**translators: context = liturgical season */
            LitSeason::ORDINARY_TIME  => in_array($locale, [LitLocale::LATIN, LitLocale::LATIN_PRIMARY_LANGUAGE]) ? 'Tempus per Annum'    : _('Ordinary Time')
        };
    }
}
