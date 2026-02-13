<?php

namespace LiturgicalCalendar\Api\Enum;

/**
 * Permission level enumeration for calendar access control.
 *
 * Defines the levels of access that can be granted for calendars.
 */
enum PermissionLevel: string
{
    use EnumToArrayTrait;

    case READ  = 'read';
    case WRITE = 'write';
}
