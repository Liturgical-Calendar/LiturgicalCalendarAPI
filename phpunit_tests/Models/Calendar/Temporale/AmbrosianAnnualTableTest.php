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

    /**
     * The Missal's own oracle for this design's central claim: that placing `Trinity` on the
     * I domenica dopo Pentecoste does NOT shift the numbering of the Sundays that follow it.
     *
     * The Tabella prints each of the two post-Pentecost sub-blocks as "N + ultima", i.e. N
     * NUMBERED Sundays plus one final unnumbered one. The engine emits a numbered `event_key`
     * for exactly the numbered Sundays and a proper-name key for the unnumbered one, so N is
     * the count of numbered keys the engine emits for that block:
     *
     * - "dopo Pentecoste": ordinal 1 is always consumed by `Trinity` (Easter + 56), which the
     *   engine emits under its own key rather than as `AfterPentecost1`. `numberSundayBlock()`
     *   still increments the ordinal for that skipped Sunday, so the remaining Sundays keep the
     *   numbering they had before Task 4 -- the invariant this test exists to protect. The
     *   emitted keys are therefore `AfterPentecost2 .. AfterPentecost{N+1}`, N of them.
     * - "dopo il Martirio": nothing displaces its first Sunday, so the emitted keys are
     *   `AfterPentecostMartyrdom1 .. AfterPentecostMartyrdom{N}`, N of them; the block's
     *   unnumbered final Sunday is the Dedication of the Duomo, emitted as `DedicationDuomo`.
     *
     * Asserting the exact ordinal RANGE (not merely the count) is what pins the no-shift claim:
     * a renumbering that started the first block at 1 would keep the same count but change the
     * range, and would fail here.
     *
     * Until now these counts were pinned for 2025 alone (`AmbrosianTemporaleTest::testAfterPentecostSubBlocks2025()`).
     *
     * @param array<string,string|int> $row
     */
    #[DataProvider('annualTableRows')]
    public function testPostPentecostSundayCountsMatchTheMissalTable(array $row): void
    {
        $year                   = (int) $row['year'];
        $expectedAfterPentecost = (int) $row['sundays_after_pentecost'];
        $expectedAfterMartyrdom = (int) $row['sundays_after_martyrdom'];

        $keys = array_keys($this->runEngine($year));

        $afterPentecostOrdinals = [];
        $afterMartyrdomOrdinals = [];
        foreach ($keys as $key) {
            if (preg_match('/^AfterPentecostMartyrdom(\d+)$/', $key, $matches) === 1) {
                $afterMartyrdomOrdinals[] = (int) $matches[1];
            } elseif (preg_match('/^AfterPentecost(\d+)$/', $key, $matches) === 1) {
                $afterPentecostOrdinals[] = (int) $matches[1];
            }
        }
        sort($afterPentecostOrdinals);
        sort($afterMartyrdomOrdinals);

        self::assertSame(
            range(2, $expectedAfterPentecost + 1),
            $afterPentecostOrdinals,
            "The Sundays 'dopo Pentecoste' in $year must be numbered II..{$expectedAfterPentecost}+1 "
                . "(Missal: $expectedAfterPentecost + ultima; ordinal I is Trinity, and the numbering must not shift)"
        );

        self::assertSame(
            range(1, $expectedAfterMartyrdom),
            $afterMartyrdomOrdinals,
            "The Sundays 'dopo il Martirio' in $year must be numbered I..$expectedAfterMartyrdom "
                . "(Missal: $expectedAfterMartyrdom + ultima, the ultima being the Dedication of the Duomo)"
        );
    }
}
