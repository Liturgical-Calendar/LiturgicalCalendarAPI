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
     * @param LitColor $color The liturgical color to translate.
     * @param string $locale The locale for the translation.
     * @return string The translated liturgical color.
     */
    public static function i18n(LitColor $color, string $locale): string
    {
        switch ($color) {
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
