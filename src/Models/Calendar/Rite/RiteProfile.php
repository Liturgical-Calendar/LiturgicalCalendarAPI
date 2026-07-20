<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\TemporaleEngine;

/**
 * Bundles the rite-specific strategies. This plan wires only the temporale
 * engine; later plans add precedenceResolver(), missalResolver(), and the
 * season/grade/colour vocabularies.
 */
interface RiteProfile
{
    public function rite(): Rite;

    public function temporaleEngine(): TemporaleEngine;
}
