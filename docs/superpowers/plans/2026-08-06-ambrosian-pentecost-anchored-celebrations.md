# Ambrosian Pentecost-anchored celebrations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement
  this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the five celebrations the Ambrosian Missal anchors to Pentecost — Mary Mother of the Church, the Most Holy Trinity, Corpus Domini, the
Sacred Heart and the Immaculate Heart — which are currently absent from `/calendar/ambrosian` entirely.

**Architecture:** All five go into the Ambrosian Proprium de Tempore data file as `type: "mobile"` entries (matching the Roman precedent, which holds
`Trinity`, `CorpusChristi`, `SacredHeart` and `ImmaculateHeart` in its own temporale) and are placed as anchors in
`AmbrosianTemporale::calculateAfterPentecostAnchors()`. No new engine mechanism is required: the anchor pass already runs before the Sunday-numbering
and ferial-fill passes, both of which skip days already occupied. Historical gating for Mary Mother of the Church (instituted 2018) is added as
optional `since_year`/`until_year` on the Proprium de Tempore schema and model.

**Tech Stack:** PHP 8.4, PHPUnit 12, JSON Schema (draft-07), gettext i18n.

## Global Constraints

- PHP >= 8.4; PSR-12 via `vendor/bin/phpcs`; PHPStan level 10 via `composer analyse` (scans `src` only).
- Ambrosian rite floor is **1976** (`CalendarParams::AMBROSIAN_YEAR_LOWER_LIMIT`).
- Ambrosian temporale i18n ships **exactly two locales**: `it` and `la`.
- `PropriumDeTemporeMap::setNames()` **throws** `InvalidArgumentException` listing any key missing a translation. The data file and both i18n files must
  therefore change in the **same commit**, or every Ambrosian request breaks.
- `LitSeason::forEventKey()` is **rite-agnostic** and is also consumed by the Roman `/temporale` endpoint. It must **not** be modified.
  Ambrosian-specific season classification belongs in `AmbrosianTemporale::stampSeason()`, which already local-overrides `ChristKing` for exactly this
  reason.
- Never use `--no-verify`. CaptainHook runs `composer lint`, `composer lint:md` and PHP linting pre-commit.
- Commits are GPG-signed. If signing times out, ask the user to unlock; never disable signing.

### Environment note

A fresh worktree has no `.env.local` (it is gitignored). Copy it from the main checkout and correct two ports, or `Routes/*` and `Repositories/*` tests error instead of skipping:

```bash
cp /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI/.env.local .env.local
sed -i 's/^DB_PORT=5432$/DB_PORT=15432/' .env.local   # 5432 is another project's container
sed -i 's/^API_PORT=8000$/API_PORT=8001/' .env.local  # 8000 is another project's API
```

None of the tasks below require a running server or database.

---

## Source of truth

**Messale Ambrosiano II ed. 2024, Premesse/Praenotanda** — readable from WSL at
`/mnt/c/Users/johnr/Documents/LitCal/Ambrosian Missals/Premesse_praenotanda_portale.pdf`.
`pdftotext` is not installed; use Python `fitz` (PyMuPDF).

**Extraction gotcha:** in the *calendario ambrosiano* the grade sits in a separate left-hand column, and a plain `page.get_text()` binds it to the
wrong row. Reconstruct rows from word coordinates:

```python
rows = {}
for w in page.get_text('words'):          # x0, y0, x1, y1, word, ...
    rows.setdefault(round(w[1] / 3), []).append((w[0], w[4]))
for y in sorted(rows):
    print(' '.join(t for _, t in sorted(rows[y])))
```

### The five celebrations

From the *calendario ambrosiano*, pp. LXXV and LXXVII. `dS` = *del Signore*, modelled by the existing `is_dominical` field.

| Missal rule                                        | Celebration                             | Grade            | `event_key`        | Easter offset |
|----------------------------------------------------|-----------------------------------------|------------------|--------------------|---------------|
| Lunedì dopo Pentecoste                             | B. Vergine Maria, Madre della Chiesa    | Memoria (3)      | `MaryMotherChurch` | +50           |
| I domenica dopo Pentecoste                         | SS. TRINITÀ                             | Solennità dS (6) | `Trinity`          | +56           |
| Giovedì successivo alla I domenica dopo Pentecoste | SS. CORPO E SANGUE DI CRISTO            | Solennità dS (6) | `CorpusChristi`    | +60           |
| Venerdì dopo la II domenica dopo Pentecoste        | SACRATISSIMO CUORE DI GESÙ              | Solennità dS (6) | `SacredHeart`      | +68           |
| Sabato dopo la II domenica dopo Pentecoste         | Cuore Immacolato della b. Vergine Maria | Memoria (3)      | `ImmaculateHeart`  | +69           |

### Why grade 6 and not the Roman 7

The Roman temporale grades `Trinity` and `CorpusChristi` as **7** (`HIGHER_SOLEMNITY`). **Do not copy that.** The Missal's own *Tabella dei giorni
liturgici disposta secondo l'ordine di precedenza* (pp. LXV–LXVI) separates them:

- **Rank 2** — Natale, Epifania, **Ascensione, Pentecoste**, Sundays of Advent/Lent/Easter, *Sabato in traditione symboli*, octave days, and the
  **Dedicazione del Duomo di Milano**. These are exactly the keys the existing Ambrosian data grades **7**.
- **Rank 3** — "**Solennità e feste del Signore**, elencate nel Calendario comune ambrosiano." This is where Trinity, Corpus Domini and the Sacred Heart
  sit, alongside `ChristKing`, which the existing data grades **6**.
- **Rank 4** — "domeniche… dopo Pentecoste".

Rank 3 outranking rank 4 is also what makes Trinity correctly displace the *I domenica dopo Pentecoste*.

### Verified against the Missal's annual table

The *Tabella annuale* (pp. LXXXVIII–LXXXIX) fixes dates for 2025–2056. Checked across all 32 rows with zero mismatches: Ascension = Easter+39,
Pentecost = Easter+49, Corpus Domini = Pentecost+11 and a Thursday in every year.

---

## File Structure

**Modified:**

- `jsondata/schemas/PropriumDeTempore.json` — add optional `since_year` / `until_year`.
- `src/Models/PropriumDeTemporeEvent.php` — parse and expose those two fields.
- `jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore/propriumdetempore.json` — +5 entries (38 → 43).
- `jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore/i18n/it.json` — +5 names.
- `jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore/i18n/la.json` — +5 names.
- `src/Models/Calendar/Temporale/AmbrosianTemporale.php` — season override + anchor placement + year gate.
- `README.md` — note the five celebrations under the Ambrosian bullet.

**Created:**

- `phpunit_tests/fixtures/ambrosian_annual_table_2025_2056.json` — the Missal's annual table.
- `phpunit_tests/Models/Calendar/Temporale/AmbrosianAnnualTableTest.php` — data-driven regression gate over 32 years.

