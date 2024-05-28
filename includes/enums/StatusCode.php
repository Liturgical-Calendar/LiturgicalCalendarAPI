<?php

namespace LitCal\enum;

class StatusCode
{
    public const UNPROCESSABLE_CONTENT  = 422;
    public const SERVICE_UNAVAILABLE    = 503;
    public const METHOD_NOT_ALLOWED     = 405;
    public const UNSUPPORTED_MEDIA_TYPE = 415;
}
