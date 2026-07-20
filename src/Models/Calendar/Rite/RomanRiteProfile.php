<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\Rite;
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
}
