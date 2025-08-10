<?php

namespace LiturgicalCalendar\Api\Enum;

class ICSErrorLevel
{
    public const int REPAIRED = 1;
    public const int WARNING  = 2;
    public const int FATAL    = 3;

    /** @var array<int,string> */
    public const ERROR_STRING = [
        '',
        'Repaired value',
        'Warning',
        'Fatal Error'
    ];

    private string $errorString;

    public function __construct(int $errorLevel)
    {
        if ($errorLevel < 1 || $errorLevel > 3) {
            throw new \InvalidArgumentException('Invalid error level');
        }
        $this->errorString = static::ERROR_STRING[$errorLevel];
    }

    public function __toString()
    {
        return $this->errorString;
    }
}
