<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * The liturgical rite a calendar request is computed under.
 * ROMAN is the default and applies to every existing route; AMBROSIAN
 * is selected by an optional leading `ambrosian` path segment.
 */
enum Rite: string
{
    case ROMAN     = 'roman';
    case AMBROSIAN = 'ambrosian';

    public static function default(): self
    {
        return self::ROMAN;
    }
}
