<?php

namespace LiturgicalCalendar\Api\Enum;

class YearType
{
    public const CIVIL          = "CIVIL";
    public const LITURGICAL     = "LITURGICAL";
    public static array $values = [ "CIVIL", "LITURGICAL" ];

    public static function isValid(string $value)
    {
        return in_array($value, self::$values);
    }
}
