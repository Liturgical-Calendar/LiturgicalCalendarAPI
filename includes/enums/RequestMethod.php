<?php

class RequestMethod
{
    const POST      = "POST";   //Create / read
    const GET       = "GET";    //Read
    const PATCH     = "PATCH";  //Update / modify
    const PUT       = "PUT";    //Update / replace
    const DELETE    = "DELETE"; //Delete
    const OPTIONS   = "OPTIONS";
    public static array $values = [ "GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS" ];

    public static function isValid($value)
    {
        return in_array($value, self::$values);
    }
}
