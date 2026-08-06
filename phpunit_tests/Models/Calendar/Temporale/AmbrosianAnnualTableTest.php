<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\Models\Calendar\Temporale\AmbrosianTemporale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression gate for the Ambrosian temporale against the Missal's own published
 * "Tabella annuale delle principali celebrazioni dell'anno liturgico"
 * (Messale Ambrosiano II ed. 2024, Premesse/Praenotanda pp. LXXXVIII-LXXXIX),
 * which fixes dates for 2025-2056.
 *
 * Unlike AmbrosianTemporaleOrdoValidationTest, which spot-checks three civil years
 * against the chiesadimilano.it daily widget, this is a published 32-year oracle.
 *
 * Marked @group slow per project convention for full-engine acceptance runs.
 */
#[CoversClass(AmbrosianTemporale::class)]
#[Group('slow')]
final class AmbrosianAnnualTableTest extends TestCase
{
    use AmbrosianTemporaleHarnessTrait;

    private const FIXTURE = __DIR__ . '/../../../fixtures/ambrosian_annual_table_2025_2056.json';

    /** @return array<int,array{0:array<string,string|int>}> */
    public static function annualTableRows(): array
    {
        $contents = file_get_contents(self::FIXTURE);
        self::assertIsString($contents, 'Annual table fixture is unreadable');

        /** @var array<int,array<string,string|int>> $rows */
        $rows = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return array_map(static fn (array $row): array => [$row], $rows);
    }

    /**
     * @param array<string,string|int> $row
     */
    #[DataProvider('annualTableRows')]
    public function testTemporaleAnchorsMatchTheMissalTable(array $row): void
    {
        $year = (int) $row['year'];
        $d    = $this->runEngine($year);

        self::assertSame($row['easter'], $d['Easter'], "Easter $year");
        self::assertSame($row['ascension'], $d['Ascension'], "Ascension $year");
        self::assertSame($row['pentecost'], $d['Pentecost'], "Pentecost $year");
        self::assertSame($row['lent1'], $d['Lent1'], "Lent I $year");
        self::assertSame($row['dedication_duomo'], $d['DedicationDuomo'], "Dedication of the Duomo $year");
    }

    /**
     * Advent I for liturgical year Y falls in November of civil year Y-1, so it is
     * produced by the engine run for Y-1, not for Y.
     *
     * @param array<string,string|int> $row
     */
    #[DataProvider('annualTableRows')]
    public function testAdventOneMatchesTheMissalTable(array $row): void
    {
        $year = (int) $row['year'];
        $d    = $this->runEngine($year - 1);

        self::assertSame($row['advent1'], $d['Advent1'], "Advent I opening liturgical year $year");
    }

    /**
     * Corpus Domini is the Thursday after Trinity Sunday, i.e. Pentecost + 11. The Missal
     * tabulates it explicitly for every year from 2025 to 2056.
     *
     * @param array<string,string|int> $row
     */
    #[DataProvider('annualTableRows')]
    public function testCorpusDominiMatchesTheMissalTable(array $row): void
    {
        $year = (int) $row['year'];
        $d    = $this->runEngine($year);

        self::assertSame($row['corpus_domini'], $d['CorpusChristi'], "Corpus Domini $year");

        $pentecost = new \DateTimeImmutable((string) $row['pentecost']);
        $corpus    = new \DateTimeImmutable((string) $row['corpus_domini']);
        self::assertSame(11, (int) $pentecost->diff($corpus)->days, "Corpus Domini $year must be Pentecost + 11");
        self::assertSame('Thu', $corpus->format('D'), "Corpus Domini $year must fall on a Thursday");
    }
}
