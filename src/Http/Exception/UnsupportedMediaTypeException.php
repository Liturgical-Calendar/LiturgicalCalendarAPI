<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;

class UnsupportedMediaTypeException extends ApiException
{
    public function __construct(string $message = 'Content-Type of request is not supported')
    {
        parent::__construct(
            $message,
            StatusCode::UNSUPPORTED_MEDIA_TYPE->value,
            'https://example.com/problems/validation-error',
            StatusCode::UNSUPPORTED_MEDIA_TYPE->reason()
        );
    }
}
