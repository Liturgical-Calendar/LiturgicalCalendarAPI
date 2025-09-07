<?php

namespace LiturgicalCalendar\Api\Enum;

enum Ascension: string
{
    use EnumToArrayTrait;

    case THURSDAY = 'THURSDAY';
    case SUNDAY   = 'SUNDAY';
}
