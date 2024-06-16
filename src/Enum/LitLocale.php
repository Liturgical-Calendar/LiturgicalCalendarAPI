<?php

namespace Johnrdorazio\LitCal\Enum;

class LitLocale
{
    public const LATIN                        = "LA";
    public static array $values               = [ "LA" ];
    public static string $PRIMARY_LANGUAGE    = "la";
    public static ?array $AllAvailableLocales = null;

    public static function isValid($value)
    {
        if (null === self::$AllAvailableLocales) {
            self::$AllAvailableLocales = array_filter(\ResourceBundle::getLocales(''), function ($value) {
                return strpos($value, 'POSIX') === false;
            });
        }
        return in_array($value, self::$values) || in_array($value, self::$AllAvailableLocales) || in_array(strtolower($value), self::$AllAvailableLocales);
    }
}