**Not modified (deliberately):** `src/Enum/LitSeason.php` — see Global Constraints.

---

### Task 1: Optional year gating on the Proprium de Tempore schema and model

Mary Mother of the Church was instituted in 2018 and must not appear in earlier years. `PropriumDeTempore.json` sets `additionalProperties: false`, so
the schema must allow the fields before the data can carry them. `since_year`/`until_year` is already the codebase's idiom (`RomanMissal`,
`AmbrosianMissal` year limits).

Both fields are optional and no existing entry uses them, so this is backward-compatible for both rites.

**Files:**

- Modify: `jsondata/schemas/PropriumDeTempore.json`
- Modify: `src/Models/PropriumDeTemporeEvent.php`
- Test: `phpunit_tests/Models/Calendar/Temporale/AmbrosianProprioDeTemporeDataTest.php`

**Interfaces:**

- Produces: `PropriumDeTemporeEvent::$since_year` and `::$until_year`, both `public readonly ?int`, `null` when absent. Consumed by Task 4's `isInForce()`.

- [ ] **Step 1: Write the failing test**

Append to `phpunit_tests/Models/Calendar/Temporale/AmbrosianProprioDeTemporeDataTest.php`:

```php
    public function testYearGatingFieldsDefaultToNull(): void
    {
        $event = PropriumDeTemporeEvent::fromObject((object) [
            'event_key' => 'TestEvent',
            'grade'     => 6,
            'type'      => 'mobile',
            'color'     => ['white'],
        ]);

        self::assertNull($event->since_year);
        self::assertNull($event->until_year);
    }

    public function testYearGatingFieldsAreParsedWhenPresent(): void
    {
        $event = PropriumDeTemporeEvent::fromObject((object) [
            'event_key'  => 'TestEvent',
            'grade'      => 3,
            'type'       => 'mobile',
            'color'      => ['white'],
            'since_year' => 2018,
            'until_year' => 2030,
        ]);

        self::assertSame(2018, $event->since_year);
        self::assertSame(2030, $event->until_year);
    }
```

Add the import at the top of the file if not already present:

```php
use LiturgicalCalendar\Api\Models\PropriumDeTemporeEvent;
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianProprioDeTemporeDataTest.php --filter YearGating`

Expected: FAIL — `Undefined property: ...PropriumDeTemporeEvent::$since_year`.

- [ ] **Step 3: Add the fields to the schema**

In `jsondata/schemas/PropriumDeTempore.json`, inside `definitions.LitEvent.properties`, after `is_aliturgical`:

```json
                "since_year": {
                    "type": "integer",
                    "description": "First year in which the event is celebrated. Optional; when absent the event is not year-gated."
                },
                "until_year": {
                    "type": "integer",
                    "description": "Last year in which the event is celebrated. Optional; when absent the event is not year-gated."
                }
```

- [ ] **Step 4: Add the fields to the model**

In `src/Models/PropriumDeTemporeEvent.php`, after the `$is_aliturgical` property declaration:

```php
    /**
     * First year in which the event is celebrated, if the source data gates it. Null when ungated.
     */
    public readonly ?int $since_year;
    /**
     * Last year in which the event is celebrated, if the source data gates it. Null when ungated.
     */
    public readonly ?int $until_year;
```

Extend the constructor signature and body (append the two parameters after `$is_aliturgical`):

```php
        ?bool $is_aliturgical = null,
        ?int $since_year = null,
        ?int $until_year = null
    ) {
```

and inside the constructor body, after `$this->is_aliturgical = $is_aliturgical;`:

```php
        $this->since_year     = $since_year;
        $this->until_year     = $until_year;
```

In `fromObjectInternal()`, after the `$is_aliturgical` block:

```php
        $since_year = null;
        if (property_exists($data, 'since_year') && is_int($data->since_year)) {
            $since_year = $data->since_year;
        }

        $until_year = null;
        if (property_exists($data, 'until_year') && is_int($data->until_year)) {
            $until_year = $data->until_year;
        }
```

and extend the `new static(...)` call, after `$is_aliturgical`:

```php
            $is_aliturgical,
            $since_year,
            $until_year
        );
```

Update the `@param` docblock on the constructor and the `@param` type on `fromObjectInternal()` to include `since_year?:int|null,until_year?:int|null`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianProprioDeTemporeDataTest.php`

Expected: PASS.

- [ ] **Step 6: Verify nothing else regressed**

Run: `vendor/bin/phpunit --exclude-group slow phpunit_tests/Models phpunit_tests/Schemas`

Expected: PASS. Then:

Run: `composer analyse && vendor/bin/phpcs src/Models/PropriumDeTemporeEvent.php`

Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add jsondata/schemas/PropriumDeTempore.json src/Models/PropriumDeTemporeEvent.php phpunit_tests/Models/Calendar/Temporale/AmbrosianProprioDeTemporeDataTest.php
git commit -m "feat(temporale): add optional since_year/until_year to the Proprium de Tempore"
```

---

### Task 2: Pin current engine behaviour against the Missal's annual table

This creates the 32-year regression net **before** any behaviour changes, so Task 4 can prove it shifted nothing. Corpus Domini is deliberately
**not** asserted yet — it does not exist until Task 4, and this task must pass on unmodified code.

**Date-to-engine-year mapping (easy to get wrong):** the table's *I domenica di avvento* for row year `Y` falls in November of `Y-1`, because it opens
the liturgical year named `Y`. The engine computes `Advent1` for the civil year it is asked about. So assert `advent1` against `runEngine(Y -
1)['Advent1']`, and every other column against `runEngine(Y)`.

**Files:**

- Create: `phpunit_tests/fixtures/ambrosian_annual_table_2025_2056.json`
- Create: `phpunit_tests/Models/Calendar/Temporale/AmbrosianAnnualTableTest.php`

**Interfaces:**

- Consumes: `AmbrosianTemporaleHarnessTrait::runEngine(int $year): array<string,string>` (key => `Y-m-d`), already present at `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleHarnessTrait.php`.
- Produces: the fixture and the `annualTableRows()` provider, extended in Task 5.

- [ ] **Step 1: Create the fixture**

Create `phpunit_tests/fixtures/ambrosian_annual_table_2025_2056.json`, transcribed from Premesse/Praenotanda pp. LXXXVIII–LXXXIX:

