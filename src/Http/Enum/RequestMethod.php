<?php

namespace LiturgicalCalendar\Api\Http\Enum;

use LiturgicalCalendar\Api\Enum\EnumToArrayTrait;

enum RequestMethod: string
{
    use EnumToArrayTrait;

    case GET     = 'GET';     // Read
    case POST    = 'POST';    // Read
    case PUT     = 'PUT';     // Create
    case PATCH   = 'PATCH';   // Update / modify
    case DELETE  = 'DELETE';  // Delete
    case OPTIONS = 'OPTIONS'; // Preflight
    case HEAD    = 'HEAD';    // Read headers
    case CONNECT = 'CONNECT'; // Tunnel
    case TRACE   = 'TRACE';   // Trace route
}
