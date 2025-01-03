<?php

namespace LiturgicalCalendar\Api\Enum;

class StatusCode
{
    public const BAD_REQUEST            = 400;
    public const NOT_FOUND              = 404;
    public const METHOD_NOT_ALLOWED     = 405;
    public const NOT_ACCEPTABLE         = 406;
    public const UNSUPPORTED_MEDIA_TYPE = 415;
    public const UNPROCESSABLE_CONTENT  = 422;
    public const SERVICE_UNAVAILABLE    = 503;
    private const STATUS_CODES = [
        StatusCode::BAD_REQUEST            => " 400 Bad Request",
        StatusCode::NOT_FOUND              => " 404 Not Found",
        StatusCode::METHOD_NOT_ALLOWED     => " 405 Method Not Allowed",
        StatusCode::NOT_ACCEPTABLE         => " 406 Not Acceptable",
        StatusCode::UNSUPPORTED_MEDIA_TYPE => " 415 Unsupported Media Type",
        StatusCode::UNPROCESSABLE_CONTENT  => " 422 Unprocessable Content",
        StatusCode::SERVICE_UNAVAILABLE    => " 503 Service Unavailable"
    ];
    public static function toString(int $code): string
    {
        return StatusCode::STATUS_CODES[ $code ];
    }
}
