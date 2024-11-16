<?php

namespace LiturgicalCalendar\Api\Enum;

enum CacheDuration: string
{
    case DAY       = "DAY";
    case WEEK      = "WEEK";
    case MONTH     = "MONTH";
    case YEAR      = "YEAR";
}
