<?php

namespace LiturgicalCalendar\Api\Enum;

class RequestMethod
{
    public const POST           = 'POST';   //Create / read
    public const GET            = 'GET';    //Read
    public const PATCH          = 'PATCH';  //Update / modify
    public const PUT            = 'PUT';    //Update / replace
    public const DELETE         = 'DELETE'; //Delete
    public const OPTIONS        = 'OPTIONS';
    public static array $values = [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' ];

    public static function isValid($value)
    {
        return in_array($value, self::$values);
    }
}