```json
[
  {"year": 2025, "advent1": "2024-11-17", "lent1": "2025-03-09", "easter": "2025-04-20", "ascension": "2025-05-29", "pentecost": "2025-06-08", "corpus_domini": "2025-06-19", "dedication_duomo": "2025-10-19"},
  {"year": 2026, "advent1": "2025-11-16", "lent1": "2026-02-22", "easter": "2026-04-05", "ascension": "2026-05-14", "pentecost": "2026-05-24", "corpus_domini": "2026-06-04", "dedication_duomo": "2026-10-18"},
  {"year": 2027, "advent1": "2026-11-15", "lent1": "2027-02-14", "easter": "2027-03-28", "ascension": "2027-05-06", "pentecost": "2027-05-16", "corpus_domini": "2027-05-27", "dedication_duomo": "2027-10-17"},
  {"year": 2028, "advent1": "2027-11-14", "lent1": "2028-03-05", "easter": "2028-04-16", "ascension": "2028-05-25", "pentecost": "2028-06-04", "corpus_domini": "2028-06-15", "dedication_duomo": "2028-10-15"},
  {"year": 2029, "advent1": "2028-11-12", "lent1": "2029-02-18", "easter": "2029-04-01", "ascension": "2029-05-10", "pentecost": "2029-05-20", "corpus_domini": "2029-05-31", "dedication_duomo": "2029-10-21"},
  {"year": 2030, "advent1": "2029-11-18", "lent1": "2030-03-10", "easter": "2030-04-21", "ascension": "2030-05-30", "pentecost": "2030-06-09", "corpus_domini": "2030-06-20", "dedication_duomo": "2030-10-20"},
  {"year": 2031, "advent1": "2030-11-17", "lent1": "2031-03-02", "easter": "2031-04-13", "ascension": "2031-05-22", "pentecost": "2031-06-01", "corpus_domini": "2031-06-12", "dedication_duomo": "2031-10-19"},
  {"year": 2032, "advent1": "2031-11-16", "lent1": "2032-02-15", "easter": "2032-03-28", "ascension": "2032-05-06", "pentecost": "2032-05-16", "corpus_domini": "2032-05-27", "dedication_duomo": "2032-10-17"},
  {"year": 2033, "advent1": "2032-11-14", "lent1": "2033-03-06", "easter": "2033-04-17", "ascension": "2033-05-26", "pentecost": "2033-06-05", "corpus_domini": "2033-06-16", "dedication_duomo": "2033-10-16"},
  {"year": 2034, "advent1": "2033-11-13", "lent1": "2034-02-26", "easter": "2034-04-09", "ascension": "2034-05-18", "pentecost": "2034-05-28", "corpus_domini": "2034-06-08", "dedication_duomo": "2034-10-15"},
  {"year": 2035, "advent1": "2034-11-12", "lent1": "2035-02-11", "easter": "2035-03-25", "ascension": "2035-05-03", "pentecost": "2035-05-13", "corpus_domini": "2035-05-24", "dedication_duomo": "2035-10-21"},
  {"year": 2036, "advent1": "2035-11-18", "lent1": "2036-03-02", "easter": "2036-04-13", "ascension": "2036-05-22", "pentecost": "2036-06-01", "corpus_domini": "2036-06-12", "dedication_duomo": "2036-10-19"},
  {"year": 2037, "advent1": "2036-11-16", "lent1": "2037-02-22", "easter": "2037-04-05", "ascension": "2037-05-14", "pentecost": "2037-05-24", "corpus_domini": "2037-06-04", "dedication_duomo": "2037-10-18"},
  {"year": 2038, "advent1": "2037-11-15", "lent1": "2038-03-14", "easter": "2038-04-25", "ascension": "2038-06-03", "pentecost": "2038-06-13", "corpus_domini": "2038-06-24", "dedication_duomo": "2038-10-17"},
  {"year": 2039, "advent1": "2038-11-14", "lent1": "2039-02-27", "easter": "2039-04-10", "ascension": "2039-05-19", "pentecost": "2039-05-29", "corpus_domini": "2039-06-09", "dedication_duomo": "2039-10-16"},
  {"year": 2040, "advent1": "2039-11-13", "lent1": "2040-02-19", "easter": "2040-04-01", "ascension": "2040-05-10", "pentecost": "2040-05-20", "corpus_domini": "2040-05-31", "dedication_duomo": "2040-10-21"},
  {"year": 2041, "advent1": "2040-11-18", "lent1": "2041-03-10", "easter": "2041-04-21", "ascension": "2041-05-30", "pentecost": "2041-06-09", "corpus_domini": "2041-06-20", "dedication_duomo": "2041-10-20"},
  {"year": 2042, "advent1": "2041-11-17", "lent1": "2042-02-23", "easter": "2042-04-06", "ascension": "2042-05-15", "pentecost": "2042-05-25", "corpus_domini": "2042-06-05", "dedication_duomo": "2042-10-19"},
  {"year": 2043, "advent1": "2042-11-16", "lent1": "2043-02-15", "easter": "2043-03-29", "ascension": "2043-05-07", "pentecost": "2043-05-17", "corpus_domini": "2043-05-28", "dedication_duomo": "2043-10-18"},
  {"year": 2044, "advent1": "2043-11-15", "lent1": "2044-03-06", "easter": "2044-04-17", "ascension": "2044-05-26", "pentecost": "2044-06-05", "corpus_domini": "2044-06-16", "dedication_duomo": "2044-10-16"},
  {"year": 2045, "advent1": "2044-11-13", "lent1": "2045-02-26", "easter": "2045-04-09", "ascension": "2045-05-18", "pentecost": "2045-05-28", "corpus_domini": "2045-06-08", "dedication_duomo": "2045-10-15"},
  {"year": 2046, "advent1": "2045-11-12", "lent1": "2046-02-11", "easter": "2046-03-25", "ascension": "2046-05-03", "pentecost": "2046-05-13", "corpus_domini": "2046-05-24", "dedication_duomo": "2046-10-21"},
  {"year": 2047, "advent1": "2046-11-18", "lent1": "2047-03-03", "easter": "2047-04-14", "ascension": "2047-05-23", "pentecost": "2047-06-02", "corpus_domini": "2047-06-13", "dedication_duomo": "2047-10-20"},
  {"year": 2048, "advent1": "2047-11-17", "lent1": "2048-02-23", "easter": "2048-04-05", "ascension": "2048-05-14", "pentecost": "2048-05-24", "corpus_domini": "2048-06-04", "dedication_duomo": "2048-10-18"},
  {"year": 2049, "advent1": "2048-11-15", "lent1": "2049-03-07", "easter": "2049-04-18", "ascension": "2049-05-27", "pentecost": "2049-06-06", "corpus_domini": "2049-06-17", "dedication_duomo": "2049-10-17"},
  {"year": 2050, "advent1": "2049-11-14", "lent1": "2050-02-27", "easter": "2050-04-10", "ascension": "2050-05-19", "pentecost": "2050-05-29", "corpus_domini": "2050-06-09", "dedication_duomo": "2050-10-16"},
  {"year": 2051, "advent1": "2050-11-13", "lent1": "2051-02-19", "easter": "2051-04-02", "ascension": "2051-05-11", "pentecost": "2051-05-21", "corpus_domini": "2051-06-01", "dedication_duomo": "2051-10-15"},
  {"year": 2052, "advent1": "2051-11-12", "lent1": "2052-03-10", "easter": "2052-04-21", "ascension": "2052-05-30", "pentecost": "2052-06-09", "corpus_domini": "2052-06-20", "dedication_duomo": "2052-10-20"},
  {"year": 2053, "advent1": "2052-11-17", "lent1": "2053-02-23", "easter": "2053-04-06", "ascension": "2053-05-15", "pentecost": "2053-05-25", "corpus_domini": "2053-06-05", "dedication_duomo": "2053-10-19"},
  {"year": 2054, "advent1": "2053-11-16", "lent1": "2054-02-15", "easter": "2054-03-29", "ascension": "2054-05-07", "pentecost": "2054-05-17", "corpus_domini": "2054-05-28", "dedication_duomo": "2054-10-18"},
  {"year": 2055, "advent1": "2054-11-15", "lent1": "2055-03-07", "easter": "2055-04-18", "ascension": "2055-05-27", "pentecost": "2055-06-06", "corpus_domini": "2055-06-17", "dedication_duomo": "2055-10-17"},
  {"year": 2056, "advent1": "2055-11-14", "lent1": "2056-02-20", "easter": "2056-04-02", "ascension": "2056-05-11", "pentecost": "2056-05-21", "corpus_domini": "2056-06-01", "dedication_duomo": "2056-10-15"}
]
```

