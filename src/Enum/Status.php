<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * The outcome of a step.
 *
 * Explicit on the wire so a client need not infer it from a CSS class. Note the legacy `.test-valid`
 * class is an *address* for the validity box, not a claim about the outcome — the box is coloured by
 * this status. That is why the class is the same for a pass and a fail, and why it is correct.
 */
enum Status: string
{
    case PASS = 'pass';
    case FAIL = 'fail';
}
