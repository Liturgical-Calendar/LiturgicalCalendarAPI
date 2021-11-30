<?php

class REQUEST_METHOD {
    const GET       = "GET";    //Read
    const POST      = "POST";   //Create / read
    const PUT       = "PUT";    //Update / replace
    const PATCH     = "PATCH";  //Update / modify
    const DELETE    = "DELETE"; //Delete
    public static array $values = [ "GET", "POST", "PUT", "PATCH", "DELETE" ];

    public static function isValid( $value ) {
        return in_array( $value, self::$values );
    }
}
