<?php

namespace LiturgicalCalendar\Api\Enum;

enum YearType: string
{
    use EnumToArrayTrait;

    case CIVIL      = 'CIVIL';
    case LITURGICAL = 'LITURGICAL';
}
