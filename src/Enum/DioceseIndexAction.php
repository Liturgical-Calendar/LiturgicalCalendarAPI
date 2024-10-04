<?php

namespace LiturgicalCalendar\Api\Enum;

enum DioceseIndexAction: string
{
    case DELETE = 'delete';
    case UPDATE = 'update';
    case CREATE = 'create';
}
