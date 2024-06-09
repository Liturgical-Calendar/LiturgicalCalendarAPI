<?php

namespace LitCal\enum;

class ICSErrorLevel
{
    public const int REPAIRED  = 1;
    public const int WARNING   = 2;
    public const int FATAL     = 3;
    public const ERROR_STRING  = [
        null,
        'Repaired value',
        'Warning',
        'Fatal Error'
    ];
    private string $errorString;

    public function __construct(int $errorLevel)
    {
        $this->errorString = static::ERROR_STRING[ $errorLevel ];
    }

    public function __toString()
    {
        return $this->errorString;
    }
}
