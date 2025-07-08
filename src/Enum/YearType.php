<?php

namespace LiturgicalCalendar\Api\Enum;

class YearType
{
    public const CIVIL      = 'CIVIL';
    public const LITURGICAL = 'LITURGICAL';

    /** @var string[] */
    public static array $values = [ 'CIVIL', 'LITURGICAL' ];

    /**
     * Check if the given year type is valid.
     *
     * @param string $value The year type to check.
     * @return bool True if the year type is valid, otherwise false.
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::$values);
    }
}
