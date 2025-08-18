<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;

class ValidationException extends ApiException
{
    public function __construct(string $message = 'Validation failed')
    {
        parent::__construct(
            $message,
            StatusCode::BAD_REQUEST->value,
            'https://example.com/problems/validation-error',
            'Validation Error'
        );
    }
}
