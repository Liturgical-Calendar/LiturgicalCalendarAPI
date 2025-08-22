<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;

class UnsupportedMediaTypeException extends ApiException
{
    public function __construct(string $message = 'Content-Type of request is not supported', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            StatusCode::UNSUPPORTED_MEDIA_TYPE->value,
            'https://datatracker.ietf.org/doc/html/rfc9110#name-415-unsupported-media-type',
            StatusCode::UNSUPPORTED_MEDIA_TYPE->reason(),
            $previous
        );
    }
}
