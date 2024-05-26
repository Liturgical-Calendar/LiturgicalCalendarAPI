<?php

class CalendarType
{
    const CIVIL        = "CIVIL";
    const LITURGICAL   = "LITURGICAL";
    public static array $values = [ "CIVIL", "LITURGICAL" ];

    public static function isValid(string $value)
    {
        return in_array($value, self::$values);
    }
}
