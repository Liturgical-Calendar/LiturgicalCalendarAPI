<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\LitColor;
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

    /**
     * The liturgical colors licit in this rite.
     *
     * {@see LitColor} is deliberately a single flat enum shared by both rites — it
     * answers "is this a liturgical color this API can translate and render", which
     * is rite-independent. *Which* of those colors a given Missal admits is a
     * validation rule, and this method is its single authoritative statement per
     * rite (issue #771). Consumed by the `/data` write path — where `metadata.rite`
     * is known — and by the source-data integrity tests.
     *
     * The two palettes are disjoint only in their violet families and extremes:
     * `purple`/`rose` are Roman, `morello`/`black` are Ambrosian, and
     * `green`/`red`/`white` are common to both. Their union is exactly
     * {@see LitColor::cases()}, which `ColorEnumParityTest` pins.
     *
     * @return LitColor[]
     */
    public function colors(): array;
}
