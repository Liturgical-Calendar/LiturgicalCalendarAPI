<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models;

use LiturgicalCalendar\Api\Enum\AdLibitumColorCondition;
use LiturgicalCalendar\Api\Enum\LitColor;

/**
 * A liturgical colour admitted *ad libitum*, gated by a named condition (issue #781).
 *
 * Source data only: the engine evaluates {@see AdLibitumColorCondition::isSatisfiedBy()}
 * against the computed date and, when it holds, appends {@see self::$color} to the event's
 * `color` array. The API response therefore carries the outcome and never the condition —
 * which is what keeps the liturgical law in the API rather than in every client.
 */
final class AdLibitumColor
{
    public function __construct(
        public readonly LitColor $color,
        public readonly AdLibitumColorCondition $when
    ) {
    }
}
