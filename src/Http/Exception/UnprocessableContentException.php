<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;

class UnprocessableContentException extends ApiException
{
    public function __construct(string $message = 'Unprocessable Content')
    {
        parent::__construct(
            $message,
            StatusCode::UNPROCESSABLE_CONTENT->value,
            'https://example.com/problems/service-unavailable-error',
            StatusCode::UNPROCESSABLE_CONTENT->reason()
        );
    }
}
