<?php

namespace LiturgicalCalendar\Api\Enum;

enum ParamError: int
{
    case NONE                   = 0;
    case INVALID_LOCALE         = 1;
    case INVALID_YEAR           = 2;
    case INVALID_REGION         = 3;
    case MISSING_REQUIRED_PARAM = 4;
    case UNKNOWN_ERROR          = 5;

    public function getMessage(): string
    {
        return match ($this) {
            self::NONE => 'No error',
            self::INVALID_LOCALE => 'Invalid locale provided',
            self::INVALID_YEAR => 'Invalid year provided',
            self::INVALID_REGION => 'Invalid region provided',
            self::MISSING_REQUIRED_PARAM => 'Missing required parameter',
            self::UNKNOWN_ERROR => 'An unknown error occurred',
        };
    }
}
