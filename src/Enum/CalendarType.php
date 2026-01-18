<?php

namespace LiturgicalCalendar\Api\Enum;

/**
 * Calendar type enumeration for calendar permissions.
 *
 * Defines the types of calendars that can have permissions assigned.
 */
enum CalendarType: string
{
    use EnumToArrayTrait;

    case NATIONAL    = 'national';
    case DIOCESAN    = 'diocesan';
    case WIDERREGION = 'widerregion';
}
