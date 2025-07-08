<?php

namespace LiturgicalCalendar\Api\Enum;

class Ascension
{
    public const THURSDAY       = 'THURSDAY';
    public const SUNDAY         = 'SUNDAY';
    public static array $values = [ 'THURSDAY', 'SUNDAY' ];

    public static function isValid($value)
    {
        return in_array($value, self::$values);
    }
}
