<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

use LiturgicalCalendar\Api\DateTime;

/**
 * The closed set of named predicates that can gate an *ad libitum* liturgical colour
 * (issue #781).
 *
 * A liturgical colour is normally unconditional, and `color` — already an array — carries
 * every colour licit for the celebration. Some colours, however, are admitted only on
 * certain days, which a static array cannot express. Rather than an expression language,
 * the condition is one of a fixed vocabulary of predicates evaluated by the engine, so it
 * is validatable in JSON Schema (a plain `enum`) and each predicate's liturgical scope is
 * pinned in exactly one place.
 *
 * The engine resolves these against the computed date and appends the permitted colours to
 * `color`; the response therefore never carries a condition, only its outcome.
 */
enum AdLibitumColorCondition: string
{
    use EnumToArrayTrait;

    /**
     * Admitted on any day but Sunday.
     *
     * *Ordinamento Generale del Messale Ambrosiano* n. 320 admits black in offices and
     * Masses for the dead **except on Sundays**.
     *
     * The Sunday-vigil case needs no special handling here, because `color` is per
     * *celebration* rather than per day, and a vigil is its own event that inherits the
     * colours of the celebration it belongs to:
     *
     * - All Souls on a Sunday (e.g. 2025) is `morello` only, and `AllSouls_vigil` — the
     *   Saturday-evening Mass that opens it — inherits `morello` with it.
     * - All Souls on a Saturday (e.g. 2024) is `morello` + `black`; the Mass that opens the
     *   *following* Sunday is a different event entirely (that Sunday's own vigil), and
     *   carries that Sunday's colours, not these.
     *
     * So evaluating the condition against the celebration's own date is exactly right, and
     * the restrictive "exclude Saturday too" reading would have wrongly denied the faculty
     * at a Saturday Mass for the dead.
     */
    case NOT_SUNDAY = 'not_sunday';

    /**
     * Whether this condition holds for the given computed date.
     */
    public function isSatisfiedBy(DateTime $date): bool
    {
        $isoDayOfWeek = (int) $date->format('N');

        return match ($this) {
            self::NOT_SUNDAY => $isoDayOfWeek !== 7, // 7 = Sunday
        };
    }
}