- [ ] **Step 2: Write the test**

Create `phpunit_tests/Models/Calendar/Temporale/AmbrosianAnnualTableTest.php`:

```php
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
}
```

- [ ] **Step 3: Run the test to verify it passes on unmodified code**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianAnnualTableTest.php`

Expected: PASS, 64 tests. This is the point of the task — the net must be green **before** anything changes. If any assertion fails here, stop: the
engine disagrees with the Missal on something this plan does not touch, and that needs its own investigation.

- [ ] **Step 4: Commit**

```bash
git add phpunit_tests/fixtures/ambrosian_annual_table_2025_2056.json phpunit_tests/Models/Calendar/Temporale/AmbrosianAnnualTableTest.php
git commit -m "test(ambrosian): pin the temporale against the Missal's 32-year annual table"
```

---

### Task 3: Add the five celebrations to the Proprium de Tempore data and both i18n files

Data only — the engine does not place them yet, so the calendar output is unchanged after this task. The three files must be committed **together**
because `PropriumDeTemporeMap::setNames()` throws for any key lacking a translation.

Latin names are taken from the Roman temporale i18n (`jsondata/sourcedata/rite/roman/missals/propriumdetempore/i18n/la.json`) and the decrees i18n,
since these are the same celebrations. Italian names are the Missal's own wording from the *calendario ambrosiano*.

**Files:**

- Modify: `jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore/propriumdetempore.json`
- Modify: `jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore/i18n/it.json`
- Modify: `jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore/i18n/la.json`
- Test: `phpunit_tests/Models/Calendar/Temporale/AmbrosianProprioDeTemporeDataTest.php`

**Interfaces:**

- Produces: the five `event_key` values `MaryMotherChurch`, `Trinity`, `CorpusChristi`, `SacredHeart`, `ImmaculateHeart` in the Ambrosian Proprium de Tempore, consumed by Task 4.

- [ ] **Step 1: Write the failing test**

Append to `phpunit_tests/Models/Calendar/Temporale/AmbrosianProprioDeTemporeDataTest.php`:

```php
    /** @return array<string,array{0:string,1:int,2:bool,3:int|null}> */
    public static function pentecostAnchoredCelebrations(): array
    {
        // key => [event_key, grade, is_dominical, since_year]
        return [
            'Mary Mother of the Church' => ['MaryMotherChurch', 3, false, 2018],
            'Most Holy Trinity'         => ['Trinity', 6, true, null],
            'Corpus Domini'             => ['CorpusChristi', 6, true, null],
            'Sacred Heart'              => ['SacredHeart', 6, true, null],
            'Immaculate Heart'          => ['ImmaculateHeart', 3, false, null],
        ];
    }

    #[DataProvider('pentecostAnchoredCelebrations')]
    public function testPentecostAnchoredCelebrationsArePresent(
        string $eventKey,
        int $grade,
        bool $isDominical,
        ?int $sinceYear
    ): void {
        $map = $this->loadPropriumDeTemporeMap();

        self::assertTrue($map->offsetExists($eventKey), "Missing Proprium de Tempore entry: $eventKey");

        $event = $map[$eventKey];
        self::assertSame($grade, $event->grade->value, "$eventKey grade");
        self::assertSame($isDominical, $event->is_dominical, "$eventKey is_dominical");
        self::assertSame($sinceYear, $event->since_year, "$eventKey since_year");
        self::assertSame('mobile', $event->type->value, "$eventKey type");
        self::assertSame(['white'], array_map(static fn ($c): string => $c->value, $event->color), "$eventKey color");
    }

    #[DataProvider('pentecostAnchoredCelebrations')]
    public function testPentecostAnchoredCelebrationsAreTranslatedInEveryShippedLocale(string $eventKey): void
    {
        foreach (['it', 'la'] as $locale) {
            $names = $this->loadI18n($locale);
            self::assertArrayHasKey($eventKey, $names, "$eventKey missing from $locale.json");
            self::assertNotSame('', trim($names[$eventKey]), "$eventKey is empty in $locale.json");
        }
    }
```

Inspect the existing test class first: if it already has helpers that load the data file and the i18n files, reuse them and drop the
`loadPropriumDeTemporeMap()` / `loadI18n()` names above in favour of the existing ones. If it does not, add:

```php
    private function loadPropriumDeTemporeMap(): \LiturgicalCalendar\Api\Models\PropriumDeTemporeMap
    {
        \LiturgicalCalendar\Api\Router::getApiPaths();
        $raw = \LiturgicalCalendar\Api\Utilities::jsonFileToObjectArray(
            \LiturgicalCalendar\Api\Enum\JsonData::AMBROSIAN_TEMPORALE_FILE->path()
        );
        return \LiturgicalCalendar\Api\Models\PropriumDeTemporeMap::fromObject($raw);
    }

    /** @return array<string,string> */
    private function loadI18n(string $locale): array
    {
        \LiturgicalCalendar\Api\Router::getApiPaths();
        /** @var array<string,string> $names */
        $names = \LiturgicalCalendar\Api\Utilities::jsonFileToArray(strtr(
            \LiturgicalCalendar\Api\Enum\JsonData::AMBROSIAN_TEMPORALE_I18N_FILE->path(),
            ['{locale}' => $locale]
        ));
        return $names;
    }
```

Ensure `use PHPUnit\Framework\Attributes\DataProvider;` is imported.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianProprioDeTemporeDataTest.php --filter PentecostAnchored`

Expected: FAIL — "Missing Proprium de Tempore entry: MaryMotherChurch".

- [ ] **Step 3: Add the data entries**

