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
            'https://www.rfc-editor.org/rfc/rfc9110.html#name-422-unprocessable-content',
            StatusCode::UNPROCESSABLE_CONTENT->reason(),
            $previous
        );
    }
}
