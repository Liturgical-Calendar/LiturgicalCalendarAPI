<?php

/**
 * enum RequestContentType
 * Represents all possible Content Types
 * for a Request that the API might receive
 */

namespace LiturgicalCalendar\Api\Enum;

class RequestContentType
{
    public const JSON           = "application/json";
    public const YAML           = "application/yaml";
    public const FORMDATA       = "application/x-www-form-urlencoded";
    public static array $values = [ "application/json", "application/yaml", "application/x-www-form-urlencoded" ];

    public static function isValid($value)
    {
        return in_array($value, self::$values);
    }
}
