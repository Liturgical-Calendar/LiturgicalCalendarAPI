# Ambrosian Temporale Completion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps
> use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend `AmbrosianTemporale` so `buildTemporale()` produces a *complete* Ambrosian temporal year — every day from Advent I to the Saturday before the next Advent I is
covered by a liturgical event — by adding the after-Epiphany and after-Pentecost Sunday numbering (3 sub-blocks), all-season ferial weekday fill (including Lenten aliturgical
Fridays), and `liturgical_season` stamping on every engine-created event.

**Architecture:** The engine synthesizes the numbered Sundays and every ferial weekday **in code** (exactly how `RomanTemporale`/`CalendarHandler` synthesize Roman Ordinary-Time
weekdays), stamping `liturgical_season` via `LitSeason::forEventKey()`. The existing anchor block (Advent/Christmas/Lent/Easter Sundays + solemnities) remains data-driven and
unchanged. A single `is_aliturgical` boolean is added to the event models + source schema, mirroring the existing `is_dominical` wiring, and is set on Lenten Friday ferie.

**Tech Stack:** PHP 8.4, PHPUnit 11, PHPStan level 10, PHP_CodeSniffer (PSR-12 + custom ruleset). No new runtime dependencies.

## Global Constraints

- **Endpoint stays 501.** Nothing in this plan wires `AmbrosianTemporale` into `CalendarHandler`; `/calendar/ambrosian` continues to return `ImplementationException` (501). The
  engine is exercised **only** through the existing unit-test harness (`AmbrosianTemporaleHarnessTrait::runEngine()`), by invoking `buildTemporale()` directly. Do **not** touch
  `CalendarHandler`.
- **Roman output byte-identical.** `phpunit_tests/Handlers/CalendarGoldenMasterTest.php` (9 tests / 36 assertions) is the gate and must stay green. Every change is strictly
  additive. `is_aliturgical` serializes **only when non-null** (mirror `is_dominical`), so no Roman event's JSON changes.
- **Seasons are stamped by the engine, not by the collection.** `LiturgicalEventCollection::setSeasonsAndHolyDaysOfObligation()` is Roman-only (it requires an `AshWednesday` event
  and knows only the 6 Roman seasons) and must **not** be called for Ambrosian. The engine stamps `liturgical_season` on every event it creates via
  `LitSeason::forEventKey($eventKey)`.
- **Synthesized keys must classify under `forEventKey`.** Every synthesized event key MUST begin with one of these stems so `LitSeason::forEventKey()` returns the correct season:
  `AdventWeekday`, `Christmas` (→`ChristmasWeekday`), `LentWeekday`, `EasterWeekday`, `AfterEpiphany` (Sundays `AfterEpiphany2..N`, ferie `AfterEpiphanyWeekday…`), `AfterPentecost`
  (Sundays/ferie `AfterPentecost…`, `AfterPentecostMartyrdom…`, `AfterPentecostDedication…` — all begin with `AfterPentecost`).
- **LitGrade ladder is unchanged** (`HIGHER_SOLEMNITY`=7 … `WEEKDAY`=0). Grades/colours for synthesized events:
- After-***Sundays**: `LitGrade::FEAST_LORD`, `LitColor::GREEN`, `is_dominical = true`. (The precedence resolver's rank-3 clause excludes AFTER_* seasons, so these FEAST_LORD
    dominical Sundays correctly land at Tabella rank 4 — see `AmbrosianLiturgicalDayRank`.)
- **Ferial weekdays**: `LitGrade::WEEKDAY`, `is_dominical` left null. Colours per season: Advent → `LitColor::MORELLO`, Christmas → `LitColor::WHITE`, Lent → `LitColor::MORELLO`,
    Easter → `LitColor::WHITE`, after-Epiphany/after-Pentecost → `LitColor::GREEN`.
- **Edition scope: 2024 (post-2008) only.** The pre-2008 (1976) single-block post-Pentecost structure and the year-2008 branch are Plan 9 (1976 backfill). This plan implements the
  post-2008 rules for all years the harness exercises (2024–2026).
- **Names are norm-derived and synthesized in code; exact ordo names/numbering are validated in Plan 9.** Do not hand-author a speculative Sunday data file. Build display names
  from a per-season template + localized weekday + ordinal, for the two Ambrosian locales present (`it`, `la`).
- **All work happens in the worktree** `…/scratchpad/wt-ambrosian-p6` (branch `feature/ambrosian-temporale-fill`). Verify `git rev-parse --show-toplevel` ends in `wt-ambrosian-p6`
  before editing. Signed commits (unlock GPG if a commit times out).
- **Quality gates per task:** `composer test:quick` (or the named test), `composer analyse` (PHPStan L10, scans `src` only), `composer lint` (phpcs). PHPStan-ignore uses the modern
  `@phpstan-ignore <identifier>` form.

---

## File Structure

- `src/Models/Calendar/LiturgicalEvent.php` — add `public ?bool $is_aliturgical = null;` + serialization + hydration (mirror `is_dominical`).
- `src/Models/PropriumDeTemporeEvent.php` — add readonly `?bool $is_aliturgical` (ctor/`fromArray`/`fromObject`), mirror `is_dominical`.
- `jsondata/schemas/PropriumDeTempore.json` — add optional `is_aliturgical` boolean property.
- `src/Enum/LitSeason.php` — add 4 anchor-key patterns so the existing Ambrosian anchor keys classify correctly.
- `src/Models/Calendar/Temporale/AmbrosianTemporale.php` — the core work: a `stampSeason()` hook on every created event; `calculateAfterEpiphanySundays()`;
  `calculateAfterPentecostSundays()` (3 sub-blocks); a generic `fillFerialWeekdays()` helper + `buildFerialName()`; six per-season fill methods; all wired into `buildTemporale()`.
- `phpunit_tests/Enum/LitSeasonAmbrosianTest.php` — extend for the 4 new anchor-key patterns.
- `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php` — extend with Sundays, sub-blocks, weekday fill, seasons, aliturgical Fridays.
- `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleHarnessTrait.php` — add a `runEngineEvents()` helper returning full `LiturgicalEvent` objects (not just dates) so tests
  can assert season/grade/is_aliturgical.
- `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleCompletenessTest.php` — **new**; full-year gap-free coverage acceptance test (`@group slow`).
- `phpunit_tests/Models/Calendar/LiturgicalEventIsAliturgicalTest.php` — **new**; model round-trip for `is_aliturgical`.

**Naming conventions (fixed for this plan):**

| Block                     | Sunday keys                   | Ferial keys                                      | Season          | Colour  |
| ------------------------- | ----------------------------- | ------------------------------------------------ | --------------- | ------- |
| Advent (existing anchors) | `Advent1..6`                  | `AdventWeekday{DDD}` (see Task 6)                | ADVENT          | morello |
| Christmas                 | anchors only                  | `ChristmasWeekday{DDD}`                          | CHRISTMAS       | white   |
| After-Epiphany            | `AfterEpiphany2..N`           | `AfterEpiphanyWeekday{N}{EnglishDay}`            | AFTER_EPIPHANY  | green   |
| Lent (existing anchors)   | `Lent1..5`                    | `LentWeekday{N}{EnglishDay}`                     | LENT            | morello |
| Easter (existing anchors) | `Easter2..7`                  | `EasterWeekday{N}{EnglishDay}`                   | EASTER          | white   |
| After-Pentecost (a)       | `AfterPentecost{N}`           | `AfterPentecostWeekday{N}{EnglishDay}`           | AFTER_PENTECOST | green   |
| After-Pentecost (b)       | `AfterPentecostMartyrdom{N}`  | `AfterPentecostMartyrdomWeekday{N}{EnglishDay}`  | AFTER_PENTECOST | green   |
| After-Pentecost (c)       | `AfterPentecostDedication{N}` | `AfterPentecostDedicationWeekday{N}{EnglishDay}` | AFTER_PENTECOST | green   |

`{DDD}` = zero-padded day-of-month; `{N}` = week number within the block/season; `{EnglishDay}` = English weekday name (`Monday`…`Saturday`) — matching the Roman
`OrdWeekday{N}{EnglishDay}` key convention so keys are locale-independent.

---

### Task 1: Add the `is_aliturgical` flag to the event models and source schema

**Files:**

- Modify: `src/Models/Calendar/LiturgicalEvent.php` (property near line 51; serialization near line 312; hydration near line 566)
- Modify: `src/Models/PropriumDeTemporeEvent.php`
- Modify: `jsondata/schemas/PropriumDeTempore.json` (property block near line 25)
- Test: `phpunit_tests/Models/Calendar/LiturgicalEventIsAliturgicalTest.php` (create)

**Interfaces:**

- Consumes: nothing new.
- Produces: `LiturgicalEvent::$is_aliturgical` (`public ?bool`, serialized only when non-null); `PropriumDeTemporeEvent::$is_aliturgical` (`public readonly ?bool`). Task 7 sets
  `$event->is_aliturgical = true` on Lenten Friday ferie.

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/Models/Calendar/LiturgicalEventIsAliturgicalTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitEventType;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use PHPUnit\Framework\TestCase;

