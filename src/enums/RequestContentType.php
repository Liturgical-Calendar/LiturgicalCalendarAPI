<?php

/**
 * enum RequestContentType
 * Represents all possible Content Types
 * for a Request that the API might receive
 */

namespace Johnrdorazio\LitCal\enum;

class RequestContentType
{
    public const JSON      = "application/json";
    public const FORMDATA  = "application/x-www-form-urlencoded";
    public static array $values = [ "application/json", "application/x-www-form-urlencoded" ];

    public static function isValid($value)
    {
        return in_array($value, self::$values);
    }
}
