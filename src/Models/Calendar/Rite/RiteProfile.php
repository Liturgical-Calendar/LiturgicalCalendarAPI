<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Calendar\Missal\MissalResolver;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\PrecedenceResolver;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\TemporaleEngine;

/**
 * Bundles the rite-specific strategies. Earlier plans wired the temporale
 * engine and the precedence resolver; this plan adds missalResolver(). Later
 * plans add the season/grade/colour vocabularies.
 */
interface RiteProfile
{
    public function rite(): Rite;

    public function temporaleEngine(): TemporaleEngine;

    public function precedenceResolver(): PrecedenceResolver;

    public function missalResolver(): MissalResolver;
}
