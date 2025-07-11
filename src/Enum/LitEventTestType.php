<?php

namespace LiturgicalCalendar\Api\Test;

enum LitEventTestType: string
{
    case EXACT_CORRESPONDENCE       = 'exactCorrespondence';
    case EXACT_CORRESPONDENCE_SINCE = 'exactCorrespondenceSince';
    case VARIABLE_CORRESPONDENCE    = 'variableCorrespondence';
}
