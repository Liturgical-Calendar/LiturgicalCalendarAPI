<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;

class ValidationException extends ApiException
{
    public function __construct(string $message = 'Validation Error', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            StatusCode::BAD_REQUEST->value,
            'https://datatracker.ietf.org/doc/html/rfc9110#name-400-bad-request',
            StatusCode::BAD_REQUEST->reason(),
            $previous
        );
    }
}
