<?php

namespace LitCal\enum;

class CorpusChristi
{
    public const THURSDAY          = "THURSDAY";
    public const SUNDAY            = "SUNDAY";
    public static array $values = [ "THURSDAY", "SUNDAY" ];

    public static function isValid($value)
    {
        return in_array($value, self::$values);
    }
}