In `jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore/propriumdetempore.json`, insert these five objects immediately after the `Pentecost`
entry and before `DedicationDuomo`, so the file stays in calendar order:

```json
  {
    "event_key": "MaryMotherChurch",
    "grade": 3,
    "type": "mobile",
    "color": [
      "white"
    ],
    "is_dominical": false,
    "since_year": 2018
  },
  {
    "event_key": "Trinity",
    "grade": 6,
    "type": "mobile",
    "color": [
      "white"
    ],
    "is_dominical": true
  },
  {
    "event_key": "CorpusChristi",
    "grade": 6,
    "type": "mobile",
    "color": [
      "white"
    ],
    "is_dominical": true
  },
  {
    "event_key": "SacredHeart",
    "grade": 6,
    "type": "mobile",
    "color": [
      "white"
    ],
    "is_dominical": true
  },
  {
    "event_key": "ImmaculateHeart",
    "grade": 3,
    "type": "mobile",
    "color": [
      "white"
    ],
    "is_dominical": false
  },
```

- [ ] **Step 4: Add the Italian names**

In `i18n/it.json`, using the Missal's own wording:

```json
  "MaryMotherChurch": "Beata Vergine Maria, Madre della Chiesa",
  "Trinity": "Santissima Trinità",
  "CorpusChristi": "Santissimo Corpo e Sangue di Cristo",
  "SacredHeart": "Sacratissimo Cuore di Gesù",
  "ImmaculateHeart": "Cuore Immacolato della beata Vergine Maria",
```

- [ ] **Step 5: Add the Latin names**

In `i18n/la.json`:

```json
  "MaryMotherChurch": "Beatæ Mariæ Virginis, Ecclesiæ Matris",
  "Trinity": "Dominica post Pentecostem Sanctissimæ Trinitatis",
  "CorpusChristi": "Ss.mi Corporis et Sanguinis Christi",
  "SacredHeart": "Sacratissimi Cordis Iesu",
  "ImmaculateHeart": "Immaculati Cordis Beatæ Mariæ Virginis",
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianProprioDeTemporeDataTest.php`

Expected: PASS.

- [ ] **Step 7: Verify the calendar still builds and is unchanged**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/ phpunit_tests/Handlers/CalendarHandlerAmbrosianOrchestratorTest.php`

Expected: PASS. In particular `AmbrosianTemporaleTest` and `AmbrosianAnnualTableTest` must still pass — the entries exist but are not placed, so no
date changes. If `setNames()` throws here, an i18n key is missing.

Run: `vendor/bin/phpunit phpunit_tests/Schemas/`

Do NOT use `--group slow` to select these: `phpunit_tests/Schemas/SchemaValidationTest.php` marks its slow tests with legacy `@group slow`
DOCBLOCKS, and PHPUnit 12 in this repo honours only the `#[Group('slow')]` ATTRIBUTE — so `--group slow phpunit_tests/Schemas/` selects
zero tests and silently reports success. Select by path instead.

Expected: PASS — the data file still validates against the updated schema.

- [ ] **Step 8: Commit**

```bash
git add jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore/
git add phpunit_tests/Models/Calendar/Temporale/AmbrosianProprioDeTemporeDataTest.php
git commit -m "feat(ambrosian): add the five Pentecost-anchored celebrations to the temporale data"
```

---

### Task 4: Classify and place the five Pentecost-anchored celebrations

`AmbrosianTemporale::stampSeason()` calls `LitSeason::forEventKey()`, whose default branch returns `ORDINARY_TIME` and names `Trinity` and
`CorpusChristi` explicitly as examples. That is correct for the Roman rite and wrong for the Ambrosian, where these belong to the *tempo dopo
Pentecoste*.

`forEventKey()` is rite-agnostic and shared with the Roman `/temporale` endpoint, so it must not be touched — `stampSeason()` already local-overrides
`ChristKing` for exactly this reason. Extend that override.

The placement is the behaviour change. `buildTemporale()` already calls `calculateAfterPentecostAnchors()` (line 37) before
`calculateAfterPentecostSundays()` (line 40) and `calculateAfterPentecostWeekdays()` (line 41). `numberSundayBlock()` skips a Sunday when
`$ctx->cal->inCalendar($sunday)` is true **while still incrementing its ordinal**, and `fillFerialWeekdays()` guards each weekday with the same check.
So placing the anchors first is sufficient, and no downstream numbering shifts: the Sunday after Trinity is `AfterPentecost2` both before and after
this change — only `AfterPentecost1` stops being emitted, which is correct, because in the Ambrosian rite the I domenica dopo Pentecoste **is** the
Most Holy Trinity.

Season classification and placement are one task because neither is independently reviewable: the season stamp is unobservable until the celebrations
are placed, and the placement is only correct because the stamp is.

**Files:**

- Modify: `src/Models/Calendar/Temporale/AmbrosianTemporale.php` (`stampSeason()`, and `calculateAfterPentecostAnchors()` at `:220-238`)
- Test: `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`

**Interfaces:**

- Consumes: `PropriumDeTemporeEvent::$since_year` / `$until_year` (Task 1) and the five data entries (Task 3).
- Produces: `AmbrosianTemporale::AFTER_PENTECOST_SEASON_OVERRIDES` (a `private const array` of event keys stamped `LitSeason::AFTER_PENTECOST`),
  `AmbrosianTemporale::placeEasterOffsetAnchor(string $key, int $easterOffset, TemporaleContext $ctx): void`, and `AmbrosianTemporale::isInForce(string
  $key, TemporaleContext $ctx): bool`.

- [ ] **Step 1: Write the failing test**

Append to `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`:

```php
    public function testPentecostAnchoredCelebrationsAreStampedAfterPentecost(): void
    {
        $events = $this->runEngineEvents(2026);

        foreach (['Trinity', 'CorpusChristi', 'SacredHeart', 'ImmaculateHeart', 'MaryMotherChurch'] as $key) {
            self::assertArrayHasKey($key, $events, "Expected $key in the 2026 temporale");
            self::assertSame(
                LitSeason::AFTER_PENTECOST,
                $events[$key]->liturgical_season,
                "$key must be stamped AFTER_PENTECOST, not the rite-agnostic Ordinary Time default"
            );
        }
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php --filter StampedAfterPentecost`

Expected: FAIL — "Expected Trinity in the 2026 temporale". It stays red until Step 9 places the celebrations; the season rule is written first because
it is what makes the placement safe.

- [ ] **Step 3: Add the override constant**

In `src/Models/Calendar/Temporale/AmbrosianTemporale.php`, near the other class constants:

