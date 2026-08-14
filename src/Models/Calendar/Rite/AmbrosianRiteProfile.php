<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\AmbrosianHolyDaysOfObligation;
use LiturgicalCalendar\Api\Enum\Ascension;
use LiturgicalCalendar\Api\Enum\CorpusChristi;
use LiturgicalCalendar\Api\Enum\Epiphany;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Calendar\Missal\AmbrosianMissalResolver;
use LiturgicalCalendar\Api\Models\Calendar\Missal\MissalResolver;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\AmbrosianPrecedenceResolver;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\PrecedenceResolver;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\AmbrosianTemporale;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\TemporaleEngine;
use LiturgicalCalendar\Api\Models\Metadata\MetadataRiteCalendarSettings;

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

    /**
     * The Ambrosian rite fixes all five settings, and this method is the only place they are
     * written down (issue #776).
     *
     * - `epiphany`: fixed to 6 January; the Ambrosian rite has no transfer to a Sunday
     *   (see {@see \LiturgicalCalendar\Api\Models\Calendar\Temporale\AmbrosianTemporale::calculateChristmasEpiphany()}).
     * - `ascension`: fixed to the Thursday 39 days after Easter
     *   (see {@see \LiturgicalCalendar\Api\Models\Calendar\Temporale\AmbrosianTemporale::calculateEasterCycle()}).
     * - `corpus_christi`: fixed to the Thursday 60 days after Easter (*il giovedì successivo*),
     *   see {@see \LiturgicalCalendar\Api\Models\Calendar\Temporale\AmbrosianTemporale::calculateAfterPentecostAnchors()}.
     * - `eternal_high_priest`: the Roman `Jesus Christ Eternal High Priest` feast is a
     *   national-conference concession within the Roman rite and has no Ambrosian counterpart.
     * - `holydays_of_obligation`: the Ambrosian days of precept, sourced from
     *   {@see AmbrosianHolyDaysOfObligation::DEFAULT} (provisional, pending ordo validation —
     *   see that class for the rationale). Every Sunday is also a day of precept, as in the
     *   Roman rite; that rule is applied by
     *   {@see \LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection::setAmbrosianHolyDaysOfObligation()}
     *   and is not expressible as an `event_key`, so it is not listed here (the Roman
     *   national/diocesan settings omit it for the same reason).
     *
     * The `/calendar/ambrosian` endpoint documents `epiphany`, `ascension` and
     * `corpus_christi` request parameters as accepted-but-ignored; `CalendarHandler` applies
     * this block over whatever was requested so that the echoed `settings` report what was
     * actually used rather than what was asked for.
     */
    public function fixedCalendarSettings(): MetadataRiteCalendarSettings
    {
        return MetadataRiteCalendarSettings::fromArray([
            'epiphany'               => Epiphany::JAN6->value,
            'ascension'              => Ascension::THURSDAY->value,
            'corpus_christi'         => CorpusChristi::THURSDAY->value,
            'eternal_high_priest'    => false,
            'holydays_of_obligation' => AmbrosianHolyDaysOfObligation::DEFAULT
        ]);
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
}
