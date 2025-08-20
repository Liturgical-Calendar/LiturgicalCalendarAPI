<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;

class ServiceUnavailableException extends ApiException
{
    public function __construct(string $message = 'Service unavailable', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            StatusCode::SERVICE_UNAVAILABLE->value,
            'https://www.rfc-editor.org/rfc/rfc9110.html#name-503-service-unavailable',
            StatusCode::SERVICE_UNAVAILABLE->reason(),
            $previous
        );
    }
}
