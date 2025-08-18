<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use PHPUnit\Logging\OpenTestReporting\Status;

class NotAcceptableException extends ApiException
{
    public function __construct(string $message = 'Not Acceptable')
    {
        parent::__construct(
            $message,
            StatusCode::NOT_ACCEPTABLE->value,
            'https://example.com/problems/validation-error',
            StatusCode::NOT_ACCEPTABLE->reason()
        );
    }
}
