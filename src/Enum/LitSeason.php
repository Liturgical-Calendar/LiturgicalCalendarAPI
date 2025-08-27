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
     * @param string $locale The locale for the translation.
     * @return string The translated liturgical season value.
     */
    public function i18n(string $locale): string
    {
        $isLatin = in_array($locale, [LitLocale::LATIN, LitLocale::LATIN_PRIMARY_LANGUAGE], true);
        return match ($this) {
            /**translators: context = liturgical season */
            LitSeason::ADVENT         => $isLatin ? 'Tempus Adventus'     : _('Advent'),
            /**translators: context = liturgical season */
            LitSeason::CHRISTMAS      => $isLatin ? 'Tempus Nativitatis'  : _('Christmas'),
            /**translators: context = liturgical season */
            LitSeason::LENT           => $isLatin ? 'Tempus Quadragesima' : _('Lent'),
            /**translators: context = liturgical season */
            LitSeason::EASTER_TRIDUUM => $isLatin ? 'Triduum Paschale'    : _('Easter Triduum'),
            /**translators: context = liturgical season */
            LitSeason::EASTER         => $isLatin ? 'Tempus Paschale'     : _('Easter'),
            /**translators: context = liturgical season */
            LitSeason::ORDINARY_TIME  => $isLatin ? 'Tempus per Annum'    : _('Ordinary Time')
        };
    }
}
