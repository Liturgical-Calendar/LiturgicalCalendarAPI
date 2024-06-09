<?php

namespace Johnrdorazio\LitCal\Enum;

class CacheDuration
{
    public const DAY       = "DAY";
    public const WEEK      = "WEEK";
    public const MONTH     = "MONTH";
    public const YEAR      = "YEAR";
    public static array $values = [ "DAY", "WEEK", "MONTH", "YEAR" ];

    public static function isValid($value)
    {
        return in_array($value, self::$values);
    }
}