```php
    /**
     * Event keys whose Ambrosian season differs from the rite-agnostic
     * {@see LitSeason::forEventKey()} classification and must be stamped
     * AFTER_PENTECOST locally.
     *
     * `ChristKing` is the last Sunday after the Dedication in the Ambrosian rite but the
     * last Sunday of Ordinary Time in the Roman. The five Pentecost-anchored celebrations
     * fall in the Ambrosian tempo dopo Pentecoste, while `forEventKey()`'s default branch
     * returns ORDINARY_TIME for them (correctly, for the Roman rite). `forEventKey()` is
     * shared with the Roman /temporale endpoint and must not be changed.
     */
    private const array AFTER_PENTECOST_SEASON_OVERRIDES = [
        'ChristKing',
        'MaryMotherChurch',
        'Trinity',
        'CorpusChristi',
        'SacredHeart',
        'ImmaculateHeart',
    ];
```

- [ ] **Step 4: Use it in stampSeason()**

Replace the body of `stampSeason()`:

```php
    private function stampSeason(LiturgicalEvent $event): void
    {
        $event->liturgical_season = in_array($event->event_key, self::AFTER_PENTECOST_SEASON_OVERRIDES, true)
            ? LitSeason::AFTER_PENTECOST
            : LitSeason::forEventKey($event->event_key);
    }
```

Update the method docblock so it describes the constant rather than only `ChristKing`.

- [ ] **Step 5: Confirm the ChristKing behaviour is unchanged by the refactor**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php --filter ChristKing`

Expected: PASS. The season test from Step 1 is still red at this point — it needs the placement in Step 6, which is why these are one task.

- [ ] **Step 6: Write the failing placement test**

Append to `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`:

```php
    public function testPentecostAnchoredCelebrations2026(): void
    {
        $d = $this->runEngine(2026);

        // Pentecost 2026 = 24 May (Messale Ambrosiano, Tabella annuale).
        self::assertSame('2026-05-24', $d['Pentecost']);
        self::assertSame('2026-05-25', $d['MaryMotherChurch']);  // Lunedì dopo Pentecoste
        self::assertSame('2026-05-31', $d['Trinity']);           // I domenica dopo Pentecoste
        self::assertSame('2026-06-04', $d['CorpusChristi']);     // Giovedì successivo
        self::assertSame('2026-06-12', $d['SacredHeart']);       // Venerdì dopo la II domenica
        self::assertSame('2026-06-13', $d['ImmaculateHeart']);   // Sabato dopo la II domenica
    }

    public function testTrinityConsumesTheFirstSundayAfterPentecost(): void
    {
        $d = $this->runEngine(2026);

        // Trinity occupies the I domenica dopo Pentecoste, so AfterPentecost1 is no longer
        // emitted -- but the numbering of every later Sunday is unchanged, which is what
        // lets the Missal anchor the Sacred Heart to "la II domenica dopo Pentecoste".
        self::assertArrayNotHasKey('AfterPentecost1', $d);
        self::assertSame('2026-06-07', $d['AfterPentecost2']);
    }

    public function testMaryMotherChurchIsGatedToItsInstitutionYear(): void
    {
        $before = $this->runEngine(2017);
        self::assertArrayNotHasKey('MaryMotherChurch', $before);

        $after = $this->runEngine(2018);
        self::assertArrayHasKey('MaryMotherChurch', $after);

        // The other four are ancient and present on both sides of the gate.
        foreach (['Trinity', 'CorpusChristi', 'SacredHeart', 'ImmaculateHeart'] as $key) {
            self::assertArrayHasKey($key, $before, "$key must not be year-gated");
        }
    }
```

- [ ] **Step 7: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php --filter PentecostAnchored`

Expected: FAIL — undefined array key `MaryMotherChurch`.

- [ ] **Step 8: Add the placement helpers**

In `src/Models/Calendar/Temporale/AmbrosianTemporale.php`, add after `calculateAfterPentecostAnchors()`:

```php
    /**
     * Place one Easter-anchored celebration `$easterOffset` days after Easter Sunday,
     * unless the Proprium de Tempore entry's `since_year`/`until_year` gate excludes
     * the requested year.
     */
    private function placeEasterOffsetAnchor(string $key, int $easterOffset, TemporaleContext $ctx): void
    {
        if (false === $this->isInForce($key, $ctx)) {
            return;
        }

        $date = Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P' . $easterOffset . 'D'));
        $ctx->propriumDeTempore[$key]->setDate($date);
        $this->createPropriumDeTemporeLiturgicalEventByKey($key, $ctx);
    }

    /**
     * True when the Proprium de Tempore entry for `$key` is in force for the requested
     * year, i.e. when its optional `since_year`/`until_year` limits admit it.
     */
    private function isInForce(string $key, TemporaleContext $ctx): bool
    {
        if (false === $ctx->propriumDeTempore->offsetExists($key)) {
            throw new ServiceUnavailableException("isInForce requires a key from the Proprium de Tempore, instead got $key");
        }

        $event = $ctx->propriumDeTempore[$key];
        $year  = $ctx->params->Year;

        if (null !== $event->since_year && $year < $event->since_year) {
            return false;
        }

        return null === $event->until_year || $year <= $event->until_year;
    }
```

- [ ] **Step 9: Place the five anchors**

At the end of `calculateAfterPentecostAnchors()`, after the `ChristKing` block:

```php
        // Pentecost-anchored celebrations (calendario ambrosiano, praenotanda pp. LXXV, LXXVII).
        // Placed here, before calculateAfterPentecostSundays() and calculateAfterPentecostWeekdays(),
        // so both passes see these days as occupied: numberSundayBlock() skips an occupied Sunday
        // while still incrementing its ordinal (Trinity therefore consumes the I domenica dopo
        // Pentecoste and the later numbering is unaffected), and fillFerialWeekdays() skips
        // occupied weekdays. Offsets are from Easter; Pentecost itself is Easter + 49.
        $this->placeEasterOffsetAnchor('MaryMotherChurch', 50, $ctx); // Lunedì dopo Pentecoste
        $this->placeEasterOffsetAnchor('Trinity', 56, $ctx);          // I domenica dopo Pentecoste
        $this->placeEasterOffsetAnchor('CorpusChristi', 60, $ctx);    // Giovedì successivo
        $this->placeEasterOffsetAnchor('SacredHeart', 68, $ctx);      // Venerdì dopo la II domenica
        $this->placeEasterOffsetAnchor('ImmaculateHeart', 69, $ctx);  // Sabato dopo la II domenica
```

Confirm `Utilities` and `ServiceUnavailableException` are already imported in this file — both are used elsewhere in it; add the `use` statements only if a linter reports them missing.

- [ ] **Step 10: Run tests to verify they pass**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`

Expected: PASS, including the season test from Step 1, which turns green here.

- [ ] **Step 11: Verify nothing shifted**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/`

Expected: PASS. `AmbrosianAnnualTableTest` (Task 2) must still be green — it asserts Easter, Ascension, Pentecost, Lent I, Advent I and the
Dedication, none of which this task may move. `AmbrosianTemporaleCompletenessTest` must also stay green: it asserts exactly one temporale event per
day, and each new celebration replaces the ferial or Sunday that previously occupied its day rather than doubling up.

