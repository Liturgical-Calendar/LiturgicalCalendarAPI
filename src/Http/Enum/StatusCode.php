<?php

namespace LiturgicalCalendar\Api\Http\Enum;

/**
 * StatusCode provides named constants for the HTTP protocol status codes.
 *
 * @package LiturgicalCalendar\Api\Enum
 */
enum StatusCode: int
{
    case PROCESSING             = 102;
    case OK                     = 200;
    case CREATED                = 201;
    case NO_CONTENT             = 204;
    case NOT_MODIFIED           = 304;
    case BAD_REQUEST            = 400;
    case NOT_FOUND              = 404;
    case METHOD_NOT_ALLOWED     = 405;
    case NOT_ACCEPTABLE         = 406;
    case CONFLICT               = 409;
    case UNSUPPORTED_MEDIA_TYPE = 415;
    case UNPROCESSABLE_CONTENT  = 422;
    case NOT_IMPLEMENTED        = 501;
    case SERVICE_UNAVAILABLE    = 503;

    /**
     * Converts an HTTP status code to its corresponding string representation.
     *
     * @return string The 'reason' that accompanies the HTTP Status code.
     */
    public function toString(): string
    {
        return match ($this) {
            StatusCode::PROCESSING             => 'Processing',
            StatusCode::OK                     => 'OK',
            StatusCode::CREATED                => 'Created',
            StatusCode::NO_CONTENT             => 'No Content',
            StatusCode::NOT_MODIFIED           => 'Not Modified',
            StatusCode::BAD_REQUEST            => 'Bad Request',
            StatusCode::NOT_FOUND              => 'Not Found',
            StatusCode::METHOD_NOT_ALLOWED     => 'Method Not Allowed',
            StatusCode::NOT_ACCEPTABLE         => 'Not Acceptable',
            StatusCode::CONFLICT               => 'Conflict',
            StatusCode::UNSUPPORTED_MEDIA_TYPE => 'Unsupported Media Type',
            StatusCode::UNPROCESSABLE_CONTENT  => 'Unprocessable Content',
            StatusCode::NOT_IMPLEMENTED        => 'Not Implemented',
            StatusCode::SERVICE_UNAVAILABLE    => 'Service Unavailable'
        };
    }

    /**
     * Alias for {@see \LiturgicalCalendar\Api\Http\Enum\StatusCode::toString()}.
     *
     * @return string The 'reason' that accompanies the HTTP Status code.
     */
    public function reason(): string
    {
        return $this->toString();
    }
}
