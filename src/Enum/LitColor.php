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
     *
     * @param string $locale The locale for the translation.
     * @return string The translated liturgical color.
     */
    public function i18n(string $locale): string
    {
        $isLatin = in_array($locale, [LitLocale::LATIN, LitLocale::LATIN_PRIMARY_LANGUAGE], true);
        return match ($this) {
            /**translators: context = liturgical color */
            LitColor::GREEN  => ( $isLatin ? 'viridis' : _('green') ),
            /**translators: context = liturgical color */
            LitColor::PURPLE => ( $isLatin ? 'purpura' : _('purple') ),
            /**translators: context = liturgical color */
            LitColor::WHITE  => ( $isLatin ? 'albus'   : _('white') ),
            /**translators: context = liturgical color */
            LitColor::RED    => ( $isLatin ? 'ruber'   : _('red') ),
            /**translators: context = liturgical color */
            LitColor::PINK   => ( $isLatin ? 'rosea'   : _('pink') )
        };
    }
}
