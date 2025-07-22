<?php

namespace LiturgicalCalendar\Api\Enum;

/**
 * EnumToArrayTrait
 *
 * Provides a set of methods for working with enum cases, including retrieving their names, values, and converting them to arrays.
 */
trait EnumToArrayTrait
{
    /**
     * Retrieves an array of the names of all enum cases.
     *
     * @return array<string> An array containing the names of the enum cases.
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }

    /**
     * Returns an array of all the values of the enum
     *
     * @return array<string|int>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Return an array of either the names or values of the enum, depending on which is available.
     *
     * If both names and values are available, the array will have the names as keys and the values as values.
     *
     * @return array<string|int>|array<string|int,string>
     */
    public static function asArray(): array
    {
        if (empty(self::values())) {
            return self::names();
        }

        if (empty(self::names())) {
            return self::values();
        }

        return array_column(self::cases(), 'value', 'name');
    }

    /**
     * Check if the given string is a valid enum value.
     *
     * @param string $value The value to check.
     * @return bool True if the value is valid, otherwise false.
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values());
    }

    /**
     * Checks if all of the given values are valid enum values.
     *
     * @param array<string> $values The array of values to check.
     * @return bool True if all of the values are valid, otherwise false.
     */
    public static function areValid(array $values): bool
    {
        return array_reduce($values, function ($carry, $value) {
            return $carry && self::isValid($value);
        }, true);
    }
}