Run: `composer analyse && vendor/bin/phpcs src/Models/Calendar/Temporale/AmbrosianTemporale.php`

Expected: no errors.

- [ ] **Step 12: Commit**

```bash
git add src/Models/Calendar/Temporale/AmbrosianTemporale.php phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php
git commit -m "feat(ambrosian): place the five Pentecost-anchored celebrations"
```

---

### Task 5: Assert Corpus Domini across all 32 tabulated years

Task 2 deliberately omitted the `corpus_domini` column because the celebration did not exist. Now that it does, close the loop against the published oracle.

**Files:**

- Modify: `phpunit_tests/Models/Calendar/Temporale/AmbrosianAnnualTableTest.php`

**Interfaces:**

- Consumes: the `corpus_domini` column of `phpunit_tests/fixtures/ambrosian_annual_table_2025_2056.json` (already present since Task 2) and the `CorpusChristi` key from Task 4.

- [ ] **Step 1: Write the failing assertion**

Add to `AmbrosianAnnualTableTest`:

```php
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
```

- [ ] **Step 2: Run it**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianAnnualTableTest.php`

Expected: PASS, 96 tests. (Had this been written before Task 4 it would have failed on the missing `CorpusChristi` key.)

- [ ] **Step 3: Assert Corpus Domini at the ASSEMBLED-CALENDAR layer too**

The assertion above runs against the temporale engine only. That layer cannot see precedence resolution — which is exactly how Task 4's Critical
(Corpus Domini being transferred off its Missal Thursday by a lower-graded sanctorale event) survived a fully green temporale suite. A temporale-only
oracle is therefore not enough.

Extend the coverage so the 32 tabulated Corpus Domini dates are also asserted on the ASSEMBLED calendar (temporale + sanctorale + precedence
resolution), proving the date survives resolution rather than merely being computed. Use the existing assembled-calendar harness —
`phpunit_tests/Models/Calendar/Sanctorale/AmbrosianRealYearHarnessTrait.php` (`assembleAmbrosianYear()`) together with
`AmbrosianPrecedenceResolver::resolve()` — as already used by `AmbrosianRealYearPrecedenceTest`. Do not invent a second harness.

Assembling 32 full years is materially slower than running the engine alone, so mark the assembled-calendar sweep `#[Group('slow')]` (the ATTRIBUTE,
never a `@group` docblock — see Task 3). If the full 32-year sweep proves too slow to be useful, cover the tabulated years that actually contain a
collision plus a representative sample, and state in the report exactly which years were covered and which were dropped, so the reduced coverage is
visible rather than silent.

- [ ] **Step 4: Commit**

```bash
git add phpunit_tests/Models/Calendar/Temporale/AmbrosianAnnualTableTest.php phpunit_tests/Models/Calendar/Sanctorale/
git commit -m "test(ambrosian): assert Corpus Domini across all 32 tabulated years"
```

---

### Task 6: `is_bvm` property and the rank-10 split

Added after Task 4's review. Placing the two BVM memorials into the temporale made them collide with sanctorale memorials on five days across the
sampled years:

| Date       | Sanctorale event      | New temporale event | Both at |
|------------|-----------------------|---------------------|---------|
| 2024-05-20 | `StBernardineOfSiena` | `MaryMotherChurch`  | rank 10 |
| 2025-06-09 | `StEphremDeacon`      | `MaryMotherChurch`  | rank 10 |
| 2025-06-28 | `StIrenaeus`          | `ImmaculateHeart`   | rank 10 |
| 2026-05-25 | `StDionysius`         | `MaryMotherChurch`  | rank 10 |
| 2026-06-13 | `StAnthonyOfPadua`    | `ImmaculateHeart`   | rank 10 |

**The outcomes are already correct.** A memorial of the Blessed Virgin Mary ranks above a memorial of a saint, so the BVM celebration winning each of
these days is right. Praenotanda n. 53's "medesimo grado" coexistence rule governs saints among themselves; it does not put a BVM memorial on a level
with a saint's.

The defect is that the rank ladder does not say so. `rankOf()` returns a flat rank 10 for every comune memorial, so the correct winner is currently
decided by `uasort` stability rather than by a stated rule — and the suppression message reads "higher-ranking" when the ranks are numerically equal.
A future data or ordering change could silently flip any of these five days with no test to catch it.

Encode the rule as an explicit `is_bvm` property, mirroring the existing `is_dominical` property. `is_dominical` is already carried by BOTH the
Proprium de Tempore schema and the Ambrosian sanctorale schema, so `is_bvm` must be too — otherwise a BVM celebration coming from the sanctorale
(e.g. `OurLadyOfLourdes`) would not be recognised.

**Files:**

- Modify: `jsondata/schemas/PropriumDeTempore.json` — optional `is_bvm` boolean
- Modify: the Ambrosian sanctorale schema — the same optional `is_bvm` boolean
- Modify: `src/Models/PropriumDeTemporeEvent.php` — parse and expose it (follow the `is_dominical` pattern exactly, as in Task 1)
- Modify: the sanctorale event model and `src/Models/Calendar/LiturgicalEvent.php` — plumb it through so `rankOf()` can read it
- Modify: `jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore/propriumdetempore.json` — `is_bvm: true` on `MaryMotherChurch` and `ImmaculateHeart`
- Modify: the Ambrosian sanctorale data — `is_bvm: true` on its BVM celebrations
- Modify: `src/Models/Calendar/Precedence/AmbrosianLiturgicalDayRank.php` — split rank 10
- Test: `phpunit_tests/Models/Calendar/Precedence/` and `phpunit_tests/Models/Calendar/Sanctorale/`

**Interfaces:**

- Consumes: `LiturgicalEvent`, as read by `AmbrosianLiturgicalDayRank::rankOf()`.
- Produces: `is_bvm` on the event models, and a rank ladder in which a comune BVM memorial outranks a comune saint's memorial.

- [ ] **Step 1: Write the failing tests**

Two levels, both required:

1. A unit test on `AmbrosianLiturgicalDayRank::rankOf()`: a comune BVM memorial must rank strictly above a comune saint's memorial. Follow the
   existing fixture style in `phpunit_tests/Models/Calendar/Precedence/AmbrosianLiturgicalDayRankTest.php`.
2. Assembled-calendar tests pinning all five rows of the table above: the BVM celebration is present on that date and the saint is the one suppressed.
   These are the regression lock for the five real days. Use `AmbrosianRealYearHarnessTrait`.

While here, also add the unit-level fixture test deferred from Task 4's review: a non-Sunday, `is_dominical: true`, `AFTER_PENTECOST`,
`SOLEMNITY`-grade event must return rank 3. That branch is currently exercised only indirectly through `CorpusChristi`'s assembled test, and the
existing "of-the-Lord asymmetry" tests use `ORDINARY_TIME`, so none of them would have caught the Task 4 Critical.

