<?php

namespace LiturgicalCalendar\Api\Enum;

class Ascension
{
    public const THURSDAY = 'THURSDAY';
    public const SUNDAY   = 'SUNDAY';

    /** @var string[] */
    public static array $values = [ 'THURSDAY', 'SUNDAY' ];

    /**
     * Determines if the given string is a valid Ascension value.
     *
     * @param string $value The value to test.
     * @return bool True if the value is valid, otherwise false.
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::$values);
    }
}
