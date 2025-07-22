<?php

namespace LiturgicalCalendar\Api\Enum;

enum DateRelation: string
{
    use EnumToArrayTrait;

    case Before = 'before';
    case After  = 'after';
}
