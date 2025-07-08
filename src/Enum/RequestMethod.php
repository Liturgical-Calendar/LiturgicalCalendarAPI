<?php

namespace LiturgicalCalendar\Api\Enum;

class RequestMethod
{
    public const POST    = 'POST';   //Create / read
    public const GET     = 'GET';    //Read
    public const PATCH   = 'PATCH';  //Update / modify
    public const PUT     = 'PUT';    //Update / replace
    public const DELETE  = 'DELETE'; //Delete
    public const OPTIONS = 'OPTIONS';

    /** @var string[] */
    public static array $values = [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' ];

    /**
     * Check if the given request method is valid.
     *
     * @param string $value The value to check.
     * @return bool True if the value is valid, otherwise false.
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::$values);
    }
}
