<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;

class ValidationException extends ApiException
{
    public function __construct(string $message = 'Method not allowed')
    {
        parent::__construct(
            $message,
            StatusCode::METHOD_NOT_ALLOWED->value,
            'https://example.com/problems/validation-error',
            'Request Method Error'
        );
    }
}
