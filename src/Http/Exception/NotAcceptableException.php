<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use PHPUnit\Logging\OpenTestReporting\Status;

class NotAcceptableException extends ApiException
{
    public function __construct(string $message = 'Not Acceptable', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            StatusCode::NOT_ACCEPTABLE->value,
            'https://www.rfc-editor.org/rfc/rfc9110.html#name-406-not-acceptable',
            StatusCode::NOT_ACCEPTABLE->reason(),
            $previous
        );
    }
}
