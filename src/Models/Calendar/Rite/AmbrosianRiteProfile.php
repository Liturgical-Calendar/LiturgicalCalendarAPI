<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Calendar\Missal\AmbrosianMissalResolver;
use LiturgicalCalendar\Api\Models\Calendar\Missal\MissalResolver;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\AmbrosianPrecedenceResolver;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\PrecedenceResolver;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\AmbrosianTemporale;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\TemporaleEngine;

/**
 * Ambrosian rite profile. Plan 2 wired rite identity; Plan 3 added the
 * temporale engine; Plan 4 added the precedence resolver; Plan 5 added the
 * missal resolver. Diocese/rite membership is data-driven, sourced from each
 * diocese's declared `rite` metadata (see
 * {@see \LiturgicalCalendar\Api\Services\CalendarMetadataProvider}) and
 * enforced by {@see \LiturgicalCalendar\Api\Params\CalendarParams::validateDiocesanCalendarParam()}
 * — no hardcoded whitelist remains here. The remaining vocabularies arrive in
 * later plans.
 */
final class AmbrosianRiteProfile implements RiteProfile
{
    public function rite(): Rite
    {
        return Rite::AMBROSIAN;
    }

    public function temporaleEngine(): TemporaleEngine
    {
        return new AmbrosianTemporale();
    }

    public function precedenceResolver(): PrecedenceResolver
    {
        return new AmbrosianPrecedenceResolver();
    }

    public function missalResolver(): MissalResolver
    {
        return new AmbrosianMissalResolver();
    }

    /**
     * The colors of the Ambrosian Missal: green, red, white, morello and black
     * (praenotanda of the 2024 Ambrosian Missal, §350).
     *
     * Two entries need their provenance stated, because neither is inferable from
     * the shipped source rows:
     *
     * - `green` carries no data row at all. The Ambrosian green is synthesised by
     *   {@see \LiturgicalCalendar\Api\Models\Calendar\Temporale\AmbrosianTemporale}
     *   for the ferial weeks rather than authored per event, so it is licit in the
     *   rite even though grepping the Ambrosian tree for it returns nothing.
     *
     * - `black` likewise carries no data row today, and is kept deliberately. The
     *   *Ordinamento Generale del Messale Ambrosiano* n. 320 admits *nero* as an
     *   optional alternative to `morello` on the ferias of Lent (Saturday excluded)
     *   and in the offices and Masses for the dead, but **not on Sundays**. It is
     *   not an uninterrupted survival but a recent reintroduction: after the 2008
     *   reform of the Ambrosian Lectionary, Card. Tettamanzi restored the optional
     *   use of black in substitution for morello on Lenten ferias (Holy Week
     *   excluded), at funerals, in Masses for the dead and on the Commemoration of
     *   All the Faithful Departed. So it is licit vocabulary awaiting the data
     *   model that can express "morello, or black when the day is not a Sunday",
     *   not dead weight to be dropped from {@see LitColor}.
     *
     * `morello` is the proper Ambrosian denomination of the violet family and is
     * kept distinct from the Roman `purple` even though the vestments are in
     * practice interchangeable — see n. 320 for its own occurrences (Advent, Lent
     * up to but excluding the Saturday *in Traditione symboli*, votive Masses for
     * the forgiveness of sins, and offices and Masses for the dead).
     *
     * @return LitColor[]
     */
    public function colors(): array
    {
        return [
            LitColor::GREEN,
            LitColor::RED,
            LitColor::WHITE,
            LitColor::MORELLO,
            LitColor::BLACK,
        ];
    }
}
