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
            'https://www.rfc-editor.org/rfc/rfc9110.html#name-501-not-implemented',
            StatusCode::NOT_IMPLEMENTED->reason()
        );
    }
}
