<?php

namespace Johnrdorazio\LitCal\enum;

class LitLocale
{
    public const LATIN                     = "LA";
    public static array $values            = [ "LA" ];
    public static string $PRIMARY_LANGUAGE = "la";

    public static function isValid($value)
    {
        $AllAvailableLocales = array_filter(\ResourceBundle::getLocales(''), function ($value) {
            return strpos($value, 'POSIX') === false;
        });
        return in_array($value, self::$values) || in_array($value, $AllAvailableLocales) || in_array(strtolower($value), $AllAvailableLocales);
    }
}
