<?php

namespace LiturgicalCalendar\Api\Enum;

enum CorpusChristi: string
{
    use EnumToArrayTrait;

    case THURSDAY = 'THURSDAY';
    case SUNDAY   = 'SUNDAY';
}
