<?php

namespace LiturgicalCalendar\Api\Enum;

enum Epiphany: string
{
    use EnumToArrayTrait;

    case SUNDAY_JAN2_JAN8 = 'SUNDAY_JAN2_JAN8';
    case JAN6             = 'JAN6';
}
