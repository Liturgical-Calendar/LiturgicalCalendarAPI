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
            'https://example.com/problems/invalid-yaml',
            'Invalid YAML data',
            $previous
        );
    }
}
