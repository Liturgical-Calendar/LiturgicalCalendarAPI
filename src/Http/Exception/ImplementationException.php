<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;

class ImplementationException extends ApiException
{
    public function __construct(string $message = 'Not implemented')
    {
        parent::__construct(
            $message,
            StatusCode::NOT_IMPLEMENTED->value,
            'https://example.com/problems/implementation-error',
            StatusCode::NOT_IMPLEMENTED->reason()
        );
    }
}
