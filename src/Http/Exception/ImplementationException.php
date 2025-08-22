<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;

class ImplementationException extends ApiException
{
    public function __construct(string $message = 'Not implemented', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            StatusCode::NOT_IMPLEMENTED->value,
            'https://datatracker.ietf.org/doc/html/rfc9110#name-501-not-implemented',
            StatusCode::NOT_IMPLEMENTED->reason(),
            $previous
        );
    }
}
