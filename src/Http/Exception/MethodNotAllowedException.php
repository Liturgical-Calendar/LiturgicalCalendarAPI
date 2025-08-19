<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;

class MethodNotAllowedException extends ApiException
{
    public function __construct(string $message = 'Method not allowed')
    {
        parent::__construct(
            $message,
            StatusCode::METHOD_NOT_ALLOWED->value,
            'https://www.rfc-editor.org/rfc/rfc9110.html#name-406-not-acceptable',
            StatusCode::METHOD_NOT_ALLOWED->reason()
        );
    }
}
