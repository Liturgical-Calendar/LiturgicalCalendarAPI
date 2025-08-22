<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;

class ResourceConflictException extends ApiException
{
    public function __construct(string $message = 'Conflicting resource', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            StatusCode::CONFLICT->value,
            'https://datatracker.ietf.org/doc/html/rfc9110#name-409-conflict',
            StatusCode::CONFLICT->reason(),
            $previous
        );
    }
}
