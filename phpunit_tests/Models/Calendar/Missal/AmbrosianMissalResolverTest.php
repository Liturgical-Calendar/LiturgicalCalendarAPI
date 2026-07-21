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
    public function testResolveReturnsEditio2024For2025(): void
    {
        $resolver = new AmbrosianMissalResolver();

        $this->assertSame([AmbrosianMissal::EDITIO_2024], $resolver->resolve(2025));
    }

    /**
     * Every in-range year resolves to the 2024 edition for now; the 1976 edition
     * split is deferred to a later plan (Plan 8).
     */
    public function testResolveReturnsEditio2024ForAnyInRangeYear(): void
    {
        $resolver = new AmbrosianMissalResolver();

        foreach ([1976, 2000, 2024, 2100] as $year) {
            $this->assertSame([AmbrosianMissal::EDITIO_2024], $resolver->resolve($year));
        }
    }
}
