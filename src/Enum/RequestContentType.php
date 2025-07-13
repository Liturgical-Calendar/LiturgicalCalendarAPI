<?php

/**
 * enum RequestContentType
 * Represents all possible Content Types
 * for a Request that the API might receive
 */

namespace LiturgicalCalendar\Api\Enum;

enum RequestContentType: string
{
    use EnumToArrayTrait;

    case JSON     = 'application/json';
    case YAML     = 'application/yaml';
    case FORMDATA = 'application/x-www-form-urlencoded';
}
