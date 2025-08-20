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
            'https://www.rfc-editor.org/rfc/rfc9110.html#name-400-bad-request',
            StatusCode::BAD_REQUEST->reason(),
            $previous
        );
    }
}
