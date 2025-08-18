<?php

namespace LiturgicalCalendar\Api\Http\Enum;

use LiturgicalCalendar\Api\Enum\EnumToArrayTrait;

/**
 * Represents all possible Request Content Types
 * for a Request that the Liturgical Calendar API might receive
 */
enum RequestContentType: string
{
    use EnumToArrayTrait;

    case JSON      = 'application/json';
    case YAML      = 'application/yaml';
    case XML       = 'application/xml';
    case FORMDATA  = 'application/x-www-form-urlencoded';
    case MULTIPART = 'multipart/form-data';
}
