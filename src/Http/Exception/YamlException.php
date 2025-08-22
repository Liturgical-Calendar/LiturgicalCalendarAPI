<?php

namespace LiturgicalCalendar\Api\Http\Exception;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;

class YamlException extends ApiException
{
    public function __construct(
        string $message = 'Malformed YAML data received in the request',
        int $status     = StatusCode::UNPROCESSABLE_CONTENT->value,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            $message,
            $status,
            'https://datatracker.ietf.org/doc/html/rfc9110#name-422-unprocessable-content',
            'Invalid YAML data',
            $previous
        );
    }
}
