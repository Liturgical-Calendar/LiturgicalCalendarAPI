<?php

namespace LiturgicalCalendar\Api\Enum;

enum LitEventType: string
{
    use EnumToArrayTrait;

    case FIXED  = 'fixed';
    case MOBILE = 'mobile';
}
