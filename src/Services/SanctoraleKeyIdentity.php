<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/**
 * One `event_key` denotes one saint, across every sanctorale missal of a rite.
 *
 * This is the write-time half of the invariant `scripts/lint-missals.php` guards for the
 * corpus as a whole (its invariant 2, added by #939). The rule is deliberately the SAME
 * rule, stated once here rather than re-derived, because a second, slightly different
 * definition of "unique" is worse than none: the lint would pass a corpus the API refuses
 * to produce, or — far worse — the API would wave through a write the lint then fails on,
 * leaving the repository red with no way to undo the write except by hand.
 *
 *     Within a rite, an event_key declared by more than one SANCTORALE missal file must
 *     carry the same month/day in every one of them.
 *
 * Uniqueness is NOT "a key may appear only once". A missal is a delta layer, and
 * re-declaring a key across layers is normal and correct: `StPeterClaver` is declared by
 * the 2002 editio typica, by IT_1983 and by US_2011, each with its own grade for its own
 * calendar. What must never happen is two DIFFERENT saints sharing one key — and because
 * {@see \LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection::addLiturgicalEvent()}
 * merges layers on that string alone, the later layer silently OVERWRITES the earlier one.
 * That is what happened to `StIsidore`: Isidore the Farmer (US_2011, 15 May) erased Isidore
 * of Seville (1970, 4 April) from the calendar of a country that celebrates him, and handed
 * his readings to the wrong man, with no error anywhere.
 *
 * The observable proxy for "same saint" is the calendar date, which is what both this class
 * and the lint script compare. The difference is only when the comparison happens: the lint
 * asks it of a corpus that already exists, this class asks it of a row about to be written.
 */
final class SanctoraleKeyIdentity
{
    /**
     * Every existing declaration of the key that disagrees with the proposed date.
     *
     * An empty list means the write is admissible: either no other sanctorale missal
     * declares the key, or every one that does puts it on the same day — the legitimate
     * delta-layer re-declaration.
     *
     * @param int                                        $month             the proposed month
     * @param int                                        $day               the proposed day
     * @param array<string, array{month:int, day:int}>   $otherDeclarations missal_id => the date that missal
     *                                                                      declares this key on. MUST NOT
     *                                                                      include the missal being written:
     *                                                                      a missal disagreeing with itself
     *                                                                      is an edit, not a collision.
     *
     * @return list<string> one human-readable line per disagreeing declaration, ascending by
     *                      missal id so the message is stable across runs
     */
    public static function dateDisagreements(int $month, int $day, array $otherDeclarations): array
    {
        ksort($otherDeclarations);

        $disagreements = [];
        foreach ($otherDeclarations as $missalId => $declaration) {
            if ($declaration['month'] === $month && $declaration['day'] === $day) {
                continue;
            }
            $disagreements[] = sprintf(
                '%s declares it on %d-%d',
                $missalId,
                $declaration['month'],
                $declaration['day']
            );
        }

        return $disagreements;
    }

    /**
     * The refusal message for a set of disagreements, phrased the way
     * `scripts/lint-missals.php` phrases the same failure, and naming the remedy #939 used.
     *
     * @param list<string> $disagreements as returned by {@see dateDisagreements()}
     */
    public static function conflictMessage(string $eventKey, int $month, int $day, array $disagreements): string
    {
        return sprintf(
            'event_key `%s` is already declared by another sanctorale missal on a DIFFERENT date '
            . '(this request says %d-%d; %s) — one event_key cannot denote two saints, because the '
            . 'calendar merges missal layers by that key alone and the later layer silently overwrites '
            . 'the earlier one. If this is a different saint, give it its own key (as StIsidoreFarmer '
            . 'was split from StIsidore in #939); if it is the same saint, use the date the other '
            . 'missal(s) already declare.',
            $eventKey,
            $month,
            $day,
            implode('; ', $disagreements)
        );
    }
}
