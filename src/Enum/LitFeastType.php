<?php

namespace LiturgicalCalendar\Api\Enum;

class LitFeastType
{
    public const FIXED  = 'fixed';
    public const MOBILE = 'mobile';

    /** @var string[] */
    public static array $values = [ 'fixed', 'mobile' ];

    /**
     * Checks if the given string is a valid "Feast Type".
     *
     * @param string $value The value to check.
     * @return bool True if the value is valid, otherwise false.
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::$values);
    }
}
