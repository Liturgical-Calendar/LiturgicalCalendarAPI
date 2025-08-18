<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;

class ServiceUnavailableException extends ApiException
{
    public function __construct(string $message = 'Service unavailable')
    {
        parent::__construct(
            $message,
            StatusCode::SERVICE_UNAVAILABLE->value,
            'https://example.com/problems/service-unavailable-error',
            StatusCode::SERVICE_UNAVAILABLE->reason()
        );
    }
}
