<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * Resolves the {@see MissalSource} for a rite.
 *
 * Every rite partitions its missals the same way in the source tree, so both branches always
 * exist — the same guarantee {@see JsonData::missalsFolderFor()} relies on.
 */
final class MissalCatalog
{
    public static function for(Rite $rite): MissalSource
    {
        return match ($rite) {
            Rite::ROMAN     => new RomanMissalSource(),
            Rite::AMBROSIAN => new AmbrosianMissalSource(),
        };
    }
}
