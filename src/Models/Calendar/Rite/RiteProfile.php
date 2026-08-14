<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Calendar\Missal\MissalResolver;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\PrecedenceResolver;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\TemporaleEngine;
use LiturgicalCalendar\Api\Models\Metadata\MetadataRiteCalendarSettings;

/**
 * Bundles the rite-specific strategies. Earlier plans wired the temporale
 * engine and the precedence resolver; this plan adds missalResolver(). Later
 * plans add the season/grade/colour vocabularies.
 */
interface RiteProfile
{
    public function rite(): Rite;

    /**
     * The calendar settings this rite fixes for every calendar computed under it, or
     * `null` when the rite fixes none and leaves them to its national/diocesan tiers.
     *
     * This is the single authority for those values (issue #776). Both sides read it:
     * {@see \LiturgicalCalendar\Api\Services\CalendarMetadataProvider} to *announce* them
     * on `/calendars`, and {@see \LiturgicalCalendar\Api\Handlers\CalendarHandler} to
     * *apply* them to `CalendarParams` before the calendar is calculated and the `settings`
     * block echoed — so the announced values provably cannot drift from the applied ones.
     *
     * Returning `null` (the Roman rite) is what keeps the seam general rather than
     * Ambrosian-specific: a future non-Roman rite either declares a block here or does not.
     */
    public function fixedCalendarSettings(): ?MetadataRiteCalendarSettings;

    public function temporaleEngine(): TemporaleEngine;

    public function precedenceResolver(): PrecedenceResolver;

    public function missalResolver(): MissalResolver;
}
