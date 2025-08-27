<?php

namespace LiturgicalCalendar\Api\Enum;

enum LitColor: string
{
    use EnumToArrayTrait;

    case GREEN  = 'green';
    case PURPLE = 'purple';
    case WHITE  = 'white';
    case RED    = 'red';
    case PINK   = 'pink';

    /**
     * Translates a liturgical color to the specified locale.
     *
     * This method returns the translation of a given liturgical color based on
     * the specified locale. If the locale is Latin, it returns the Latin
     * translation; otherwise, it returns the translation in the current locale.
     * If the color value is not supported, a default "unknown" value is returned.
     *
     * @param string $locale The locale for the translation.
     * @return string The translated liturgical color.
     */
    public function i18n(string $locale): string
    {
        return match ($this) {
            /**translators: context = liturgical color */
            LitColor::GREEN  => ( in_array($locale, [LitLocale::LATIN, LitLocale::LATIN_PRIMARY_LANGUAGE]) ? 'viridis' : _('green') ),
            /**translators: context = liturgical color */
            LitColor::PURPLE => ( in_array($locale, [LitLocale::LATIN, LitLocale::LATIN_PRIMARY_LANGUAGE]) ? 'purpura' : _('purple') ),
            /**translators: context = liturgical color */
            LitColor::WHITE  => ( in_array($locale, [LitLocale::LATIN, LitLocale::LATIN_PRIMARY_LANGUAGE]) ? 'albus'   : _('white') ),
            /**translators: context = liturgical color */
            LitColor::RED    => ( in_array($locale, [LitLocale::LATIN, LitLocale::LATIN_PRIMARY_LANGUAGE]) ? 'ruber'   : _('red') ),
            /**translators: context = liturgical color */
            LitColor::PINK   => ( in_array($locale, [LitLocale::LATIN, LitLocale::LATIN_PRIMARY_LANGUAGE]) ? 'rosea'   : _('pink') )
        };
    }
}
