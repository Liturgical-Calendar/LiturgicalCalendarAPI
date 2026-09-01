<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Missal;

use LiturgicalCalendar\Api\Enum\AmbrosianMissal;
use LiturgicalCalendar\Api\Models\Calendar\Missal\AmbrosianMissalResolver;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AmbrosianMissalResolver::class)]
final class AmbrosianMissalResolverTest extends TestCase
{
    private static string $savedApiPath;
    private static string $savedApiFilePath;

    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
        self::$savedApiPath     = Router::$apiPath;
        self::$savedApiFilePath = Router::$apiFilePath;
    }

    public static function tearDownAfterClass(): void
    {
        Router::$apiPath     = self::$savedApiPath;
        Router::$apiFilePath = self::$savedApiFilePath;
    }
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

    public function testSelectSanctoraleEditionIsNotSubstitutedWhenTheGoverningEditionHasData(): void
    {
        $selection = ( new AmbrosianMissalResolver() )->selectSanctoraleEdition(2025);

        self::assertSame(AmbrosianMissal::EDITIO_TYPICA_2024, $selection->requested);
        self::assertSame(AmbrosianMissal::EDITIO_TYPICA_2024, $selection->effective);
        self::assertFalse($selection->isSubstituted());
    }

    /**
     * The 1976 edition governs 1990 but ships no sanctorale, so the nearest LATER edition that does
     * is read instead. Forward, never backward: a later edition is the closest proper of this rite
     * that we actually hold, whereas an earlier one is itself absent.
     */
    public function testSelectSanctoraleEditionSubstitutesForwardWhenTheGoverningEditionHasNoData(): void
    {
        $selection = ( new AmbrosianMissalResolver() )->selectSanctoraleEdition(1990);

        self::assertSame(AmbrosianMissal::EDITIO_TYPICA_1976, $selection->requested);
        self::assertSame(AmbrosianMissal::EDITIO_TYPICA_2024, $selection->effective);
        self::assertTrue($selection->isSubstituted());
    }

    public function testSelectSanctoraleEditionAgreesWithResolveOnTheGoverningEdition(): void
    {
        $resolver = new AmbrosianMissalResolver();

        foreach ([1976, 2023, 2024, 2030] as $year) {
            self::assertSame(
                $resolver->resolve($year)[0],
                $resolver->selectSanctoraleEdition($year)->requested,
                "year $year"
            );
        }
    }
}
