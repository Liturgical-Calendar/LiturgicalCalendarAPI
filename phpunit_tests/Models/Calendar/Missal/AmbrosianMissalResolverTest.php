<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Missal;

use LiturgicalCalendar\Api\Enum\AmbrosianMissal;
use LiturgicalCalendar\Api\Models\Calendar\Missal\AmbrosianMissalResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AmbrosianMissalResolver::class)]
final class AmbrosianMissalResolverTest extends TestCase
{
    public function testResolveReturnsEditio2024FromItsFirstYearOnward(): void
    {
        $resolver = new AmbrosianMissalResolver();

        foreach ([2024, 2025, 2100] as $year) {
            self::assertSame([AmbrosianMissal::EDITIO_TYPICA_2024], $resolver->resolve($year), "year $year");
        }
    }

    /**
     * `until_year` is exclusive, so the 1976 edition governs through 2023 inclusive and 2024 is
     * already the second edition's first year.
     */
    public function testResolveReturnsEditio1976ForEveryYearBefore2024(): void
    {
        $resolver = new AmbrosianMissalResolver();

        foreach ([1976, 1990, 2000, 2023] as $year) {
            self::assertSame([AmbrosianMissal::EDITIO_TYPICA_1976], $resolver->resolve($year), "year $year");
        }
    }

    /**
     * A year below the rite's floor never reaches this resolver — `CalendarParams::validateRiteCompatibility()`
     * 400s under `AMBROSIAN_YEAR_LOWER_LIMIT` (1976). Returning `[]` here would surface as an undefined-offset
     * error at the `[0]` in the callers, far from its cause, so the earliest edition is returned instead.
     */
    public function testAYearBelowTheFloorFallsBackToTheEarliestEdition(): void
    {
        $resolver = new AmbrosianMissalResolver();

        self::assertSame([AmbrosianMissal::EDITIO_TYPICA_1976], $resolver->resolve(1900));
    }
}
