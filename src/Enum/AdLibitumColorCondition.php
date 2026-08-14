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
     * Admitted on any day that is neither a Sunday nor the day whose evening Mass opens one.
     *
     * *Ordinamento Generale del Messale Ambrosiano* n. 320 admits black in offices and
     * Masses for the dead **except on Sundays**. The Milan Curia extended the same exclusion
     * to the vigil: for Saturday 2 November 2019 it specified that the evening Masses were of
     * the Commemoration of All the Faithful Departed in `morello` and *not* black, since
     * Vespers already opens the Sunday.
     *
     * **Modelling note.** The API reports colours per *day*, not per Mass, so a Saturday is
     * excluded outright. Strictly, n. 320 would still permit black at a Saturday *morning*
     * Mass for the dead — only the evening Mass falls under the Sunday exclusion. Since a
     * single day-level answer has to cover both, this takes the restrictive reading, which
     * is the one the Curia published for the case it actually ruled on. Distinguishing them
     * would require per-Mass colours, which the model does not have.
     */
    case NOT_SUNDAY_AND_NOT_SUNDAY_VIGIL = 'not_sunday_and_not_sunday_vigil';

    /**
     * Whether this condition holds for the given computed date.
     */
    public function isSatisfiedBy(DateTime $date): bool
    {
        $isoDayOfWeek = (int) $date->format('N');

        return match ($this) {
            // 6 = Saturday (its evening Mass opens the Sunday), 7 = Sunday.
            self::NOT_SUNDAY_AND_NOT_SUNDAY_VIGIL => false === in_array($isoDayOfWeek, [6, 7], true),
        };
    }
}