- [ ] **Step 2: Run them to verify they fail**

The rank unit test must fail with both memorials at rank 10. Record the output.

- [ ] **Step 3: Add `is_bvm` to both schemas**

Optional boolean, defaulting to absent/null, exactly like `is_dominical`. Both schemas set `additionalProperties: false`, so the schema change must
land before the data change or validation fails.

- [ ] **Step 4: Add `is_bvm` to the models and plumb it to `LiturgicalEvent`**

Follow the `is_dominical` pattern precisely: nullable readonly property, constructor parameter defaulting to null, `property_exists(...) && is_bool(...)`
guard in `fromObjectInternal()`, and position in the `new static(...)` call. Backward compatibility is mandatory — the Roman rite shares
`PropriumDeTemporeEvent`, and no existing entry carries `is_bvm`.

- [ ] **Step 5: Flag the BVM entries in the data**

`is_bvm: true` on `MaryMotherChurch` and `ImmaculateHeart` in the Ambrosian temporale, and on the BVM celebrations in the Ambrosian sanctorale. Use the
sanctorale's existing `common: ["Blessed Virgin Mary"]` marker to find the latter, but do NOT replace that field — `is_bvm` is an addition, not a
migration.

- [ ] **Step 6: Split rank 10**

In `AmbrosianLiturgicalDayRank::rankOf()`, place a comune BVM memorial above a comune saint's memorial, and renumber the ranks below accordingly.
Update the class docblock's rank table, which enumerates the ladder.

Check every consumer of the rank numbers before renumbering — `SOLEMNITY_TIER_MAX_RANK` and anything else comparing against literal rank values must
stay semantically correct. A silent off-by-one here would mis-rank a whole tier.

- [ ] **Step 7: Fix the suppression message**

Where the resolver reports an event "suppressed by the higher-ranking X", confirm the wording is accurate now that the ranks genuinely differ.

- [ ] **Step 8: Run the tests and the full Ambrosian suites**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Precedence/ phpunit_tests/Models/Calendar/Sanctorale/ phpunit_tests/Models/Calendar/Temporale/`

Expected: PASS. `AmbrosianAnnualTableTest` and `AmbrosianTemporaleCompletenessTest` must be unchanged and green. Any change to the assembled-calendar
event counts must be explained, not accepted.

Run: `composer analyse && vendor/bin/phpcs src/`

- [ ] **Step 9: Commit**

```bash
git add jsondata/ src/ phpunit_tests/
git commit -m "feat(ambrosian): rank BVM memorials above saints' memorials via is_bvm"
```

---

### Task 7: Full-suite verification, golden master, and docs

**Files:**

- Modify: `README.md`
- Possibly regenerate: golden-master fixtures for Ambrosian years

- [ ] **Step 1: Run the full suite**

Run: `composer test:quick`

Expected: PASS apart from the environment-blocked layers noted at the top of this plan (`Routes/*` needs this API running on the configured port;
`Repositories/*` needs migrations applied to the litcal container). Neither is affected by this work — if either shows a *new* failure mode,
investigate before continuing.

Run: `composer analyse && composer lint && composer lint:md`

Expected: no errors.

- [ ] **Step 2: Check the golden master**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/CalendarGoldenMasterTest.php`

If Ambrosian fixtures are covered, this fails with a diff. Inspect it: the only changes may be the five new celebrations per year, plus the ferie and
the `AfterPentecost1` Sunday they displace. If anything else moved, stop and investigate.

Regenerate deliberately once the diff is confirmed correct — note the generator lives in a SEPARATE file from the checker:

Run: `vendor/bin/phpunit --group golden-master-generate phpunit_tests/Handlers/CalendarGoldenMasterGenerateTest.php`

The generator is fenced behind `#[Group('golden-master-generate')]` — an attribute, so unlike the `@group` docblocks noted in Task 3 this fence is
genuinely honoured by PHPUnit 12 — and is excluded from both composer scripts and `phpunit.xml.dist`, so a bare run cannot clobber fixtures. It also
requires `.env.local`.

- [ ] **Step 3: Update the README**

In `README.md`, under the Ambrosian bullet's "notes on the current state of this data" list, remove any note added about these celebrations being
absent, and confirm the remaining caveats (placeholder readings, provisional year cycles/vigils/psalter weeks, 1976 floor) are still accurate.

- [ ] **Step 4: Verify the assembled calendar, not just the engine**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Sanctorale/ phpunit_tests/Models/Calendar/Precedence/`

Expected: PASS. These are the layers where Task 4's Critical actually lived — the temporale suite stayed fully green while Corpus Domini was being
transferred off its Thursday. Treat a green temporale run as necessary but never sufficient.

Then diff the assembled calendar against the pre-change branch for at least 2024, 2025 and 2026, and account for EVERY difference. The expected set is:
the five new celebrations appearing, the ferie and the `AfterPentecost1` Sunday they replace disappearing, and the five saints correctly yielding to the
two BVM memorials (see Task 6). Anything else is a regression to investigate before merge.

- [ ] **Step 5: Verify end-to-end against a running server**

Start the API on the free port and confirm the five celebrations now appear:

```bash
PHP_CLI_SERVER_WORKERS=6 php -S localhost:8001 -t public &
curl -s "http://localhost:8001/calendar/ambrosian/2026" -H "Accept: application/json" -o /tmp/amb2026.json
python3 -c "
import json
ev = json.load(open('/tmp/amb2026.json'))['litcal']
want = {'2026-05-25':'MaryMotherChurch','2026-05-31':'Trinity','2026-06-04':'CorpusChristi','2026-06-12':'SacredHeart','2026-06-13':'ImmaculateHeart'}
for date, key in want.items():
    keys = [e['event_key'] for e in ev if e['date'][:10] == date]
    print(('OK  ' if key in keys else 'FAIL'), date, key, keys)
"
```

Expected: five `OK` lines. Before this work every line reported generic filler (`AfterPentecost1`, `AfterPentecostWeekday2Thursday`, …).

- [ ] **Step 6: Commit and open the PR**

```bash
git add README.md
git commit -m "docs(ambrosian): note the Pentecost-anchored celebrations"
git push -u origin feature/ambrosian-pentecost-anchors
```

Open a PR targeting `development` (never `stable`), summarising the five celebrations, the grade-6 rationale from the precedence table, and the 32-year oracle.

---

## Notes for the reviewer

- **Colour** is `white` for all five. For four of them this matches the Roman temporale entries exactly; the *calendario ambrosiano* does not print
  colours, so it is worth a spot-check against the Mass formularies.
- **Latin names** are borrowed from the Roman temporale i18n, since these are the same celebrations. The Ambrosian Latin corpus is already flagged as
  needing ordo-proofing, so treat these as provisional.
- **Mary Mother of the Church**: `since_year: 2018` is the Roman institution year. If the Ambrosian rite adopted it in a different year, only that one number changes.
