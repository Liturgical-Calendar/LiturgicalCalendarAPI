<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\LitColor;
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
