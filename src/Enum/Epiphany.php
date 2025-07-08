<?php

namespace LiturgicalCalendar\Api\Enum;

class Epiphany
{
    public const SUNDAY_JAN2_JAN8 = 'SUNDAY_JAN2_JAN8';
    public const JAN6             = 'JAN6';

    /**
     * @var string[] An array of valid Epiphany values.
     */
    public static array $values = [ 'SUNDAY_JAN2_JAN8', 'JAN6' ];

    /**
     * Checks if the given string is a valid Epiphany value.
     *
     * @param string $value The value to validate.
     * @return bool True if the value is valid, otherwise false.
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::$values);
    }
}