final class LiturgicalEventIsAliturgicalTest extends TestCase
{
    public function testIsAliturgicalDefaultsNullAndIsOmittedFromSerialization(): void
    {
        $event = new LiturgicalEvent('Test', DateTime::fromFormat('14-3-2025'), LitColor::MORELLO, LitEventType::MOBILE, LitGrade::WEEKDAY);
        self::assertNull($event->is_aliturgical);
        self::assertArrayNotHasKey('is_aliturgical', $event->jsonSerialize());
    }

    public function testIsAliturgicalSerializesWhenSet(): void
    {
        $event                 = new LiturgicalEvent('Test', DateTime::fromFormat('14-3-2025'), LitColor::MORELLO, LitEventType::MOBILE, LitGrade::WEEKDAY);
        $event->is_aliturgical = true;
        self::assertTrue($event->jsonSerialize()['is_aliturgical']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/LiturgicalEventIsAliturgicalTest.php`
Expected: FAIL — "Undefined property ... $is_aliturgical" (or the serialization assertion fails).

- [ ] **Step 3: Add the property to `LiturgicalEvent`**

In `src/Models/Calendar/LiturgicalEvent.php`, directly after the `public ?bool $is_dominical = null;` line (≈ line 51):

```php
    public ?bool $is_aliturgical         = null;
```

- [ ] **Step 4: Serialize it (only when non-null)**

In the same file, directly after the `is_dominical` serialization block (≈ lines 312–314):

```php
        if ($this->is_aliturgical !== null) {
            $returnArr['is_aliturgical'] = $this->is_aliturgical;
        }
```

Also add `is_aliturgical?: ?bool,` to the serialization return-shape docblock next to the existing `is_dominical?: ?bool,` entry (≈ line 250), so PHPStan L10 sees the key.

- [ ] **Step 5: Hydrate it from source objects**

In `fromObject()`, directly after the `is_dominical` carry-over block (≈ lines 561–567):

```php
        // Carry over `is_aliturgical` from source data (e.g. PropriumDeTemporeEvent) when present and non-null.
        if (property_exists($obj, 'is_aliturgical') && null !== $obj->is_aliturgical) {
            if (false === is_bool($obj->is_aliturgical)) {
                throw new \Exception('Invalid object provided to create LiturgicalEvent: is_aliturgical is not a boolean or null');
            }
            $litEvent->is_aliturgical = $obj->is_aliturgical;
        }
```

- [ ] **Step 6: Add `is_aliturgical` to `PropriumDeTemporeEvent`**

In `src/Models/PropriumDeTemporeEvent.php`, mirror every `is_dominical` site:

- Property (after the `$is_dominical` property declaration):

```php
    /**
     * Whether the event is aliturgical (no Mass celebrated), if applicable to the source data.
     * Absent/null for source data that does not classify aliturgical days (e.g. the Roman proprium).
     */
    public readonly ?bool $is_aliturgical;
```

- Constructor: add `?bool $is_aliturgical = null` as the final parameter and `$this->is_aliturgical = $is_aliturgical;` in the body.
- `fromArrayInternal()`: add `$data['is_aliturgical'] ?? null` as the final `new static(...)` argument, and add `is_aliturgical?:bool|null` to its `@param` array shape.
- `fromObjectInternal()`: mirror the `is_dominical` guard:

```php
        $is_aliturgical = null;
        if (property_exists($data, 'is_aliturgical') && is_bool($data->is_aliturgical)) {
            $is_aliturgical = $data->is_aliturgical;
        }
```

  and pass `$is_aliturgical` as the final `new static(...)` argument; add `is_aliturgical?:bool|null` to the `@param` shape.

- [ ] **Step 7: Add the schema property**

In `jsondata/schemas/PropriumDeTempore.json`, inside the same `properties` object as `is_dominical` (≈ line 25), add:

```json
                "is_aliturgical": {
                    "type": "boolean",
                    "description": "Whether the event is aliturgical (no Mass is celebrated). Used by the Ambrosian proprium for the aliturgical Fridays of Lent."
                },
```

(`is_aliturgical` stays optional — do not add it to any `required` array. `additionalProperties` is `false`, so this new key must be declared to be accepted.)

- [ ] **Step 8: Run tests + static analysis**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/LiturgicalEventIsAliturgicalTest.php` → PASS.
Run: `composer analyse` → no new errors.
Run: `composer lint` → clean.

- [ ] **Step 9: Commit**

```bash
git add src/Models/Calendar/LiturgicalEvent.php src/Models/PropriumDeTemporeEvent.php jsondata/schemas/PropriumDeTempore.json phpunit_tests/Models/Calendar/LiturgicalEventIsAliturgicalTest.php
git commit -m "feat(ambrosian): add is_aliturgical flag to event models and PropriumDeTempore schema"
```

---

### Task 2: Classify the Ambrosian anchor keys under `LitSeason::forEventKey()` and stamp seasons in the engine

**Files:**

- Modify: `src/Enum/LitSeason.php` (`CHRISTMAS_PATTERNS`, `LENT_PATTERNS`, `AFTER_PENTECOST_PATTERNS`)
- Modify: `src/Models/Calendar/Temporale/AmbrosianTemporale.php` (add `stampSeason()`, call it in `createPropriumDeTemporeLiturgicalEventByKey()`)
- Test: `phpunit_tests/Enum/LitSeasonAmbrosianTest.php`, `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`

**Interfaces:**

- Consumes: `LitSeason::forEventKey(string): LitSeason` (existing).
- Produces: `AmbrosianTemporale::stampSeason(LiturgicalEvent $event): void` (private) — sets `$event->liturgical_season = LitSeason::forEventKey($event->event_key)`. Every event
  the engine creates (now and in Tasks 3–9) is stamped through the single creation path.

**Background — the 4 anchor keys that currently misclassify:** `forEventKey()` sends any unmatched key to `ORDINARY_TIME`. Of the 38 existing Ambrosian anchor keys, four have no
pattern and wrongly resolve to `ORDINARY_TIME`: `Circoncisione` (Jan 1 octave day → CHRISTMAS), `AshesMonday` (→ LENT), `SabatoTradSymb` (Saturday before Palm Sunday → LENT),
`ChristKing` (last Sunday after the Dedication → AFTER_PENTECOST). All others already classify correctly (`Advent\d`, `Christmas`, `Epiphany`, `BaptismLord`, `Lent\d`, `PalmSun`,
`HolyThurs`/`GoodFri`/`EasterVigil`, `Easter\d*`, `*OctaveEaster`, `Ascension`, `Pentecost`, `DedicationDuomo`).

- [ ] **Step 1: Write the failing test (patterns)**

Add to `phpunit_tests/Enum/LitSeasonAmbrosianTest.php`:

```php
    public function testAmbrosianAnchorKeysClassifyCorrectly(): void
    {
        self::assertSame(LitSeason::CHRISTMAS, LitSeason::forEventKey('Circoncisione'));
        self::assertSame(LitSeason::LENT, LitSeason::forEventKey('AshesMonday'));
        self::assertSame(LitSeason::LENT, LitSeason::forEventKey('SabatoTradSymb'));
        self::assertSame(LitSeason::AFTER_PENTECOST, LitSeason::forEventKey('ChristKing'));
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Enum/LitSeasonAmbrosianTest.php --filter testAmbrosianAnchorKeysClassifyCorrectly`
Expected: FAIL — each returns `ORDINARY_TIME`.

- [ ] **Step 3: Add the patterns**

In `src/Enum/LitSeason.php`:

- To `CHRISTMAS_PATTERNS` add `'/^Circoncisione$/',`
- To `LENT_PATTERNS` add `'/^AshesMonday$/',` and `'/^SabatoTradSymb$/',`
- To `AFTER_PENTECOST_PATTERNS` add `'/^ChristKing$/',`

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Enum/LitSeasonAmbrosianTest.php` → PASS.

- [ ] **Step 5: Write the failing test (engine stamping)**

Add to `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php` a test that uses a new `runEngineEvents()` harness helper (added in Step 6) to assert seasons on the
anchor block:

```php
    public function testAnchorBlockSeasonsStamped2025(): void
    {
        $events = $this->runEngineEvents(2025);
        self::assertSame(LitSeason::ADVENT, $events['Advent1']->liturgical_season);
        self::assertSame(LitSeason::CHRISTMAS, $events['Christmas']->liturgical_season);
        self::assertSame(LitSeason::CHRISTMAS, $events['Circoncisione']->liturgical_season);
        self::assertSame(LitSeason::CHRISTMAS, $events['Epiphany']->liturgical_season);
        self::assertSame(LitSeason::CHRISTMAS, $events['BaptismLord']->liturgical_season);
        self::assertSame(LitSeason::LENT, $events['Lent1']->liturgical_season);
        self::assertSame(LitSeason::LENT, $events['AshesMonday']->liturgical_season);
        self::assertSame(LitSeason::LENT, $events['SabatoTradSymb']->liturgical_season);
        self::assertSame(LitSeason::EASTER_TRIDUUM, $events['HolyThurs']->liturgical_season);
        self::assertSame(LitSeason::EASTER, $events['Easter']->liturgical_season);
        self::assertSame(LitSeason::EASTER, $events['Pentecost']->liturgical_season);
        self::assertSame(LitSeason::AFTER_PENTECOST, $events['DedicationDuomo']->liturgical_season);
        self::assertSame(LitSeason::AFTER_PENTECOST, $events['ChristKing']->liturgical_season);
    }
```

Add `use LiturgicalCalendar\Api\Enum\LitSeason;` to the test's imports.

- [ ] **Step 6: Add `runEngineEvents()` to the harness trait**

In `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleHarnessTrait.php`, add alongside `runEngine()`:

```php
    /** @return array<string,\LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent> map of event_key => event after buildTemporale */
    private function runEngineEvents(int $year): array
    {
        $messages = [];
        $ctx      = $this->buildContext($year, $messages);
        ( new AmbrosianTemporale() )->buildTemporale($ctx);

        $events = [];
        foreach ($ctx->cal->getLiturgicalEvents()->getKeys() as $key) {
            $event = $ctx->cal->getLiturgicalEvent($key);
            self::assertNotNull($event, "Expected a LiturgicalEvent for key $key");
            $events[$key] = $event;
        }
        return $events;
    }
```

- [ ] **Step 7: Add `stampSeason()` and call it on every created event**

In `src/Models/Calendar/Temporale/AmbrosianTemporale.php`, add the helper and call it from `createPropriumDeTemporeLiturgicalEventByKey()` (before `return $event;`):

```php
    /**
     * Stamp the Ambrosian liturgical season onto an event from its key. Called on
     * every event the engine creates, because the Roman
     * `LiturgicalEventCollection::setSeasonsAndHolyDaysOfObligation()` cannot run
     * for the Ambrosian rite (it requires an AshWednesday event and knows only the
     * six Roman seasons).
     */
    private function stampSeason(LiturgicalEvent $event): void
    {
        $event->liturgical_season = \LiturgicalCalendar\Api\Enum\LitSeason::forEventKey($event->event_key);
    }
```

In `createPropriumDeTemporeLiturgicalEventByKey()`, change the tail to:

```php
        $event = LiturgicalEvent::fromObject($ctx->propriumDeTempore[$key]);
        $ctx->cal->addLiturgicalEvent($key, $event);
        $this->stampSeason($event);
        return $event;
```

Add `use LiturgicalCalendar\Api\Enum\LitSeason;` to the file's imports and reference it unqualified in `stampSeason()`.

- [ ] **Step 8: Run tests + static analysis + lint**

Run: `vendor/bin/phpunit phpunit_tests/Enum/LitSeasonAmbrosianTest.php phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php` → PASS.
Run: `composer analyse` → clean. `composer lint` → clean.
Run: `vendor/bin/phpunit phpunit_tests/Handlers/CalendarGoldenMasterTest.php` → still green (Roman unaffected: the new patterns only match Ambrosian-only keys;
`Circoncisione`/`AshesMonday`/`SabatoTradSymb` do not exist in the Roman proprium, and `ChristKing` in the Roman path is dated but its season is set by the Roman date-range method,
not `forEventKey`).

- [ ] **Step 9: Commit**

```bash
git add src/Enum/LitSeason.php src/Models/Calendar/Temporale/AmbrosianTemporale.php phpunit_tests/Enum/LitSeasonAmbrosianTest.php phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleHarnessTrait.php
git commit -m "feat(ambrosian): classify anchor keys by season and stamp liturgical_season in the temporale engine"
```

---

### Task 3: After-Epiphany Sundays

**Files:**

- Modify: `src/Models/Calendar/Temporale/AmbrosianTemporale.php`
- Test: `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`

**Interfaces:**

- Consumes: `TemporaleContext` (`$ctx->params->Year`, `$ctx->cal`), `Utilities::calcGregEaster()`, `AmbrosianTemporale::synthesizeSunday()` (added here, reused by Task 4).
- Produces: `AmbrosianTemporale::calculateAfterEpiphanySundays(TemporaleContext $ctx): void`; `synthesizeSunday(TemporaleContext $ctx, string $key, DateTime $date, string $name):
  LiturgicalEvent` (private; FEAST_LORD, GREEN, MOBILE, `is_dominical=true`, season-stamped). Numbered Sundays `AfterEpiphany2..N`.

**Norm (n.40):** "Il tempo dopo l'Epifania comincia il lunedì che segue la domenica … del battesimo del Signore, e si protrae fino … del sabato che precede la domenica all'inizio
della quaresima." So the after-Epiphany Sundays are those **strictly after** `BaptismLord` (the Sunday after Jan 6) and **strictly before** `Lent1` (`Easter − 42d`). Numbering
starts at **2** (Baptism is the 1st Sunday of the block, keyed `BaptismLord`), mirroring Roman `OrdSunday2..`.

- [ ] **Step 1: Write the failing test**

Add to `AmbrosianTemporaleTest.php` (Baptism 2025 = 2025-01-12; Lent1 2025 = 2025-03-09; so Sundays Jan 19, 26, Feb 2, 9, 16, 23, Mar 2 → `AfterEpiphany2..8`):

```php
    public function testAfterEpiphanySundays2025(): void
    {
        $d = $this->runEngine(2025);
        self::assertSame('2025-01-19', $d['AfterEpiphany2']);
        self::assertSame('2025-01-26', $d['AfterEpiphany3']);
        self::assertSame('2025-02-02', $d['AfterEpiphany4']);
        self::assertSame('2025-02-09', $d['AfterEpiphany5']);
        self::assertSame('2025-02-16', $d['AfterEpiphany6']);
        self::assertSame('2025-02-23', $d['AfterEpiphany7']);
        self::assertSame('2025-03-02', $d['AfterEpiphany8']);
        self::assertArrayNotHasKey('AfterEpiphany9', $d); // Mar 9 is Lent1
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php --filter testAfterEpiphanySundays2025`
Expected: FAIL — `AfterEpiphany2` key absent.

- [ ] **Step 3: Add `synthesizeSunday()` and `calculateAfterEpiphanySundays()`**

Add to `AmbrosianTemporale.php`:

```php
    /**
     * Create a synthesized numbered Sunday (not drawn from the Proprium de Tempore
     * data file): a dominical FEAST_LORD in green, season-stamped from its key.
     * Used for the after-Epiphany and after-Pentecost Sunday blocks whose exact
     * names/numbering are validated against a published ordo in a later plan.
     */
    private function synthesizeSunday(TemporaleContext $ctx, string $key, DateTime $date, string $name): LiturgicalEvent
    {
        $event               = new LiturgicalEvent($name, $date, LitColor::GREEN, LitEventType::MOBILE, LitGrade::FEAST_LORD);
        $event->is_dominical = true;
        $ctx->cal->addLiturgicalEvent($key, $event);
        $this->stampSeason($event);
        return $event;
    }

    /**
     * After-Epiphany Sundays (n. 40): every Sunday strictly after BaptismLord
     * (the Sunday after Jan 6) and strictly before Lent I (Easter − 42d).
     * Numbered from 2 — BaptismLord is the block's 1st Sunday.
     */
    private function calculateAfterEpiphanySundays(TemporaleContext $ctx): void
    {
        $year    = $ctx->params->Year;
        $baptism = DateTime::fromFormat('6-1-' . $year)->modify('next Sunday');
        $lent1   = Utilities::calcGregEaster($year)->sub(new \DateInterval('P' . ( 6 * 7 ) . 'D'));

        $ordinal = 2;
        $sunday  = ( clone $baptism )->modify('next Sunday');
        while ($sunday < $lent1) {
            $key = 'AfterEpiphany' . $ordinal;
            $this->synthesizeSunday($ctx, $key, clone $sunday, $this->afterEpiphanySundayName($ordinal, $ctx));
            $ordinal++;
            $sunday = ( clone $sunday )->modify('next Sunday');
        }
    }
```

Add the required `use` imports at the top of the file if not present: `LitColor`, `LitEventType`, `LitGrade` (`LiturgicalCalendar\Api\Enum\LitColor`, `…\LitEventType`,
`…\LitGrade`).

- [ ] **Step 4: Add the Sunday name builder**

Add a name builder (locale-aware; `it` and `la` are the only Ambrosian locales). Latin uses `LatinUtils::LATIN_ORDINAL`:

```php
    /**
     * Localized display name for an after-Epiphany Sunday, e.g. (it) "II domenica
     * dopo l'Epifania", (la) "Dominica II post Epiphaniam". Exact ordo wording is
     * validated in a later plan.
     */
    private function afterEpiphanySundayName(int $ordinal, TemporaleContext $ctx): string
    {
        if (LitLocale::LATIN_PRIMARY_LANGUAGE === LitLocale::$PRIMARY_LANGUAGE) {
            return sprintf('Dominica %s post Epiphaniam', LatinUtils::LATIN_ORDINAL[$ordinal]);
        }
        $ordinalStr = Utilities::getOrdinal($ordinal, $ctx->localeDateFormatter->getLocale(), $this->ordinalFormatter($ctx), LatinUtils::LATIN_ORDINAL);
        return sprintf("%s domenica dopo l'Epifania", $ordinalStr);
    }

    /**
     * Feminine \NumberFormatter for ordinal rendering, cached per engine call.
     */
    private ?\NumberFormatter $ordinalFormatterCache = null;

    private function ordinalFormatter(TemporaleContext $ctx): \NumberFormatter
    {
        if (null === $this->ordinalFormatterCache) {
            $this->ordinalFormatterCache = new \NumberFormatter($ctx->localeDateFormatter->getLocale(), \NumberFormatter::SPELLOUT);
            $this->ordinalFormatterCache->setTextAttribute(\NumberFormatter::DEFAULT_RULESET, '%spellout-ordinal-feminine');
        }
        return $this->ordinalFormatterCache;
    }
```

Add `use LiturgicalCalendar\Api\Enum\LitLocale;` and `use LiturgicalCalendar\Api\LatinUtils;` to the imports.

> **Note for the implementer:** the display text is intentionally provisional (Plan 9 pins ordo wording). If the `%spellout-ordinal-feminine` ruleset is unavailable for a locale,
> fall back to `LatinUtils::LATIN_ORDINAL[$ordinal]` — but for `it` it is available. Keep the name-builder logic identical in shape to the Roman weekday-name construction in
> `CalendarHandler::calculateWeekdaysOrdinaryTime()`.

- [ ] **Step 5: Wire into `buildTemporale()`**

In `buildTemporale()`, add `$this->calculateAfterEpiphanySundays($ctx);` **after** `calculateChristmasEpiphany` and `calculateLent` have run (both provide the boundary anchors it
reads) — place it after `calculateEasterCycle($ctx);` for simplicity, or immediately after `calculateLent($ctx);`. Ordering does not matter for dates (boundaries are recomputed),
but keep it before the weekday fill added in later tasks.

- [ ] **Step 6: Run to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php --filter testAfterEpiphanySundays2025` → PASS.
Run: `composer analyse`, `composer lint` → clean.

- [ ] **Step 7: Commit**

```bash
git add src/Models/Calendar/Temporale/AmbrosianTemporale.php phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php
git commit -m "feat(ambrosian): synthesize after-Epiphany Sundays in the temporale engine"
```

---

### Task 4: After-Pentecost Sundays (3 sub-blocks)

**Files:**

- Modify: `src/Models/Calendar/Temporale/AmbrosianTemporale.php`
- Test: `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`, `AmbrosianTemporaleOrdoValidationTest.php`

**Interfaces:**

- Consumes: `synthesizeSunday()` (Task 3), `Utilities::calcGregEaster()`, existing `adventOne()`, existing `DedicationDuomo`/`ChristKing` anchors.
- Produces: `AmbrosianTemporale::calculateAfterPentecostSundays(TemporaleContext $ctx): void`; `martyrdomAnchor(int $year): DateTime` (private).

**Norm (n.42):** The time after Pentecost runs from the Monday after Pentecost to the Saturday before Advent I, in **three sub-blocks**:

- **(a) dopo Pentecoste** — Sundays from the 1st Sunday after Pentecost up to the Saturday before the **1st Sunday after the Martyrdom of St John the Baptist (Aug 29; postponed to
  Sep 1 if Aug 29 is a Sunday)**.
- **(b) dopo il Martirio** — Sundays from that 1st Sunday after the Martyrdom up to the Saturday before the **3rd Sunday of October** (the Dedication of the Duomo, already an
  anchor).
- **(c) dopo la Dedicazione** — Sundays from the Dedication Sunday up to the Saturday before Advent I. The last is **Christ the King** (already an anchor); do not re-emit it.

Numbering **restarts at 1 in each sub-block**. `DedicationDuomo` and `ChristKing` are dominical anchors already placed by `calculateAfterPentecostAnchors()` — the sub-block Sunday
loops must **skip Sundays already occupied** (`$ctx->cal->inCalendar($sunday)`), which naturally excludes the Dedication Sunday from block (c)'s numbering and Christ the King as
the terminal Sunday.

- [ ] **Step 1: Write the failing test**

Add to `AmbrosianTemporaleTest.php`. Anchors for 2025: Pentecost = 2025-06-08; Aug 29 2025 = Friday → Martyrdom Aug 29; 1st Sunday after Martyrdom = 2025-08-31; Dedication (3rd Sun
Oct) = 2025-10-19; Advent I = 2025-11-16 → Christ the King = 2025-11-09.

```php
    public function testAfterPentecostSubBlocks2025(): void
    {
        $d = $this->runEngine(2025);

        // (a) dopo Pentecoste: 1st Sunday after Pentecost (Jun 15) .. Sat before Aug 31
        self::assertSame('2025-06-15', $d['AfterPentecost1']);
        self::assertSame('2025-08-24', $d['AfterPentecost11']); // last before the Martyrdom Sunday
        self::assertArrayNotHasKey('AfterPentecost12', $d);

        // (b) dopo il Martirio: Aug 31 .. Sat before Oct 19 (Dedication)
        self::assertSame('2025-08-31', $d['AfterPentecostMartyrdom1']);
        self::assertSame('2025-10-12', $d['AfterPentecostMartyrdom7']);
        self::assertArrayNotHasKey('AfterPentecostMartyrdom8', $d);

        // (c) dopo la Dedicazione: 1st Sunday after Dedication (Oct 26) .. Sat before Advent I;
        // Christ the King (Nov 9) is the terminal anchor, not re-emitted as a numbered Sunday.
        self::assertSame('2025-10-26', $d['AfterPentecostDedication1']);
        self::assertSame('2025-11-02', $d['AfterPentecostDedication2']);
        self::assertArrayNotHasKey('AfterPentecostDedication3', $d); // Nov 9 = ChristKing
        self::assertSame('2025-11-09', $d['ChristKing']);
    }
```

> **Implementer note:** verify the exact week counts for 2025 by running the engine once and reading back the keys; the assertions above are computed from the anchors (Pentecost
> 2025-06-08, Dedication 2025-10-19, ChristKing 2025-11-09) but confirm `AfterPentecost11`/`AfterPentecostMartyrdom7` are the true last-in-block before adjusting. If a boundary
> count differs by one, fix the assertion to the engine's computed value **only after** confirming the date arithmetic against the norm (the loop condition, not the expected count,
> is authoritative).

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php --filter testAfterPentecostSubBlocks2025`
Expected: FAIL — keys absent.

- [ ] **Step 3: Implement the Martyrdom anchor + the three-block loop**

```php
    /**
     * Martyrdom of St John the Baptist (n. 42a): Aug 29, postponed to Sep 1 when
     * Aug 29 falls on a Sunday.
     */
    private function martyrdomAnchor(int $year): DateTime
    {
        $aug29 = DateTime::fromFormat('29-8-' . $year);
        return self::dateIsSunday($aug29) ? DateTime::fromFormat('1-9-' . $year) : $aug29;
    }

    /**
     * After-Pentecost Sundays (n. 42), in three sub-blocks with per-block numbering:
     *   (a) dopo Pentecoste     — 1st Sunday after Pentecost … Sat before the 1st Sunday after the Martyrdom
     *   (b) dopo il Martirio    — that Sunday … Sat before the Dedication (3rd Sunday of October)
     *   (c) dopo la Dedicazione — 1st Sunday after the Dedication … Sat before Advent I (ends at Christ the King)
     * DedicationDuomo and ChristKing are anchors already placed; Sundays already in
     * the calendar are skipped, so those two are not re-emitted as numbered Sundays.
     */
    private function calculateAfterPentecostSundays(TemporaleContext $ctx): void
    {
        $year          = $ctx->params->Year;
        $pentecost     = Utilities::calcGregEaster($year)->add(new \DateInterval('P49D'));
        $martyrdomSun  = ( clone $this->martyrdomAnchor($year) )->modify('next Sunday'); // 1st Sunday after the Martyrdom
        $dedication    = $ctx->cal->getLiturgicalEvent('DedicationDuomo')?->date
            ?? throw new ServiceUnavailableException('DedicationDuomo anchor must be placed before after-Pentecost Sundays');
        $advent1       = $this->adventOne($year);

        // (a) dopo Pentecoste
        $this->numberSundayBlock($ctx, 'AfterPentecost', ( clone $pentecost )->modify('next Sunday'), $martyrdomSun, 'afterPentecostSundayName');
        // (b) dopo il Martirio
        $this->numberSundayBlock($ctx, 'AfterPentecostMartyrdom', clone $martyrdomSun, $dedication, 'afterMartyrdomSundayName');
        // (c) dopo la Dedicazione
        $this->numberSundayBlock($ctx, 'AfterPentecostDedication', ( clone $dedication )->modify('next Sunday'), $advent1, 'afterDedicationSundayName');
    }

    /**
     * Emit consecutive numbered Sundays [$firstSunday, $endExclusive) under $keyStem,
     * numbering from 1, skipping Sundays already occupied by an anchor. $nameMethod
     * is the name of the per-block localized name builder (see below).
     */
    private function numberSundayBlock(TemporaleContext $ctx, string $keyStem, DateTime $firstSunday, DateTime $endExclusive, string $nameMethod): void
    {
        $ordinal = 1;
        $sunday  = clone $firstSunday;
        while ($sunday < $endExclusive) {
            if (false === $ctx->cal->inCalendar($sunday)) {
                $this->synthesizeSunday($ctx, $keyStem . $ordinal, clone $sunday, $this->{$nameMethod}($ordinal, $ctx));
            }
            $ordinal++;
            $sunday = ( clone $sunday )->modify('next Sunday');
        }
    }
```

Add name builders mirroring `afterEpiphanySundayName()` (it/la), for the three phrases — "dopo Pentecoste" / "dopo il Martirio" / "dopo la Dedicazione" (Latin: "post Pentecosten" /
"post Martyrium" / "post Dedicationem"):

```php
    private function afterPentecostSundayName(int $ordinal, TemporaleContext $ctx): string
    {
        return $this->afterPentecostFamilyName($ordinal, $ctx, 'dopo Pentecoste', 'post Pentecosten');
    }

    private function afterMartyrdomSundayName(int $ordinal, TemporaleContext $ctx): string
    {
        return $this->afterPentecostFamilyName($ordinal, $ctx, 'dopo il Martirio', 'post Martyrium');
    }

    private function afterDedicationSundayName(int $ordinal, TemporaleContext $ctx): string
    {
        return $this->afterPentecostFamilyName($ordinal, $ctx, 'dopo la Dedicazione', 'post Dedicationem');
    }

    private function afterPentecostFamilyName(int $ordinal, TemporaleContext $ctx, string $phraseIt, string $phraseLa): string
    {
        if (LitLocale::LATIN_PRIMARY_LANGUAGE === LitLocale::$PRIMARY_LANGUAGE) {
            return sprintf('Dominica %s %s', LatinUtils::LATIN_ORDINAL[$ordinal], $phraseLa);
        }
        $ordinalStr = Utilities::getOrdinal($ordinal, $ctx->localeDateFormatter->getLocale(), $this->ordinalFormatter($ctx), LatinUtils::LATIN_ORDINAL);
        return sprintf('%s domenica %s', $ordinalStr, $phraseIt);
    }
```

`ServiceUnavailableException` is already imported by the file (used by `createPropriumDeTemporeLiturgicalEventByKey`).

- [ ] **Step 4: Wire into `buildTemporale()`**

Add `$this->calculateAfterPentecostSundays($ctx);` **after** `calculateAfterPentecostAnchors($ctx);` (it reads the `DedicationDuomo` anchor).

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php --filter testAfterPentecostSubBlocks2025`
Expected: PASS (adjust the two "last-in-block" ordinals to the engine's computed values per the Step-1 note, if needed).

- [ ] **Step 6: Extend the ordo-validation pin**

Add a spot-check to `AmbrosianTemporaleOrdoValidationTest.php` asserting the **first** Sunday of each sub-block for 2024/2025/2026 (first-Sundays are anchor-derived and stable).
Keep `@group slow`.

- [ ] **Step 7: Run + analyse + lint + golden master**

Run: `composer test:quick`, `composer analyse`, `composer lint` → clean. Golden master green.

- [ ] **Step 8: Commit**

```bash
git add src/Models/Calendar/Temporale/AmbrosianTemporale.php phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleOrdoValidationTest.php
git commit -m "feat(ambrosian): synthesize after-Pentecost Sundays in three sub-blocks"
```

---

### Task 5: Generic ferial-weekday fill helper + weekday name builder

**Files:**

- Modify: `src/Models/Calendar/Temporale/AmbrosianTemporale.php`
- Test: `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`

**Interfaces:**

- Consumes: `$ctx->cal->inCalendar()`, `$ctx->localeDateFormatter->getDayOfTheWeekFormatter()`, `LatinUtils::LATIN_DAYOFTHEWEEK`, `Utilities::getOrdinal()`.
- Produces:
- `fillFerialWeekdays(TemporaleContext $ctx, DateTime $from, DateTime $to, LitColor $color, callable $keyBuilder, callable $nameBuilder, bool $lentenAliturgicalFridays = false):
    void` — iterates day-by-day over `[$from, $to)`, skipping Sundays and any date already `inCalendar()`, creating a `WEEKDAY` event per free ferial day; season-stamped; on Lenten
    Fridays sets `is_aliturgical = true` when `$lentenAliturgicalFridays`. `$keyBuilder(DateTime): string` and `$nameBuilder(DateTime): string` are supplied by each per-season
    caller.
- `weekdayName(DateTime $date, string $seasonPhraseIt, string $seasonPhraseLa, ?int $weekNumber, TemporaleContext $ctx): string` — e.g. (it) "Lunedì della II settimana dopo
    Pentecoste", (la) "Feria II hebdomadæ II post Pentecosten".
  - `englishWeekday(DateTime $date): string` — `Monday`…`Saturday` for locale-independent keys.

- [ ] **Step 1: Write the failing test**

Test the helper directly through a tiny public-ish seam. Since the engine has no public fill entry yet, assert it via a season fill you can add trivially: instead, unit-test
`fillFerialWeekdays` by adding the after-Epiphany fill (Task 9 uses it too) is premature — so test the helper's **observable contract** through a temporary spy is overkill.
Instead, write the test against the **after-Epiphany** weekday fill wired in this task as the helper's first caller (a natural, self-contained range):

```php
    public function testAfterEpiphanyWeekdaysFillGaps2025(): void
    {
        $d = $this->runEngine(2025);
        // After-Epiphany block: Mon after Baptism (2025-01-13) .. Sat before Lent1 (2025-03-08).
        // Mondays are weekdays; assert a representative weekday exists and Sundays are NOT overwritten.
        self::assertArrayHasKey('AfterEpiphanyWeekday2Monday', $d);   // week 2 Monday = 2025-01-13
        self::assertSame('2025-01-13', $d['AfterEpiphanyWeekday2Monday']);
        self::assertArrayHasKey('AfterEpiphanyWeekday3Saturday', $d); // 2025-01-25
        self::assertSame('2025-01-25', $d['AfterEpiphanyWeekday3Saturday']);
        // The Sunday 2025-01-19 remains AfterEpiphany2 (a weekday fill must never take a Sunday)
        self::assertSame('2025-01-19', $d['AfterEpiphany2']);
    }
```

> **Implementer note:** the week-number scheme for after-Epiphany weekdays follows the Roman convention (week of the *following* Sunday; Baptism-week = week 1, so the first Monday
> after Baptism is in week 2). Confirm the exact `{N}` from the engine and adjust the key in the assertion if the numbering base differs — but the base MUST match the Sunday
> numbering (a Monday in the week leading to `AfterEpiphany{k}` is `AfterEpiphanyWeekday{k}Monday`).

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php --filter testAfterEpiphanyWeekdaysFillGaps2025`
Expected: FAIL — key absent.

- [ ] **Step 3: Implement the helper + name/key builders**

```php
    /**
     * Fill ferial weekdays over [$from, $to), skipping Sundays and any date already
     * occupied (Sundays, anchors). Each free ferial day becomes a WEEKDAY event,
     * season-stamped from its key. $keyBuilder(DateTime $date): string produces the
     * event key; $nameBuilder(DateTime $date): string the display name. On Lenten
     * Fridays, when $lentenAliturgicalFridays is true, is_aliturgical is set.
     *
     * @param callable(DateTime):string $keyBuilder
     * @param callable(DateTime):string $nameBuilder
     */
    private function fillFerialWeekdays(TemporaleContext $ctx, DateTime $from, DateTime $to, LitColor $color, callable $keyBuilder, callable $nameBuilder, bool $lentenAliturgicalFridays = false): void
    {
        $day = clone $from;
        while ($day < $to) {
            $isSunday = (int) $day->format('N') === 7;
            if (false === $isSunday && false === $ctx->cal->inCalendar($day)) {
                $key   = $keyBuilder($day);
                $name  = $nameBuilder($day);
                $event = new LiturgicalEvent($name, clone $day, $color, LitEventType::MOBILE, LitGrade::WEEKDAY);
                if ($lentenAliturgicalFridays && (int) $day->format('N') === 5) {
                    $event->is_aliturgical = true;
                }
                $ctx->cal->addLiturgicalEvent($key, $event);
                $this->stampSeason($event);
            }
            $day = ( clone $day )->add(new \DateInterval('P1D'));
        }
    }

    /** English weekday name (Monday…Saturday) for locale-independent keys. */
    private function englishWeekday(DateTime $date): string
    {
        return ['1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday'][$date->format('N')];
    }

    /**
     * Localized ferial name, e.g. (it) "Lunedì della II settimana dopo Pentecoste",
     * (la) "Feria II hebdomadæ II post Pentecosten". When $weekNumber is null the
     * week clause is omitted (e.g. de Exceptáto ferie named by date in Task 6).
     */
    private function weekdayName(DateTime $date, string $seasonPhraseIt, string $seasonPhraseLa, ?int $weekNumber, TemporaleContext $ctx): string
    {
        $n = (int) $date->format('N');
        if (LitLocale::LATIN_PRIMARY_LANGUAGE === LitLocale::$PRIMARY_LANGUAGE) {
            $feria = LatinUtils::LATIN_DAYOFTHEWEEK[$date->format('w')]; // e.g. "Feria II"
            if (null === $weekNumber) {
                return sprintf('%s %s', $feria, $seasonPhraseLa);
            }
            return sprintf('%s hebdomadæ %s %s', $feria, LatinUtils::LATIN_ORDINAL[$weekNumber], $seasonPhraseLa);
        }
        $weekday = $ctx->localeDateFormatter->getDayOfTheWeekFormatter()->format($date->format('U'));
        $weekday = Utilities::ucfirst(is_string($weekday) ? $weekday : '');
        if (null === $weekNumber) {
            return sprintf('%s %s', $weekday, $seasonPhraseIt);
        }
        $ordinalStr = Utilities::getOrdinal($weekNumber, $ctx->localeDateFormatter->getLocale(), $this->ordinalFormatter($ctx), LatinUtils::LATIN_ORDINAL);
        return sprintf('%s della %s settimana %s', $weekday, $ordinalStr, $seasonPhraseIt);
    }
```

Confirm `Utilities::ucfirst()` exists (used by the Roman weekday code — see `CalendarHandler::calculateWeekdaysOrdinaryTime`); if the signature differs, match the Roman call site
exactly.

- [ ] **Step 4: Wire the after-Epiphany weekday fill (first caller)**

Add `calculateAfterEpiphanyWeekdays()` and call it from `buildTemporale()` after `calculateAfterEpiphanySundays()`:

```php
    /** After-Epiphany ferie: Monday after Baptism … Saturday before Lent I. */
    private function calculateAfterEpiphanyWeekdays(TemporaleContext $ctx): void
    {
        $year    = $ctx->params->Year;
        $baptism = DateTime::fromFormat('6-1-' . $year)->modify('next Sunday');
        $lent1   = Utilities::calcGregEaster($year)->sub(new \DateInterval('P' . ( 6 * 7 ) . 'D'));
        $from    = ( clone $baptism )->add(new \DateInterval('P1D')); // Monday after Baptism
        $this->fillFerialWeekdays(
            $ctx,
            $from,
            $lent1,
            LitColor::GREEN,
            fn (DateTime $d): string => 'AfterEpiphanyWeekday' . $this->afterEpiphanyWeekNumber($d, $baptism) . $this->englishWeekday($d),
            fn (DateTime $d): string => $this->weekdayName($d, "dopo l'Epifania", 'post Epiphaniam', $this->afterEpiphanyWeekNumber($d, $baptism), $ctx)
        );
    }

    /**
     * Week number of a ferial day in the after-Epiphany block: the ordinal of the
     * Sunday that closes its week, matching the Sunday numbering (BaptismLord = week 1,
     * so the first Monday after Baptism is week 2).
     */
    private function afterEpiphanyWeekNumber(DateTime $date, DateTime $baptism): int
    {
        $daysSinceBaptism = (int) $baptism->diff($date)->format('%a');
        return (int) floor(( $daysSinceBaptism - 1 ) / 7) + 2;
    }
```

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php --filter testAfterEpiphanyWeekdaysFillGaps2025`
Expected: PASS (adjust `{N}` in the test assertion to the engine's computed week number if the base differs; the loop is authoritative).

- [ ] **Step 6: Analyse + lint + golden master**

Run: `composer analyse`, `composer lint` → clean. Golden master green.

- [ ] **Step 7: Commit**

```bash
git add src/Models/Calendar/Temporale/AmbrosianTemporale.php phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php
git commit -m "feat(ambrosian): add ferial weekday-fill helper and after-Epiphany ferie"
```

---

### Task 6: Advent (de Exceptáto) and Christmas ferial fill

**Files:**

- Modify: `src/Models/Calendar/Temporale/AmbrosianTemporale.php`
- Test: `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`

**Interfaces:**

- Consumes: `fillFerialWeekdays()`, `adventOne()`.
- Produces: `calculateAdventWeekdays()`, `calculateChristmasWeekdays()`.

**Norms:** Advent ferie run from the Monday after Advent I to the Saturday before Christmas; the ferie *de Exceptáto* are **Dec 17–23** (Dec 18–23 when Dec 17 is a Sunday) (n. 39).
Christmas ferie run from Dec 26 to the Saturday before Baptism, with `Circoncisione` (Jan 1) and `Epiphany` (Jan 6) already placed as anchors and skipped by `inCalendar()`.

- Advent keys: `AdventWeekday{DDD}` (`{DDD}` = zero-padded day-of-month) — matches the Roman `AdventWeekdayDec{N}` family closely enough to classify under `/^AdventWeekday/`.
  Colour morello. Names: the de Exceptáto days (Dec 17/18–23) get a distinct phrase; the rest use the week-of-Advent phrase.
- Christmas keys: `ChristmasWeekday{DDD}`. Colour white.

- [ ] **Step 1: Write the failing tests**

```php
    public function testAdventDeExceptatoFerie2025(): void
    {
        $d = $this->runEngine(2025);
        // 2025: Dec 17 is Wednesday, so de Exceptáto = Dec 17..23 (Sundays excluded).
        self::assertArrayHasKey('AdventWeekday017', $d);
        self::assertSame('2025-12-17', $d['AdventWeekday017']);
        self::assertArrayHasKey('AdventWeekday023', $d);
        self::assertSame('2025-12-23', $d['AdventWeekday023']);
    }

    public function testChristmasFerieSkipAnchors2025(): void
    {
        $d = $this->runEngine(2025);
        self::assertArrayHasKey('ChristmasWeekday029', $d); // Dec 29 2025 (Mon)
        self::assertSame('2025-12-29', $d['ChristmasWeekday029']);
        // Jan 1 (Circoncisione) and Jan 6 (Epiphany) stay anchors, never overwritten:
        self::assertArrayNotHasKey('ChristmasWeekday001', $d);
        self::assertArrayNotHasKey('ChristmasWeekday006', $d);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php --filter 'testAdventDeExceptatoFerie2025|testChristmasFerieSkipAnchors2025'`
Expected: FAIL.

- [ ] **Step 3: Implement the two fills**

```php
    /** Advent ferie: Monday after Advent I … Saturday before Christmas; Dec 17(18)–23 are de Exceptáto (n. 39). */
    private function calculateAdventWeekdays(TemporaleContext $ctx): void
    {
        $year    = $ctx->params->Year;
        $from    = ( clone $this->adventOne($year) )->add(new \DateInterval('P1D'));
        $to      = DateTime::fromFormat('25-12-' . $year); // Christmas (exclusive)
        $this->fillFerialWeekdays(
            $ctx,
            $from,
            $to,
            LitColor::MORELLO,
            fn (DateTime $d): string => 'AdventWeekday' . $d->format('d'),
            fn (DateTime $d): string => $this->adventWeekdayName($d, $ctx)
        );
    }

    private function adventWeekdayName(DateTime $date, TemporaleContext $ctx): string
    {
        $md = (int) $date->format('nd'); // e.g. 1217 for Dec 17
        if ($md >= 1217 && $md <= 1223) {
            return $this->weekdayName($date, 'de Exceptáto', 'de Exceptato', null, $ctx);
        }
        return $this->weekdayName($date, "d'Avvento", 'Adventus', null, $ctx);
    }

    /** Christmas ferie: Dec 26 … Saturday before Baptism (Circoncisione/Epiphany anchors skipped). */
    private function calculateChristmasWeekdays(TemporaleContext $ctx): void
    {
        $year    = $ctx->params->Year;
        $baptism = DateTime::fromFormat('6-1-' . $year)->modify('next Sunday');
        $from    = DateTime::fromFormat('26-12-' . $year);
        $this->fillFerialWeekdays(
            $ctx,
            $from,
            clone $baptism, // up to (not incl.) Baptism Sunday
            LitColor::WHITE,
            fn (DateTime $d): string => 'ChristmasWeekday' . $d->format('d'),
            fn (DateTime $d): string => $this->weekdayName($d, 'del tempo di Natale', 'Nativitatis', null, $ctx)
        );
    }
```

> **Implementer note on the Dec/Jan key collision:** `ChristmasWeekday{DDD}` uses day-of-month only, so Dec 27 → `ChristmasWeekday027` and Jan 3 → `ChristmasWeekday003`; no
> collision within one civil year because the Christmas fill spans Dec 26–31 (`0DD` = 26–31) then Jan 2–5 (`00D`). If a future year needs both a Dec and Jan day with the same
> day-number they still differ (`0DD` vs `00D` never overlap). The `fillFerialWeekdays` loop crosses the Dec→Jan boundary automatically because the same civil `$year` is used for
> both anchors here (the harness builds a single civil year; the handler-level year semantics are Plan 7's concern).

- [ ] **Step 4: Wire into `buildTemporale()`**

Add `$this->calculateAdventWeekdays($ctx);` and `$this->calculateChristmasWeekdays($ctx);` after the Sunday tasks.

- [ ] **Step 5–7: Run, analyse, lint, golden master, commit**

Run the two filters → PASS (adjust dates to the engine's computed values only after confirming arithmetic). `composer analyse`, `composer lint`, golden master → clean.

```bash
git add src/Models/Calendar/Temporale/AmbrosianTemporale.php phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php
git commit -m "feat(ambrosian): fill Advent (de Exceptato) and Christmas ferie"
```

---

### Task 7: Lenten ferial fill with aliturgical Fridays

**Files:**

- Modify: `src/Models/Calendar/Temporale/AmbrosianTemporale.php`
- Test: `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`

**Interfaces:**

- Consumes: `fillFerialWeekdays(..., lentenAliturgicalFridays: true)`.
- Produces: `calculateLentWeekdays()`.

**Norm (nn. 24–27):** Lent has no Ash Wednesday; it begins on a Sunday (`Lent1`), ashes are imposed the following Monday (`AshesMonday`, an anchor). Lenten Fridays are
**aliturgical** (no Mass). The block runs from `Lent1` (Sunday, excluded) to the Saturday before `PalmSun`; `SabatoTradSymb` (Saturday before Palm Sunday) is an anchor, skipped.
Colour morello. Keys `LentWeekday{N}{EnglishDay}` (matches `/^LentWeekday\d/`).

- [ ] **Step 1: Write the failing test**

2025: Lent1 = 2025-03-09; PalmSun = 2025-04-13. Lenten Fridays: Mar 14, 21, 28, Apr 4, 11. Each must carry `is_aliturgical = true`.

```php
    public function testLentenFridaysAreAliturgical2025(): void
    {
        $events = $this->runEngineEvents(2025);
        $fridays = array_filter(
            $events,
            fn ($e) => $e->liturgical_season === LitSeason::LENT
                && (int) $e->date->format('N') === 5
                && $e->grade === LitGrade::WEEKDAY
        );
        self::assertNotEmpty($fridays);
        foreach ($fridays as $key => $e) {
            self::assertTrue($e->is_aliturgical, "$key should be aliturgical");
        }
        // A Lenten non-Friday ferial is not aliturgical:
        $someThursday = $events['LentWeekday1Thursday'] ?? null;
        self::assertNotNull($someThursday);
        self::assertNull($someThursday->is_aliturgical);
    }
```

Add `use LiturgicalCalendar\Api\Enum\LitGrade;` to the test imports.

- [ ] **Step 2: Run to verify it fails**

Expected: FAIL — no Lenten ferie yet.

- [ ] **Step 3: Implement**

```php
    /** Lenten ferie: Lent I (excl.) … Saturday before Palm Sunday; Fridays are aliturgical (nn. 24–27). */
    private function calculateLentWeekdays(TemporaleContext $ctx): void
    {
        $year    = $ctx->params->Year;
        $lent1   = Utilities::calcGregEaster($year)->sub(new \DateInterval('P' . ( 6 * 7 ) . 'D'));
        $palmSun = Utilities::calcGregEaster($year)->sub(new \DateInterval('P7D'));
        $from    = ( clone $lent1 )->add(new \DateInterval('P1D'));
        $this->fillFerialWeekdays(
            $ctx,
            $from,
            clone $palmSun, // up to (not incl.) Palm Sunday; SabatoTradSymb anchor is skipped by inCalendar()
            LitColor::MORELLO,
            fn (DateTime $d): string => 'LentWeekday' . $this->lentWeekNumber($d, $lent1) . $this->englishWeekday($d),
            fn (DateTime $d): string => $this->weekdayName($d, 'di Quaresima', 'Quadragesimæ', $this->lentWeekNumber($d, $lent1), $ctx),
            true
        );
    }

    /** Lenten week number: Lent I is week 1; the following Monday begins week 1's ferie. */
    private function lentWeekNumber(DateTime $date, DateTime $lent1): int
    {
        $daysSince = (int) $lent1->diff($date)->format('%a');
        return (int) floor(( $daysSince - 1 ) / 7) + 1;
    }
```

> **Aliturgical suspension note (n. 4, deferred):** the norm suspends the no-Mass rule when Annunciation/St Joseph land on an aliturgical Friday. That interaction is
> precedence-layer + ordo-validation (Plan 9); here the temporale unconditionally flags Lenten Fridays `is_aliturgical = true`, and precedence later clears it when a solemnity
> supersedes. Do not implement the suspension in this task.

- [ ] **Step 4: Wire into `buildTemporale()`** — add `$this->calculateLentWeekdays($ctx);`.

- [ ] **Step 5–6: Run, analyse, lint, golden master** → clean.

- [ ] **Step 7: Commit**

```bash
git add src/Models/Calendar/Temporale/AmbrosianTemporale.php phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php
git commit -m "feat(ambrosian): fill Lenten ferie and flag aliturgical Fridays"
```

---

### Task 8: Easter ferial fill

**Files:**

- Modify: `src/Models/Calendar/Temporale/AmbrosianTemporale.php`
- Test: `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`

**Interfaces:**

- Consumes: `fillFerialWeekdays()`.
- Produces: `calculateEasterWeekdays()`.

**Norm:** The Easter octave weekdays (`Mon..SatOctaveEaster`) are already anchors. The remaining Easter ferie run from the Monday after `Easter2` (i.e. after the octave) to the
Saturday before `Pentecost`; `Ascension` (Thursday, Easter + 39d) is an anchor and skipped. Colour white. Keys `EasterWeekday{N}{EnglishDay}` (matches `/^EasterWeekday\d/`).

- [ ] **Step 1: Write the failing test**

2025: Easter = 2025-04-20; octave ends Sat 2025-04-26; Easter2 = 2025-04-27; Pentecost = 2025-06-08. First post-octave ferial Monday = 2025-04-28.

```php
    public function testEasterFerieAfterOctave2025(): void
    {
        $d = $this->runEngine(2025);
        self::assertArrayHasKey('EasterWeekday2Monday', $d);
        self::assertSame('2025-04-28', $d['EasterWeekday2Monday']);
        // Ascension (Thu 2025-05-29) stays its own anchor:
        self::assertSame('2025-05-29', $d['Ascension']);
        // Octave weekdays remain their own anchors, not re-emitted:
        self::assertArrayHasKey('MonOctaveEaster', $d);
    }
```

- [ ] **Step 2: Run to verify it fails** → FAIL.

- [ ] **Step 3: Implement**

```php
    /** Easter ferie after the octave: Monday after Easter II … Saturday before Pentecost (Ascension anchor skipped). */
    private function calculateEasterWeekdays(TemporaleContext $ctx): void
    {
        $year      = $ctx->params->Year;
        $easter    = Utilities::calcGregEaster($year);
        $easter2   = ( clone $easter )->add(new \DateInterval('P7D'));   // Easter II
        $pentecost = ( clone $easter )->add(new \DateInterval('P49D'));
        $from      = ( clone $easter2 )->add(new \DateInterval('P1D'));  // Monday after Easter II
        $this->fillFerialWeekdays(
            $ctx,
            $from,
            clone $pentecost, // up to (not incl.) Pentecost Sunday
            LitColor::WHITE,
            fn (DateTime $d): string => 'EasterWeekday' . $this->easterWeekNumber($d, $easter) . $this->englishWeekday($d),
            fn (DateTime $d): string => $this->weekdayName($d, 'di Pasqua', 'Paschæ', $this->easterWeekNumber($d, $easter), $ctx)
        );
    }

    /** Easter week number: octave = week 1; Easter II opens week 2. */
    private function easterWeekNumber(DateTime $date, DateTime $easter): int
    {
        $daysSince = (int) $easter->diff($date)->format('%a');
        return (int) floor($daysSince / 7) + 1;
    }
```

- [ ] **Step 4: Wire into `buildTemporale()`** — add `$this->calculateEasterWeekdays($ctx);`.

- [ ] **Step 5–6: Run, analyse, lint, golden master** → clean.

- [ ] **Step 7: Commit**

```bash
git add src/Models/Calendar/Temporale/AmbrosianTemporale.php phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php
git commit -m "feat(ambrosian): fill post-octave Easter ferie"
```

---

### Task 9: After-Pentecost ferial fill (3 sub-blocks)

**Files:**

- Modify: `src/Models/Calendar/Temporale/AmbrosianTemporale.php`
- Test: `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`

**Interfaces:**

- Consumes: `fillFerialWeekdays()`, `martyrdomAnchor()`, `adventOne()`, `DedicationDuomo` anchor.
- Produces: `calculateAfterPentecostWeekdays()`.

**Norm (n. 42):** ferie across the three sub-blocks, from the Monday after Pentecost to the Saturday before Advent I. Colour green. Per-block key stems and week numbering restart
with each block, matching the Sunday numbering of Task 4:

- (a) `AfterPentecostWeekday{N}{EnglishDay}`, phrase "dopo Pentecoste" — Monday after Pentecost … Sat before the 1st Sunday after the Martyrdom.
- (b) `AfterPentecostMartyrdomWeekday{N}{EnglishDay}`, phrase "dopo il Martirio" — that Sunday's Monday … Sat before the Dedication.
- (c) `AfterPentecostDedicationWeekday{N}{EnglishDay}`, phrase "dopo la Dedicazione" — Monday after the Dedication … Sat before Advent I (`DedicationDuomo` and `ChristKing` anchors
  are skipped by `inCalendar()`).

- [ ] **Step 1: Write the failing test**

```php
    public function testAfterPentecostWeekdaysFill2025(): void
    {
        $d = $this->runEngine(2025);
        // (a) first ferial Monday after Pentecost (2025-06-08) = 2025-06-09, week 1
        self::assertArrayHasKey('AfterPentecostWeekday1Monday', $d);
        self::assertSame('2025-06-09', $d['AfterPentecostWeekday1Monday']);
        // (b) Monday after the 1st Sunday after the Martyrdom (Sun 2025-08-31) = 2025-09-01
        self::assertArrayHasKey('AfterPentecostMartyrdomWeekday1Monday', $d);
        self::assertSame('2025-09-01', $d['AfterPentecostMartyrdomWeekday1Monday']);
        // (c) Monday after the Dedication (Sun 2025-10-19) = 2025-10-20
        self::assertArrayHasKey('AfterPentecostDedicationWeekday1Monday', $d);
        self::assertSame('2025-10-20', $d['AfterPentecostDedicationWeekday1Monday']);
    }
```

- [ ] **Step 2: Run to verify it fails** → FAIL.

- [ ] **Step 3: Implement (reuse a block helper)**

```php
    /** After-Pentecost ferie across the three sub-blocks (n. 42). */
    private function calculateAfterPentecostWeekdays(TemporaleContext $ctx): void
    {
        $year         = $ctx->params->Year;
        $pentecost    = Utilities::calcGregEaster($year)->add(new \DateInterval('P49D'));
        $martyrdomSun = ( clone $this->martyrdomAnchor($year) )->modify('next Sunday');
        $dedication   = $ctx->cal->getLiturgicalEvent('DedicationDuomo')?->date
            ?? throw new ServiceUnavailableException('DedicationDuomo anchor must be placed before after-Pentecost ferie');
        $advent1      = $this->adventOne($year);

        // (a) dopo Pentecoste — anchored on Pentecost
        $this->fillFerialBlock($ctx, ( clone $pentecost )->add(new \DateInterval('P1D')), $martyrdomSun, $pentecost, 'AfterPentecostWeekday', 'dopo Pentecoste', 'post Pentecosten', $ctx);
        // (b) dopo il Martirio — anchored on the 1st Sunday after the Martyrdom
        $this->fillFerialBlock($ctx, ( clone $martyrdomSun )->add(new \DateInterval('P1D')), $dedication, $martyrdomSun, 'AfterPentecostMartyrdomWeekday', 'dopo il Martirio', 'post Martyrium', $ctx);
        // (c) dopo la Dedicazione — anchored on the Dedication Sunday
        $this->fillFerialBlock($ctx, ( clone $dedication )->add(new \DateInterval('P1D')), $advent1, $dedication, 'AfterPentecostDedicationWeekday', 'dopo la Dedicazione', 'post Dedicationem', $ctx);
    }

    /**
     * Fill one after-Pentecost sub-block [$from, $to) with green ferie whose week
     * number is measured from $blockAnchorSunday (block week 1 = the anchor's week).
     */
    private function fillFerialBlock(TemporaleContext $ctx, DateTime $from, DateTime $to, DateTime $blockAnchorSunday, string $keyStem, string $phraseIt, string $phraseLa, TemporaleContext $ctxRef): void
    {
        $this->fillFerialWeekdays(
            $ctx,
            $from,
            $to,
            LitColor::GREEN,
            fn (DateTime $d): string => $keyStem . $this->blockWeekNumber($d, $blockAnchorSunday) . $this->englishWeekday($d),
            fn (DateTime $d): string => $this->weekdayName($d, $phraseIt, $phraseLa, $this->blockWeekNumber($d, $blockAnchorSunday), $ctxRef)
        );
    }

    /** Week number within an after-Pentecost sub-block: week 1 opens the Monday after $anchorSunday. */
    private function blockWeekNumber(DateTime $date, DateTime $anchorSunday): int
    {
        $daysSince = (int) $anchorSunday->diff($date)->format('%a');
        return (int) floor(( $daysSince - 1 ) / 7) + 1;
    }
```

> **Note:** `$ctxRef` duplicates `$ctx` only to satisfy the `weekdayName()` signature within closures without capturing `$this` twice; the implementer may instead pass `$ctx`
> directly and drop the extra parameter — keep whichever passes PHPStan L10 cleanly.

- [ ] **Step 4: Wire into `buildTemporale()`** — add `$this->calculateAfterPentecostWeekdays($ctx);` after the after-Pentecost Sundays.

- [ ] **Step 5–6: Run, analyse, lint, golden master** → clean.

- [ ] **Step 7: Commit**

```bash
git add src/Models/Calendar/Temporale/AmbrosianTemporale.php phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php
git commit -m "feat(ambrosian): fill after-Pentecost ferie across the three sub-blocks"
```

---

### Task 10: Full-year completeness acceptance test + ordo-validation extension

**Files:**

- Create: `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleCompletenessTest.php`
- Modify: `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleOrdoValidationTest.php`

**Interfaces:**

- Consumes: `AmbrosianTemporaleHarnessTrait::runEngineEvents()`.
- Produces: a gap-free coverage guarantee for the temporal year.

**Goal:** prove that after `buildTemporale()`, **every day** from `Advent1` to the day before the *next* Advent I is covered by exactly one temporale event (an anchor, a numbered
Sunday, or a ferial weekday), and that every event carries a non-null `liturgical_season`. This is the load-bearing invariant that makes Plan 7's un-501 wiring able to render a
complete calendar.

- [ ] **Step 1: Write the completeness test**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\DateTime;
use PHPUnit\Framework\TestCase;

/**
 * @group slow
 * Full-year gap-free coverage of the Ambrosian temporal cycle.
 */
final class AmbrosianTemporaleCompletenessTest extends TestCase
{
    use AmbrosianTemporaleHarnessTrait;

    /**
     * Every day from Advent I (year Y) to the day before Advent I (year Y+1) is
     * covered by exactly one temporale event, and every event has a season.
     *
     * @dataProvider civilYears
     */
    public function testTemporalYearIsGapFree(int $year): void
    {
        $events = $this->runEngineEvents($year);

        // Index events by Y-m-d; assert no two temporale events share a date.
        $byDate = [];
        foreach ($events as $key => $e) {
            self::assertNotNull($e->liturgical_season, "Event $key has no liturgical_season");
            $ymd = $e->date->format('Y-m-d');
            self::assertArrayNotHasKey($ymd, $byDate, "Two temporale events on $ymd: $key and {$byDate[$ymd]}");
            $byDate[$ymd] = $key;
        }

        // Walk Advent I (Y) .. day before Advent I (Y+1); every day must be covered.
        $adventThisYear = DateTime::fromFormat('11-11-' . $year)->modify('next Sunday');
        $adventNextYear = DateTime::fromFormat('11-11-' . ( $year + 1 ))->modify('next Sunday');
        $cursor         = clone $adventThisYear;
        while ($cursor < $adventNextYear) {
            $ymd = $cursor->format('Y-m-d');
            self::assertArrayHasKey($ymd, $byDate, "Uncovered temporal day: $ymd");
            $cursor = $cursor->add(new \DateInterval('P1D'));
        }
    }

    /** @return array<string,array{int}> */
    public static function civilYears(): array
    {
        return ['2024' => [2024], '2025' => [2025], '2026' => [2026]];
    }
}
```

> **Implementer note:** `runEngineEvents()` builds a single civil year. The anchor block that belongs to the *previous* liturgical year's tail (Jan 1 – Baptism) and the *next*
> liturgical year's head (Advent I of Y is in year Y; the walk ends before Advent I of Y+1, which this civil-year build does not produce). If the single-civil-year harness cannot
> cover the Advent-I(Y) → Dec-31 → … boundary cleanly, scope the walk to **Advent I (Y) .. Dec 31 (Y)** plus **Jan 1 (Y) .. Saturday before Advent I (Y)** as the harness actually
> produces them, i.e. assert gap-freeness over the two contiguous stretches the civil-year build yields. The essential invariant is *no uncovered day inside a produced stretch* and
> *no duplicate dates* — adapt the walk bounds to the harness's civil-year semantics rather than forcing a cross-year build (cross-year assembly is Plan 7's handler concern).

- [ ] **Step 2: Run it**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleCompletenessTest.php`
Expected: PASS. If it reports an uncovered day, that is a real gap — fix the responsible season fill's boundaries (off-by-one at a season seam) before proceeding. If it reports a
duplicate date, two fills overlap at a seam — tighten the `[from, to)` bounds.

- [ ] **Step 3: Extend the ordo-validation test**

In `AmbrosianTemporaleOrdoValidationTest.php`, add the first-Sunday-of-each-sub-block pins for 2024/2025/2026 (from Task 4) if not already present, and add a single assertion that
the count of `AfterEpiphany*` + `AfterPentecost*` numbered Sundays for 2025 is > 0 (guards against a silently empty block). Keep `@group slow`.

- [ ] **Step 4: Run the full suite + analyse + lint + golden master**

Run: `composer test` (full, includes `@group slow`) → green.
Run: `composer analyse` → clean. `composer lint` → clean.
Run: `vendor/bin/phpunit phpunit_tests/Handlers/CalendarGoldenMasterTest.php` → 9/36 green.

- [ ] **Step 5: Commit**

```bash
git add phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleCompletenessTest.php phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleOrdoValidationTest.php
git commit -m "test(ambrosian): assert gap-free temporal-year coverage and extend ordo validation"
```

---

## Post-Plan Notes

- **Deferred to Plan 9 (ordo-validation + 1976 backfill):** exact ordo wording/numbering of the synthesized Sunday and ferial names; the pre-2008 single-block post-Pentecost
  structure + the year-2008 engine branch; the n. 4 aliturgical-Friday suspension when Annunciation/St Joseph supersede; Holy-Family-on-the-last-January-Sunday interaction (a
  sanctorale feast that supersedes the coincident after-Epiphany Sunday — a precedence concern, validated once wired).
- **Deferred to Plan 7 (un-501 wiring):** adding `AFTER_EPIPHANY`/`AFTER_PENTECOST` (and, if surfaced, `is_dominical`/`is_aliturgical`) to the **response** schema
  `jsondata/schemas/LitCal.json` — its `liturgical_season` enum currently lists only the 6 Roman values in two places, so an Ambrosian calendar response would fail validation. Not
  needed while the endpoint is 501; wire it alongside the handler branch.
- **Whole-branch final review:** dispatch on the most capable model. Focus lenses: (1) season-seam off-by-ones (every `[from, to)` boundary vs its neighbor); (2) that no
  synthesized key can collide with an anchor key or another synthesized key within a year; (3) golden-master byte-identity; (4) PHPStan L10 on the closures/`callable` signatures.
