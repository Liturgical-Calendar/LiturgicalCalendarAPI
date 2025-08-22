<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;

class MethodNotAllowedException extends ApiException
{
    public function __construct(string $message = 'Method not allowed', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            StatusCode::METHOD_NOT_ALLOWED->value,
            'https://datatracker.ietf.org/doc/html/rfc9110#name-406-not-acceptable',
            StatusCode::METHOD_NOT_ALLOWED->reason(),
            $previous
        );
    }
}
