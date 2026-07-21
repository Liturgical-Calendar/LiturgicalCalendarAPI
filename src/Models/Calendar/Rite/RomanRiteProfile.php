<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\PrecedenceResolver;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\RomanTemporale;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\TemporaleEngine;

final class RomanRiteProfile implements RiteProfile
{
    public function rite(): Rite
    {
        return Rite::ROMAN;
    }

    public function temporaleEngine(): TemporaleEngine
    {
        return new RomanTemporale();
    }

    /**
     * Deferred: the Roman path resolves precedence inline in CalendarHandler
     * rather than through an extracted resolver. This method is never
     * reached on a live request.
     */
    public function precedenceResolver(): PrecedenceResolver
    {
        throw new \LogicException('Roman precedence is resolved inline in CalendarHandler; no resolver is extracted.');
    }
}
