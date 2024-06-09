<?php

namespace Johnrdorazio\LitCal\enum;

class LitFeastType
{
    public const FIXED     = "fixed";
    public const MOBILE    = "mobile";
    public static array $values = [ "fixed", "mobile" ];

    public static function isValid(string $value)
    {
        return in_array($value, self::$values);
    }
}
