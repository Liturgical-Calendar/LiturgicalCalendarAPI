<?php

namespace LiturgicalCalendar\Api\Enum;

enum PathCategory: string
{
    use EnumToArrayTrait;

    case NATION      = 'nation';
    case DIOCESE     = 'diocese';
    case WIDERREGION = 'widerregion';
}
