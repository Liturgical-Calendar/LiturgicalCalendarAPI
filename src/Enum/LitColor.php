<?php

namespace LiturgicalCalendar\Api\Enum;

class LitColor
{
    public const GREEN          = "green";
    public const PURPLE         = "purple";
    public const WHITE          = "white";
    public const RED            = "red";
    public const PINK           = "pink";
    public static array $values = [ "green", "purple", "white", "red", "pink" ];

    public static function isValid(string $value)
    {
        if (strpos($value, ',')) {
            return self::areValid(explode(',', $value));
        }
        return in_array($value, self::$values);
    }

    public static function areValid(array $values)
    {
        return empty(array_diff($values, self::$values));
    }

    public static function i18n(string $value, string $locale): string
    {
        switch ($value) {
            case self::GREEN:
                /**translators: context = liturgical color */
                return $locale === LitLocale::LATIN ? 'viridis'     : _("green");
            case self::PURPLE:
                /**translators: context = liturgical color */
                return $locale === LitLocale::LATIN ? 'purpura'     : _("purple");
            case self::WHITE:
                /**translators: context = liturgical color */
                return $locale === LitLocale::LATIN ? 'albus'       : _("white");
            case self::RED:
                /**translators: context = liturgical color */
                return $locale === LitLocale::LATIN ? 'ruber'       : _("red");
            case self::PINK:
                /**translators: context = liturgical color */
                return $locale === LitLocale::LATIN ? 'rosea'       : _("pink");
            default:
                /**translators: context = liturgical color: unsupported value */
                return $locale === LitLocale::LATIN ? 'ignotus'     : _("unknown");
        }
    }
}
