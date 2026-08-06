<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Sanctorale;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\LocaleDateFormatter;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\AmbrosianPrecedenceResolver;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\PrecedenceContext;
use LiturgicalCalendar\Api\Params\CalendarParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Task 5 (Ambrosian Pentecost-anchored celebrations, Plan follow-up to Task 4's
 * Corpus Domini fix): closes the loop Task 2 deliberately left open.
 *
 * `AmbrosianAnnualTableTest::testCorpusDominiMatchesTheMissalTable()` already
 * asserts all 32 tabulated Corpus Domini dates (2025-2056) against the
 * temporale ENGINE. That layer cannot see precedence resolution -- and this
 * is not hypothetical: Task 4 shipped a defect where CorpusChristi was
 * silently transferred off its Missal Thursday by a lower-graded comune
 * sanctorale event in 2 of 3 sampled years (`AmbrosianRealYearPrecedenceTest::
 * testCorpusChristiLandsOnMissalThursdayUnimpeded()`, 2024 and 2025), while
 * every temporale-level test stayed green throughout. A temporale-only oracle
 * would have declared that calendar correct.
 *
 * This test closes that gap: it assembles the full civil-year calendar
 * (temporale + comune sanctorale + precedence resolution, via the Task 7/8
 * harness) for every one of the 32 tabulated years and asserts CorpusChristi
 * survives resolution on its Missal-fixed date, unsuppressed, as the SOLE
 * occupant of that date.
 *
 * Coverage note: all 32 tabulated years (2025-2056) are covered, not a
 * reduced sample. A full 32-year assemble+resolve sweep measured well under
 * one second locally (~0.02s/year), so there was no practical reason to trim
 * coverage per the task brief's "if too slow" escape hatch. 15 of the 32
 * years contain a genuine pre-resolution collision between CorpusChristi and
 * a fixed-date comune sanctorale event sharing its Missal date (verified via
 * a one-off scan of `AmbrosianSanctoraleLoader` output against the fixture's
 * `corpus_domini` column): 2025, 2027, 2029, 2032, 2035, 2038, 2039, 2040,
 * 2042, 2046, 2047, 2050, 2051, 2053, 2056. The remaining 17 years have no
 * comune collider on that date and exercise the uncontested path instead.
 * Both paths are asserted uniformly below (CorpusChristi active/unsuppressed/
 * on-date/sole-occupant), since the outcome for CorpusChristi is identical
 * either way -- the collision years are simply the years where that outcome
 * is non-vacuous.
 *
 * 2024 is not in the fixture (the Missal table starts at 2025) and stays
 * covered only by `AmbrosianRealYearPrecedenceTest::
 * testCorpusChristiLandsOnMissalThursdayUnimpeded()`.
 *
 * Marked `#[Group('slow')]` (the attribute -- PHPUnit 12 in this repo only
 * honours the attribute form, never a `@group` docblock) per project
 * convention for full-engine acceptance runs; excluded from
 * `composer test:quick`.
 */
#[CoversClass(AmbrosianPrecedenceResolver::class)]
#[Group('slow')]
final class AmbrosianRealYearCorpusDominiTest extends TestCase
{
    use AmbrosianRealYearHarnessTrait;

    private const FIXTURE = __DIR__ . '/../../../fixtures/ambrosian_annual_table_2025_2056.json';

    /** @return array<int,array{0:int,1:string}> */
    public static function corpusDominiRows(): array
    {
        $contents = file_get_contents(self::FIXTURE);
        self::assertIsString($contents, 'Annual table fixture is unreadable');

        /** @var array<int,array<string,string|int>> $rows */
        $rows = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return array_map(
            static fn (array $row): array => [(int) $row['year'], (string) $row['corpus_domini']],
            $rows
        );
    }

    private function buildContextFor(LiturgicalEventCollection $cal, int $year, array &$messages): PrecedenceContext
    {
        $params = new CalendarParams();
        $params->setParams(['year' => $year]);
        $params->setRite(Rite::AMBROSIAN);

        return new PrecedenceContext(
            $cal,
            $params,
            new LocaleDateFormatter(LitLocale::$RUNTIME_LOCALE),
            $messages
        );
    }

    /**
     * Proves the Missal-tabulated Corpus Domini date survives full precedence
     * resolution for every tabulated year, not merely the two years Task 4's
     * regression test happened to sample.
     */
    #[DataProvider('corpusDominiRows')]
    public function testCorpusDominiSurvivesPrecedenceResolution(int $year, string $expectedDate): void
    {
        $cal = $this->assembleAmbrosianYear($year);

        // Sanity: the temporale engine placed CorpusChristi on the Missal date
        // BEFORE resolution runs, so a post-resolution match below is proof the
        // date survived resolution rather than merely being computed correctly
        // and then coincidentally landing back on the right day.
        $corpusChristiBefore = $cal->getLiturgicalEvent('CorpusChristi');
        self::assertNotNull($corpusChristiBefore, "Expected a LiturgicalEvent for CorpusChristi ($year) before resolution");
        self::assertSame($expectedDate, $corpusChristiBefore->date->format('Y-m-d'), "CorpusChristi ($year) before resolution");

        $messages = [];
        $ctx      = $this->buildContextFor($cal, $year, $messages);
        ( new AmbrosianPrecedenceResolver() )->resolve($ctx);

        // CorpusChristi wins outright: stays active, on its Missal-fixed date, never suppressed.
        self::assertFalse($cal->isSuppressed('CorpusChristi'), "CorpusChristi must not be suppressed ($year)");
        $corpusChristiAfter = $cal->getLiturgicalEvent('CorpusChristi');
        self::assertNotNull($corpusChristiAfter, "CorpusChristi must still be active ($year)");
        self::assertSame($expectedDate, $corpusChristiAfter->date->format('Y-m-d'), "CorpusChristi must not be transferred off $expectedDate ($year)");

        // CorpusChristi is the SOLE active occupant of its Missal date: any comune
        // sanctorale event that shared the date pre-resolution was either
        // suppressed or displaced elsewhere by the resolver, not left coexisting.
        $occupants = $cal->getCalEventsFromDate(DateTime::fromFormat($corpusChristiAfter->date->format('j-n-Y')));
        self::assertCount(1, $occupants, "Expected CorpusChristi to be the sole occupant of $expectedDate ($year)");
        self::assertArrayHasKey('CorpusChristi', $occupants);
    }
}
