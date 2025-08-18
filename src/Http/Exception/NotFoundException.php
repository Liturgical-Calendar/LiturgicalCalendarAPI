<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;

class NotFoundException extends ApiException
{
    public function __construct(string $message = 'Resource not found')
    {
        parent::__construct(
            $message,
            StatusCode::NOT_FOUND->value,
            'https://example.com/problems/not-found',
            'Not Found'
        );
    }
}
