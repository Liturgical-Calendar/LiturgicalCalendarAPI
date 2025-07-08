<?php

/**
 * enum RequestContentType
 * Represents all possible Content Types
 * for a Request that the API might receive
 */

namespace LiturgicalCalendar\Api\Enum;

class RequestContentType
{
    public const JSON     = 'application/json';
    public const YAML     = 'application/yaml';
    public const FORMDATA = 'application/x-www-form-urlencoded';

    /** @var string[] */
    public static array $values = [ 'application/json', 'application/yaml', 'application/x-www-form-urlencoded' ];

    /**
     * Check if the given value is a valid content type for a request.
     *
     * @param string $value The value to check.
     * @return bool True if the value is valid, otherwise false.
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::$values);
    }
}
