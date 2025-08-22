<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;

class UnprocessableContentException extends ApiException
{
    public function __construct(string $message = 'Unprocessable Content', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            StatusCode::UNPROCESSABLE_CONTENT->value,
            'https://datatracker.ietf.org/doc/html/rfc9110#name-422-unprocessable-content',
            StatusCode::UNPROCESSABLE_CONTENT->reason(),
            $previous
        );
    }
}
