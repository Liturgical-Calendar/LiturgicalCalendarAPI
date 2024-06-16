<?php

namespace Johnrdorazio\LitCal\Enum;

enum Route: string
{
    case CALENDARS  = '/calendars';
    case CALENDAR   = '/calendar';
    case TESTS      = '/tests';
    case EVENTS     = '/events';
    case DATA       = '/data';
    case EASTER     = '/easter';
    case SCHEMAS    = '/schemas';
    case MISSALS    = '/missals';
}
