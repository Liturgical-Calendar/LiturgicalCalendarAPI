<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\TemporaleEngine;

/**
 * Ambrosian rite profile. Plan 2 wires only the diocese whitelist and rite
 * identity; the temporale engine, precedence resolver, missal resolver, and
 * vocabularies arrive in later plans.
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
        // Unreachable in normal flow: CalendarHandler returns 501 for the
        // Ambrosian rite before any temporale computation. Replaced by the
        // real AmbrosianTemporale in Plan 3.
        throw new \LogicException('The Ambrosian temporale engine is not yet implemented (Plan 3).');
    }
}
