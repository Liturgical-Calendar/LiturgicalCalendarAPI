<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Rite;

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
}
