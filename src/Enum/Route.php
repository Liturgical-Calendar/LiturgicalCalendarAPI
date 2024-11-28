<?php

namespace LiturgicalCalendar\Api\Enum;

enum Route: string
{
    case CALENDARS         = '/calendars';
    case CALENDAR          = '/calendar';
    case CALENDAR_NATIONAL = '/calendar/nation';
    case CALENDAR_DIOCESAN = '/calendar/diocese';
    case DECREES           = '/decrees';
    case TESTS             = '/tests';
    case EVENTS            = '/events';
    case DATA              = '/data';
    case DATA_WIDERREGION  = '/data/widerregion';
    case DATA_NATIONAL     = '/data/nation';
    case DATA_DIOCESAN     = '/data/diocese';
    case EASTER            = '/easter';
    case SCHEMAS           = '/schemas';
    case MISSALS           = '/missals';
}
