<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Calendar\Missal\MissalResolver;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\PrecedenceResolver;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\RomanTemporale;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\TemporaleEngine;
use LiturgicalCalendar\Api\Models\Metadata\MetadataRiteCalendarSettings;

final class RomanRiteProfile implements RiteProfile
{
    public function rite(): Rite
    {
        return Rite::ROMAN;
    }

    /**
     * The Roman rite fixes nothing at rite level: Epiphany, Ascension, Corpus Christi, the
     * Eternal High Priest and the days of precept are all owned by its national and diocesan
     * tiers (and, absent either, by the `CalendarParams` defaults, which a request may
     * legitimately override). Announcing a block here would claim a constraint that the
     * General Roman Calendar does not impose.
     */
    public function fixedCalendarSettings(): ?MetadataRiteCalendarSettings
    {
        return null;
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

    /**
     * Deferred: the Roman path resolves missals inline in CalendarHandler
     * rather than through an extracted resolver. This method is never
     * reached on a live request.
     */
    public function missalResolver(): MissalResolver
    {
        throw new \LogicException('Roman missals are resolved inline in CalendarHandler; no resolver is extracted.');
    }

    /**
     * The colors of the Roman Missal: green, red, white, purple (violet) and rose.
     *
     * Rose is admitted on Gaudete and Laetare Sundays only; black is *not* listed
     * here — although the Roman Missal still tolerates it for Masses for the dead,
     * no Roman source row in this repository uses it and the API's Roman data
     * expresses those celebrations as `purple`. The Ambrosian palette is where
     * `black` is carried (see {@see AmbrosianRiteProfile::colors()}).
     *
     * @return LitColor[]
     */
    public function colors(): array
    {
        return [
            LitColor::GREEN,
            LitColor::RED,
            LitColor::WHITE,
            LitColor::PURPLE,
            LitColor::ROSE,
        ];
    }
}
