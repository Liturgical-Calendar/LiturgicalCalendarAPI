<?php

namespace LiturgicalCalendar\Api\Enum;

enum LitEventTestType: string
{
    case EXACT_CORRESPONDENCE       = 'exactCorrespondence';
    case EXACT_CORRESPONDENCE_SINCE = 'exactCorrespondenceSince';
    case VARIABLE_CORRESPONDENCE    = 'variableCorrespondence';
}
