<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * The liturgical rite a calendar request is computed under.
 * ROMAN is the default and applies to every existing route; AMBROSIAN
 * is selected by an optional leading `ambrosian` path segment.
 *
 * Stating the rite explicitly (`/calendar/roman/...`) is the canonical URL form. The bare
 * form (`/calendar/...`) stays valid for backwards compatibility and advertises the canonical
 * URL through a `Link: rel="canonical"` header — see {@see \LiturgicalCalendar\Api\Router::canonicalRiteUrl()}.
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
