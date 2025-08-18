<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;

class AcceptException extends ApiException
{
    public function __construct(string $message = 'Unacceptable Accept header')
    {
        parent::__construct(
            $message,
            StatusCode::NOT_ACCEPTABLE->value,
            'https://example.com/problems/validation-error',
            'Accept Error'
        );
    }
}
