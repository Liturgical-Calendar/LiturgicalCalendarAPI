<?php

namespace LiturgicalCalendar\Api\Enum;

enum LitEventTestAssertion: string
{
    case EVENT_NOT_EXISTS                        = 'eventNotExists';
    case EVENT_EXISTS_AND_HAS_EXPECTED_TIMESTAMP = 'eventExists AND hasExpectedTimestamp';
}
