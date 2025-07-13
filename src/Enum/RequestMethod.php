<?php

namespace LiturgicalCalendar\Api\Enum;

enum RequestMethod: string
{
    use EnumToArrayTrait;

    case POST    = 'POST';   //Create / read
    case GET     = 'GET';    //Read
    case PATCH   = 'PATCH';  //Update / modify
    case PUT     = 'PUT';    //Update / replace
    case DELETE  = 'DELETE'; //Delete
    case OPTIONS = 'OPTIONS';//Preflight
}
