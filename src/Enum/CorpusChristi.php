<?php

namespace LiturgicalCalendar\Api\Enum;

class CorpusChristi
{
    public const THURSDAY = 'THURSDAY';
    public const SUNDAY   = 'SUNDAY';

    /**
     * @var string[] An array of valid Corpus Christi values.
     */
    public static array $values = [ 'THURSDAY', 'SUNDAY' ];

    /**
     * Checks if the given string is a valid Corpus Christi value.
     *
     * @param string $value The value to validate.
     * @return bool True if the value is valid, false otherwise.
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::$values);
    }
}
