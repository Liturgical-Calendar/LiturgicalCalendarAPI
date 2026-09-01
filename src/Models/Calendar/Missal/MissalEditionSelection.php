<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Missal;

/**
 * The pair of Missal editions a sanctorale read involves: the one that GOVERNS the requested year, and
 * the one whose data is actually read.
 *
 * These are two different questions, and conflating them is what let the API build every Ambrosian
 * calendar from 1976 onward out of the 2024 sanctorale without saying so. `$requested` answers "which
 * edition is in force for this year" — a fact about liturgical history that does not depend on what this
 * codebase happens to ship. `$effective` answers "which edition do we hold a proper for" — a fact about
 * this repository's source data, which changes as data lands.
 *
 * When the two differ the caller is expected to SAY SO (see `CalendarHandler::addAmbrosianSanctoraleToCalendar()`),
 * rather than silently serve the substitute.
 */
final readonly class MissalEditionSelection
{
    public function __construct(
        public string $requested,
        public string $effective
    ) {
    }

    public function isSubstituted(): bool
    {
        return $this->requested !== $this->effective;
    }
}
