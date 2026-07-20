<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\Rite;

final class RiteProfileFactory
{
    public static function forRite(Rite $rite): RiteProfile
    {
        return match ($rite) {
            Rite::ROMAN     => new RomanRiteProfile(),
            Rite::AMBROSIAN => throw new \InvalidArgumentException('Ambrosian rite not yet wired'),
        };
    }
}
