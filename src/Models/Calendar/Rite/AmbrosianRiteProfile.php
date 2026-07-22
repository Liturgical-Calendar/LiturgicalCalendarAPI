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
 * Ambrosian rite profile. Plan 2 wired the diocese whitelist and rite
 * identity; Plan 3 added the temporale engine; Plan 4 added the precedence
 * resolver; Plan 5 adds the missal resolver. The remaining vocabularies
 * arrive in later plans.
 */
final class AmbrosianRiteProfile implements RiteProfile
{
    /**
     * Diocesan calendars that support the Ambrosian rite. Provisional constant
     * until Plan 5 creates these diocese files with `supported_rites` metadata,
     * at which point the whitelist becomes data-driven. These IDs MUST match the
     * diocese calendar files created in Plan 5.
     *
     * @var list<string>
     */
    public const SUPPORTED_DIOCESES = ['milano_it', 'bergam_it', 'novara_it', 'lugano_ch'];

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
