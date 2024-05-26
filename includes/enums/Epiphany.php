<?php

class Epiphany
{
    const SUNDAY_JAN2_JAN8  = "SUNDAY_JAN2_JAN8";
    const JAN6              = "JAN6";
    public static array $values = [ "SUNDAY_JAN2_JAN8", "JAN6" ];

    public static function isValid($value)
    {
        return in_array($value, self::$values);
    }
}
