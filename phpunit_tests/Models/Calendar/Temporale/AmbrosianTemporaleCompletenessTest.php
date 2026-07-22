<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\AmbrosianTemporale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Full-year completeness acceptance gate for the Ambrosian temporale engine
 * (Plan 6, Task 11).
 *
 * Proves that for a full civil year, the engine produces exactly one
 * temporale event per day, gap-free, with a single documented exception:
 * the Sunday within the Christmas octave (n.32 "domenica nell'ottava del
 * Natale"). Its vigil-shift placement is explicitly deferred by spec §4 to
 * ordo-validation; in the assembled calendar that day is instead covered by
 * the sanctorale (St Stephen/John/Holy Innocents, Dec 26/27/28), so its
 * absence from the temporale-only view is expected, not a regression.
 *
 * Marked @group slow per project convention for full-engine acceptance runs;
 * excluded from `composer test:quick`.
 */
#[CoversClass(AmbrosianTemporale::class)]
#[Group('slow')]
final class AmbrosianTemporaleCompletenessTest extends TestCase
{
    use AmbrosianTemporaleHarnessTrait;

    /** @return array<int,array{0:int}> */
    public static function civilYears(): array
    {
        return [
            [2024],
            [2025],
            [2026],
        ];
    }

    #[DataProvider('civilYears')]
    public function testTemporalYearIsGapFree(int $year): void
    {
        $events = $this->runEngineEvents($year);

        // 1. Every event must carry a resolved liturgical season.
        foreach ($events as $key => $event) {
            self::assertNotNull($event->liturgical_season, "Event '$key' ($year) is missing a liturgical_season");
        }

        // 2. Zero duplicate dates: index events by Y-m-d and detect collisions.
        /** @var array<string,string> $byDate map of 'Y-m-d' => event_key */
        $byDate = [];
        foreach ($events as $key => $event) {
            $date = $event->date->format('Y-m-d');
            if (array_key_exists($date, $byDate)) {
                self::fail("Duplicate temporale event on $date ($year): '{$byDate[$date]}' and '$key'");
            }
            $byDate[$date] = $key;
        }

        // 3. Compute the single allowlisted exception: the Sunday within the
        // Christmas octave window [Dec 26, Dec 31] of $year, if one exists.
        // n.32 "domenica nell'ottava del Natale" has its vigil-shift placement
        // deferred by spec §4 to ordo-validation, and in the assembled
        // calendar the sanctorale (Stephen/John/Innocents) covers that day,
        // so it is expected to be absent from the temporale-only view.
        $dec26 = DateTime::fromFormat('26-12-' . $year);
        $dec31 = DateTime::fromFormat('31-12-' . $year);
        if ((int) $dec26->format('N') === 7) {
            $octaveSunday = $dec26;
        } else {
            $octaveSunday = ( clone $dec26 )->modify('next Sunday');
        }
        $allow = $octaveSunday <= $dec31 ? [$octaveSunday->format('Y-m-d')] : [];

        // 4. Walk Jan 1 -> Dec 31 of $year; every day must be covered by the
        // temporale except the allowlisted octave Sunday computed above.
        $cursor    = DateTime::fromFormat('1-1-' . $year);
        $yearEnd   = DateTime::fromFormat('31-12-' . $year);
        $uncovered = [];
        while ($cursor <= $yearEnd) {
            $iso = $cursor->format('Y-m-d');
            if (!array_key_exists($iso, $byDate) && !in_array($iso, $allow, true)) {
                $weekday     = $cursor->format('l');
                $uncovered[] = "$iso ($weekday)";
            }
            $cursor->modify('+1 day');
        }

        self::assertSame(
            [],
            $uncovered,
            "Uncovered day(s) found in $year temporale coverage (excluding allowlisted octave Sunday): "
                . implode(', ', $uncovered)
        );

        // 5. Guard the allowlist itself: the octave Sunday, when it exists,
        // must genuinely be uncovered by the temporale. If a future engine
        // change starts covering it, this assertion should fail so the
        // allowlist is updated deliberately rather than silently masking
        // renewed (or regressed) coverage.
        if ($allow !== []) {
            self::assertArrayNotHasKey(
                $allow[0],
                $byDate,
                'octave Sunday expected to be temporale-uncovered (n.32 deferred, spec §4)'
            );
        }
    }
}
