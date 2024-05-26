<?php

class LitFeastType
{
    const FIXED     = "fixed";
    const MOBILE    = "mobile";
    public static array $values = [ "fixed", "mobile" ];

    public static function isValid(string $value)
    {
        return in_array($value, self::$values);
    }
}
