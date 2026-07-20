# Ambrosian Temporale Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for
> tracking.

**Goal:** Implement `AmbrosianTemporale` — the Ambrosian-rite temporal-cycle engine — as a pure, unit-tested
`TemporaleEngine`, computing the major mobile-anchor block of the Ambrosian liturgical year (Advent through
Christ the King) from a new Ambrosian *Proprium de Tempore* data file, while the `/calendar/ambrosian`
endpoint continues to return `501 Not Implemented`.

**Architecture:** `AmbrosianTemporale implements TemporaleEngine` (`buildTemporale(TemporaleContext $ctx):
void`), mirroring `RomanTemporale`'s scope and structure exactly: it dates temporal keys drawn from a resolved
*Proprium de Tempore* (`PropriumDeTemporeMap`) and adds them to the shared `LiturgicalEventCollection` carried
by the context. It computes the same *class* of events `RomanTemporale` does — the contiguous major mobile
block — not the weekday/Sunday-numbering fill (that fill is handler-level, exactly as Roman Ordinary-Time
Sunday numbering is, and is deferred to a later plan). The engine is exercised only through direct unit tests
in this plan; the handler still short-circuits Ambrosian requests to 501 before any temporale computation.

**Tech Stack:** PHP 8.4+, PHPUnit (pure-logic `PHPUnit\Framework\TestCase` for the engine), PHPStan level 10, phpcs (PSR-12 + project ruleset), gettext `_()`.

## Global Constraints

- **Endpoint stays 501.** Do **not** wire `AmbrosianTemporale` into `CalendarHandler`'s request path or
  remove/relax the `ImplementationException` (501) that `CalendarHandler::handle()` throws for
  `Rite::AMBROSIAN`. The engine is validated by unit tests only. Wiring the handler is a later plan.
- **Byte-identical Roman output.** No task may change any Roman-rite output. The golden-master gate
  (`phpunit_tests/Handlers/CalendarGoldenMasterTest.php`) must stay green after every task; all enum/model
  changes are strictly additive.
- **Scope = 2024 edition, major-anchor block only.** Implement the post-2008 (2024-edition) structure only. Do
  **not** add an edition/year branch (pre-2008 / 1976) in this plan — it is explicitly deferred. Do **not**
  compute after-Epiphany or after-Pentecost *Sunday-numbering / weekday fill* — only the fixed anchors
  (Dedication of the Duomo, Christ the King) that bound the after-Pentecost block.
- **Deferred vocabulary (do NOT add in this plan):** `LitSeason::AFTER_EPIPHANY` / `AFTER_PENTECOST`, the
  event-level `is_dominical` flag (Plan 4, precedence), and the `is_aliturgical` flag (later ferial-fill plan).
  This plan adds only the `LitColor` values the Ambrosian *Proprium de Tempore* data actually references
  (`morello`, `black`), because `LiturgicalEvent::fromObject()` parses colour strings into `LitColor` and will
  throw on an unknown colour. Adding an unused enum value would be flagged by review as YAGNI.
- **Effective rite floor = 1976** (already enforced in `CalendarParams::validateRiteCompatibility()`; nothing to
  add here). The engine itself must be re-runnable per civil year and hold no per-request state between calls
  (the handler runs the pipeline twice for `year_type=LITURGICAL`).
- **Shared Easter only.** Reuse `Utilities::calcGregEaster($year)` for the Easter anchor. Every other anchor is rite-specific and computed in this engine.
- **Grades/colours are provisional.** Where a grade or colour for an Ambrosian temporal key is not certain from
  the norms, use the value specified in Task 3 and treat it as provisional pending Missal/ordo proofing
  (Task 10 and spec §9). Do not invent extra fields.

---

## File Structure

**New source:**

- `src/Models/Calendar/Temporale/AmbrosianTemporale.php` — the engine (mirrors `RomanTemporale.php` in the same directory).
- `jsondata/sourcedata/missals/ambrosian/propriumdetempore/propriumdetempore.json` — Ambrosian temporal keys (grade/type/colour).
- `jsondata/sourcedata/missals/ambrosian/propriumdetempore/i18n/it.json` — Italian names.
- `jsondata/sourcedata/missals/ambrosian/propriumdetempore/i18n/la.json` — Latin names.

**Modified source:**

- `src/Enum/LitColor.php` — add `MORELLO`, `BLACK` (+ `i18n()` match arms).
- `src/Enum/JsonDataConstants.php` — add Ambrosian temporale path constants.
- `src/Enum/JsonData.php` — expose the new constants as enum cases.
- `src/Models/Calendar/Rite/AmbrosianRiteProfile.php` — `temporaleEngine()` returns `new AmbrosianTemporale()` (replacing the `\LogicException`).

**New tests:**

- `phpunit_tests/Enum/LitColorAmbrosianTest.php`
- `phpunit_tests/Enum/JsonDataAmbrosianPathTest.php`
- `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php` (the engine's unit tests — grows task by task)
- `phpunit_tests/Models/Calendar/Temporale/AmbrosianProprioDeTemporeDataTest.php` (data loads + validates)
- `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleOrdoValidationTest.php` (`@group slow`, Task 10)

**Reference (read-only, do not modify):**

- `src/Models/Calendar/Temporale/RomanTemporale.php` — the structural template.
- `src/Models/Calendar/Temporale/TemporaleContext.php` / `TemporaleEngine.php` — the interface + context.
- `src/Models/PropriumDeTemporeMap.php`, `src/Models/PropriumDeTemporeEvent.php` — the data map + event.
- `src/Models/Calendar/LiturgicalEvent.php` — `fromObject()` builds events from proprium entries.

---

## Test Harness Convention (used by every engine test)

The engine consumes a `TemporaleContext`. Every engine unit test builds one the same way. Define this helper once (in Task 4, inside `AmbrosianTemporaleTest`) and reuse it:

```php
/**
 * Builds a TemporaleContext wired to the Ambrosian Proprium de Tempore for a
 * given civil year, mirroring how CalendarHandler wires RomanTemporale.
 *
 * @param array<string> $messages
 */
private function buildContext(int $year, array &$messages): TemporaleContext
{
    // Force the runtime primary language so LocaleDateFormatter + i18n load deterministically.
    LitLocale::$PRIMARY_LANGUAGE = 'it';
    LitLocale::$RUNTIME_LOCALE   = 'it_IT';

    $dataFile = strtr(
        JsonData::AMBROSIAN_TEMPORALE_FILE->path(),
        []
    );
    $i18nFile = strtr(
        JsonData::AMBROSIAN_TEMPORALE_I18N_FILE->path(),
        ['{locale}' => 'it']
    );

    $rawEvents = Utilities::jsonFileToObjectArray($dataFile);
    /** @var array<string,string> $names */
    $names = Utilities::jsonFileToArray($i18nFile);

    $map = PropriumDeTemporeMap::fromObject($rawEvents);
    $map->setNames($names);

    $params = new CalendarParams(['year' => $year]);
    $params->setRite(Rite::AMBROSIAN);

    $cal = new LiturgicalEventCollection($params);

    return new TemporaleContext(
        $cal,
        $params,
        $map,
        new LocaleDateFormatter(LitLocale::$RUNTIME_LOCALE),
        $messages
    );
}
```

> **Implementer note:** Verify the exact `LiturgicalEventCollection` constructor signature and `CalendarParams`
> construction against the current code before writing Task 4's test — earlier tasks in the Ambrosian rollout
> already construct both. If `new LiturgicalEventCollection($params)` differs, follow the constructor the
> codebase actually exposes. The intent is fixed: a fresh collection wired to Ambrosian params. Read the event
> date back with `$ctx->cal->getLiturgicalEvent($key)->date->format('Y-m-d')` (confirm the collection's accessor
> name; `RomanTemporale` adds via `$ctx->cal->addLiturgicalEvent($key, $event)`).

---

## Task 1: `LitColor` — add `morello` and `black`

**Files:**

- Modify: `src/Enum/LitColor.php`
- Test: `phpunit_tests/Enum/LitColorAmbrosianTest.php`

**Interfaces:**

- Produces: `LitColor::MORELLO` (value `'morello'`), `LitColor::BLACK` (value `'black'`). Consumed by the
  Ambrosian *Proprium de Tempore* data (Task 3) via `LiturgicalEvent::fromObject()`.

- [ ] **Step 1: Guard against non-additive breakage — find every exhaustive `match` over `LitColor`**

Run: `grep -rn "match" src | grep -i "litcolor"` and read `src/Enum/LitColor.php`. The only exhaustive
`match ($this)` is `LitColor::i18n()`. If any other exhaustive match over a `LitColor` instance exists, it
must gain arms for the two new cases in this task (PHP throws `\UnhandledMatchError` otherwise). Note them
before proceeding.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Tests\Enum;

use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitLocale;
use PHPUnit\Framework\TestCase;

final class LitColorAmbrosianTest extends TestCase
{
    public function testMorelloAndBlackCasesExist(): void
    {
        $this->assertSame('morello', LitColor::MORELLO->value);
        $this->assertSame('black', LitColor::BLACK->value);
    }

    public function testMorelloAndBlackAreValidFromValue(): void
    {
        $this->assertSame(LitColor::MORELLO, LitColor::from('morello'));
        $this->assertSame(LitColor::BLACK, LitColor::from('black'));
    }

    public function testI18nItalian(): void
    {
        $this->assertSame('violaceo', LitColor::MORELLO->i18n('it_IT'));
        $this->assertSame('nero', LitColor::BLACK->i18n('it_IT'));
    }

    public function testI18nLatin(): void
    {
        $this->assertSame('violaceus', LitColor::MORELLO->i18n(LitLocale::LATIN));
        $this->assertSame('niger', LitColor::BLACK->i18n(LitLocale::LATIN));
    }

    public function testExistingColorsUnchanged(): void
    {
        $this->assertSame('green', LitColor::GREEN->value);
        $this->assertSame('purple', LitColor::PURPLE->value);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Enum/LitColorAmbrosianTest.php`
Expected: FAIL — `LitColor::MORELLO` undefined.

- [ ] **Step 4: Add the two cases and `i18n()` arms**

In `src/Enum/LitColor.php`, add after `case ROSE = 'rose';`:

```php
    case MORELLO = 'morello';
    case BLACK  = 'black';
```

Add these arms to the `match ($this)` in `i18n()` (before the closing `};`, keeping the existing arms intact):

```php
            /**translators: context = liturgical color (Ambrosian "morello"/violet) */
            LitColor::MORELLO => ( $isLatin ? 'violaceus' : _('violaceo') ),
            /**translators: context = liturgical color */
            LitColor::BLACK   => ( $isLatin ? 'niger'     : _('black') ),
```

> The Italian source strings `'violaceo'` and `'black'` pass through `_()`; the `.pot`/`.po` catalogs are
> updated in the translation pass (out of scope here). For a locale with no catalog entry, gettext returns the
> source string, so `i18n('it_IT')` returns `'violaceo'` and `'black'` unless an `it` catalog overrides them.
> **Adjust the test's expected Italian values to whatever `_()` actually returns in the test environment**
> (likely the source strings `'violaceo'` / `'black'`) — do not fight gettext; assert the real return. The Latin
> branch does not use `_()` and is deterministic.

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Enum/LitColorAmbrosianTest.php`
Expected: PASS.

- [ ] **Step 6: Static analysis + style + Roman regression**

Run: `composer analyse && vendor/bin/phpcs src/Enum/LitColor.php && vendor/bin/phpunit phpunit_tests/Handlers/CalendarGoldenMasterTest.php`
Expected: clean; golden-master green (proves additivity did not disturb Roman output).

- [ ] **Step 7: Commit**

```bash
git add src/Enum/LitColor.php phpunit_tests/Enum/LitColorAmbrosianTest.php
git commit -m "feat(ambrosian): add morello and black to LitColor"
```

---

## Task 2: Ambrosian *Proprium de Tempore* path constants

**Files:**

- Modify: `src/Enum/JsonDataConstants.php`
- Modify: `src/Enum/JsonData.php`
- Test: `phpunit_tests/Enum/JsonDataAmbrosianPathTest.php`

**Interfaces:**

- Produces:
  - `JsonData::AMBROSIAN_TEMPORALE_FILE->path()` → `jsondata/sourcedata/missals/ambrosian/propriumdetempore/propriumdetempore.json`
  - `JsonData::AMBROSIAN_TEMPORALE_I18N_FILE->path()` → `jsondata/sourcedata/missals/ambrosian/propriumdetempore/i18n/{locale}.json`
  - Consumed by the engine's test harness (Task 4) and future handler wiring.

> **Interface note:** confirm how `JsonData::CASE->path()` resolves a constant to an absolute/relative path (the
> Roman path uses `JsonData::MISSAL_FILE->path()` then `strtr(...)`). Mirror that exactly; the two new cases
> wrap the two new `JsonDataConstants`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Tests\Enum;

use LiturgicalCalendar\Api\Enum\JsonData;
use PHPUnit\Framework\TestCase;

final class JsonDataAmbrosianPathTest extends TestCase
{
    public function testAmbrosianTemporaleFilePath(): void
    {
        $this->assertStringEndsWith(
            'jsondata/sourcedata/missals/ambrosian/propriumdetempore/propriumdetempore.json',
            JsonData::AMBROSIAN_TEMPORALE_FILE->path()
        );
    }

    public function testAmbrosianTemporaleI18nFilePath(): void
    {
        $this->assertStringEndsWith(
            'jsondata/sourcedata/missals/ambrosian/propriumdetempore/i18n/{locale}.json',
            JsonData::AMBROSIAN_TEMPORALE_I18N_FILE->path()
        );
    }
}
```

> If `->path()` returns an absolute path (project root prefixed), `assertStringEndsWith` still holds. If it does
> not resolve placeholders, that is fine — the `{locale}` placeholder is expected to remain literal here (the
> harness `strtr`s it).

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Enum/JsonDataAmbrosianPathTest.php`
Expected: FAIL — `JsonData::AMBROSIAN_TEMPORALE_FILE` undefined.

- [ ] **Step 3: Add the constants**

In `src/Enum/JsonDataConstants.php`, after the `TEMPORALE_I18N_FILE` constant, add:

```php
    /**
     * Evaluates to 'jsondata/sourcedata/missals/ambrosian/propriumdetempore'.
     */
    public const AMBROSIAN_TEMPORALE_FOLDER = JsonDataConstants::MISSALS_FOLDER . '/ambrosian/propriumdetempore';

    /**
     * Evaluates to 'jsondata/sourcedata/missals/ambrosian/propriumdetempore/propriumdetempore.json'.
     */
    public const AMBROSIAN_TEMPORALE_FILE = JsonDataConstants::AMBROSIAN_TEMPORALE_FOLDER . '/propriumdetempore.json';

    /**
     * Evaluates to 'jsondata/sourcedata/missals/ambrosian/propriumdetempore/i18n'.
     */
    public const AMBROSIAN_TEMPORALE_I18N_FOLDER = JsonDataConstants::AMBROSIAN_TEMPORALE_FOLDER . '/i18n';

    /**
     * Evaluates to 'jsondata/sourcedata/missals/ambrosian/propriumdetempore/i18n/{locale}.json'.
     */
    public const AMBROSIAN_TEMPORALE_I18N_FILE = JsonDataConstants::AMBROSIAN_TEMPORALE_I18N_FOLDER . '/{locale}.json';
```

In `src/Enum/JsonData.php`, after the `TEMPORALE_I18N_FILE` case, add matching cases (mirror the exact backing-constant pattern the file uses):

```php
    case AMBROSIAN_TEMPORALE_FOLDER = JsonDataConstants::AMBROSIAN_TEMPORALE_FOLDER;

    case AMBROSIAN_TEMPORALE_FILE = JsonDataConstants::AMBROSIAN_TEMPORALE_FILE;

    case AMBROSIAN_TEMPORALE_I18N_FOLDER = JsonDataConstants::AMBROSIAN_TEMPORALE_I18N_FOLDER;

    case AMBROSIAN_TEMPORALE_I18N_FILE = JsonDataConstants::AMBROSIAN_TEMPORALE_I18N_FILE;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Enum/JsonDataAmbrosianPathTest.php`
Expected: PASS.

- [ ] **Step 5: Static analysis + style**

Run: `composer analyse && vendor/bin/phpcs src/Enum/JsonDataConstants.php src/Enum/JsonData.php`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Enum/JsonDataConstants.php src/Enum/JsonData.php phpunit_tests/Enum/JsonDataAmbrosianPathTest.php
git commit -m "feat(ambrosian): add Proprium de Tempore path constants"
```

---

## Task 3: Ambrosian *Proprium de Tempore* source data

**Files:**

- Create: `jsondata/sourcedata/missals/ambrosian/propriumdetempore/propriumdetempore.json`
- Create: `jsondata/sourcedata/missals/ambrosian/propriumdetempore/i18n/it.json`
- Create: `jsondata/sourcedata/missals/ambrosian/propriumdetempore/i18n/la.json`
- Test: `phpunit_tests/Models/Calendar/Temporale/AmbrosianProprioDeTemporeDataTest.php`

**Interfaces:**

- Produces: a `PropriumDeTemporeMap`-loadable data file whose `event_key`s are consumed by the engine (Tasks
  4–8). The exact key set below is the engine's contract — Tasks 4–8 date **these keys and only these keys**.

**Key set (the major-anchor block — reuse Roman keys where the event is structurally identical; new keys are Ambrosian-specific):**

| Key               | grade | type   | colour    | Reused from Roman? |
|-------------------|-------|--------|-----------|--------------------|
| `Advent1`         | 7     | mobile | `morello` | key reused         |
| `Advent2`         | 7     | mobile | `morello` | key reused         |
| `Advent3`         | 7     | mobile | `morello` | key reused         |
| `Advent4`         | 7     | mobile | `morello` | key reused         |
| `Advent5`         | 7     | mobile | `morello` | new                |
| `Advent6`         | 7     | mobile | `morello` | new                |
| `Christmas`       | 7     | fixed  | `white`   | key reused         |
| `Circoncisione`   | 6     | fixed  | `white`   | new                |
| `Epiphany`        | 7     | fixed  | `white`   | key reused         |
| `BaptismLord`     | 5     | mobile | `white`   | key reused         |
| `Lent1`           | 7     | mobile | `morello` | key reused         |
| `Lent2`           | 7     | mobile | `morello` | key reused         |
| `Lent3`           | 7     | mobile | `morello` | key reused         |
| `Lent4`           | 7     | mobile | `morello` | key reused         |
| `Lent5`           | 7     | mobile | `morello` | key reused         |
| `AshesMonday`     | 0     | mobile | `morello` | new                |
| `SabatoTradSymb`  | 6     | mobile | `morello` | new                |
| `PalmSun`         | 7     | mobile | `morello` | key reused         |
| `HolyThurs`       | 7     | mobile | `white`   | key reused         |
| `GoodFri`         | 7     | mobile | `morello` | key reused         |
| `EasterVigil`     | 7     | mobile | `white`   | key reused         |
| `Easter`          | 7     | mobile | `white`   | key reused         |
| `MonOctaveEaster` | 7     | mobile | `white`   | key reused         |
| `TueOctaveEaster` | 7     | mobile | `white`   | key reused         |
| `WedOctaveEaster` | 7     | mobile | `white`   | key reused         |
| `ThuOctaveEaster` | 7     | mobile | `white`   | key reused         |
| `FriOctaveEaster` | 7     | mobile | `white`   | key reused         |
| `SatOctaveEaster` | 7     | mobile | `white`   | key reused         |
| `Easter2`         | 7     | mobile | `white`   | key reused         |
| `Easter3`         | 7     | mobile | `white`   | key reused         |
| `Easter4`         | 7     | mobile | `white`   | key reused         |
| `Easter5`         | 7     | mobile | `white`   | key reused         |
| `Easter6`         | 7     | mobile | `white`   | key reused         |
| `Easter7`         | 7     | mobile | `white`   | key reused         |
| `Ascension`       | 7     | mobile | `white`   | key reused         |
| `Pentecost`       | 7     | mobile | `red`     | key reused         |
| `DedicationDuomo` | 7     | mobile | `white`   | new                |
| `ChristKing`      | 6     | mobile | `white`   | key reused         |

> **Provisional data.** Grades/colours follow Roman conventions for reused keys and reasonable Ambrosian
> defaults for new ones; they are proofed against the Missal/ordo in Task 10 and spec §9. `AshesMonday` grade
> `0` = weekday rank (confirm `LitGrade` accepts `0`, as Roman weekdays do; if the ladder differs, use the
> lowest ferial grade the codebase defines and record it).

- [ ] **Step 1: Write `propriumdetempore.json`**

Author the JSON array of objects, one per row above, in the exact shape the Roman file uses:

```json
[
    { "event_key": "Advent1", "grade": 7, "type": "mobile", "color": [ "morello" ] },
    { "event_key": "Advent2", "grade": 7, "type": "mobile", "color": [ "morello" ] },
    { "event_key": "Advent3", "grade": 7, "type": "mobile", "color": [ "morello" ] },
    { "event_key": "Advent4", "grade": 7, "type": "mobile", "color": [ "morello" ] },
    { "event_key": "Advent5", "grade": 7, "type": "mobile", "color": [ "morello" ] },
    { "event_key": "Advent6", "grade": 7, "type": "mobile", "color": [ "morello" ] },
    { "event_key": "Christmas", "grade": 7, "type": "fixed", "color": [ "white" ] },
    { "event_key": "Circoncisione", "grade": 6, "type": "fixed", "color": [ "white" ] },
    { "event_key": "Epiphany", "grade": 7, "type": "fixed", "color": [ "white" ] },
    { "event_key": "BaptismLord", "grade": 5, "type": "mobile", "color": [ "white" ] },
    { "event_key": "Lent1", "grade": 7, "type": "mobile", "color": [ "morello" ] },
    { "event_key": "Lent2", "grade": 7, "type": "mobile", "color": [ "morello" ] },
    { "event_key": "Lent3", "grade": 7, "type": "mobile", "color": [ "morello" ] },
    { "event_key": "Lent4", "grade": 7, "type": "mobile", "color": [ "morello" ] },
    { "event_key": "Lent5", "grade": 7, "type": "mobile", "color": [ "morello" ] },
    { "event_key": "AshesMonday", "grade": 0, "type": "mobile", "color": [ "morello" ] },
    { "event_key": "SabatoTradSymb", "grade": 6, "type": "mobile", "color": [ "morello" ] },
    { "event_key": "PalmSun", "grade": 7, "type": "mobile", "color": [ "morello" ] },
    { "event_key": "HolyThurs", "grade": 7, "type": "mobile", "color": [ "white" ] },
    { "event_key": "GoodFri", "grade": 7, "type": "mobile", "color": [ "morello" ] },
    { "event_key": "EasterVigil", "grade": 7, "type": "mobile", "color": [ "white" ] },
    { "event_key": "Easter", "grade": 7, "type": "mobile", "color": [ "white" ] },
    { "event_key": "MonOctaveEaster", "grade": 7, "type": "mobile", "color": [ "white" ] },
    { "event_key": "TueOctaveEaster", "grade": 7, "type": "mobile", "color": [ "white" ] },
    { "event_key": "WedOctaveEaster", "grade": 7, "type": "mobile", "color": [ "white" ] },
    { "event_key": "ThuOctaveEaster", "grade": 7, "type": "mobile", "color": [ "white" ] },
    { "event_key": "FriOctaveEaster", "grade": 7, "type": "mobile", "color": [ "white" ] },
    { "event_key": "SatOctaveEaster", "grade": 7, "type": "mobile", "color": [ "white" ] },
    { "event_key": "Easter2", "grade": 7, "type": "mobile", "color": [ "white" ] },
    { "event_key": "Easter3", "grade": 7, "type": "mobile", "color": [ "white" ] },
    { "event_key": "Easter4", "grade": 7, "type": "mobile", "color": [ "white" ] },
    { "event_key": "Easter5", "grade": 7, "type": "mobile", "color": [ "white" ] },
    { "event_key": "Easter6", "grade": 7, "type": "mobile", "color": [ "white" ] },
    { "event_key": "Easter7", "grade": 7, "type": "mobile", "color": [ "white" ] },
    { "event_key": "Ascension", "grade": 7, "type": "mobile", "color": [ "white" ] },
    { "event_key": "Pentecost", "grade": 7, "type": "mobile", "color": [ "red" ] },
    { "event_key": "DedicationDuomo", "grade": 7, "type": "mobile", "color": [ "white" ] },
    { "event_key": "ChristKing", "grade": 6, "type": "mobile", "color": [ "white" ] }
]
```

- [ ] **Step 2: Write `i18n/it.json`** (Italian names — keys must match the data file exactly)

```json
{
    "Advent1": "I Domenica di Avvento",
    "Advent2": "II Domenica di Avvento",
    "Advent3": "III Domenica di Avvento",
    "Advent4": "IV Domenica di Avvento",
    "Advent5": "V Domenica di Avvento",
    "Advent6": "VI Domenica di Avvento (dell'Incarnazione o della Divina Maternità di Maria)",
    "Christmas": "Natale del Signore",
    "Circoncisione": "Circoncisione del Signore",
    "Epiphany": "Epifania del Signore",
    "BaptismLord": "Battesimo del Signore",
    "Lent1": "I Domenica di Quaresima (all'inizio di Quaresima)",
    "Lent2": "II Domenica di Quaresima (della Samaritana)",
    "Lent3": "III Domenica di Quaresima (di Abramo)",
    "Lent4": "IV Domenica di Quaresima (del Cieco)",
    "Lent5": "V Domenica di Quaresima (di Lazzaro)",
    "AshesMonday": "Lunedì dopo la I Domenica di Quaresima (imposizione delle Ceneri)",
    "SabatoTradSymb": "Sabato «in traditione Symboli»",
    "PalmSun": "Domenica delle Palme «nella Passione del Signore»",
    "HolyThurs": "Giovedì Santo «nella Cena del Signore»",
    "GoodFri": "Venerdì Santo «nella Passione del Signore»",
    "EasterVigil": "Veglia Pasquale «nella Notte Santa»",
    "Easter": "Domenica di Pasqua «nella Risurrezione del Signore»",
    "MonOctaveEaster": "Lunedì dell'ottava di Pasqua (in albis)",
    "TueOctaveEaster": "Martedì dell'ottava di Pasqua (in albis)",
    "WedOctaveEaster": "Mercoledì dell'ottava di Pasqua (in albis)",
    "ThuOctaveEaster": "Giovedì dell'ottava di Pasqua (in albis)",
    "FriOctaveEaster": "Venerdì dell'ottava di Pasqua (in albis)",
    "SatOctaveEaster": "Sabato dell'ottava di Pasqua (in albis)",
    "Easter2": "II Domenica di Pasqua",
    "Easter3": "III Domenica di Pasqua",
    "Easter4": "IV Domenica di Pasqua",
    "Easter5": "V Domenica di Pasqua",
    "Easter6": "VI Domenica di Pasqua",
    "Easter7": "VII Domenica di Pasqua",
    "Ascension": "Ascensione del Signore",
    "Pentecost": "Domenica di Pentecoste",
    "DedicationDuomo": "Dedicazione del Duomo di Milano",
    "ChristKing": "Nostro Signore Gesù Cristo Re dell'universo"
}
```

- [ ] **Step 3: Write `i18n/la.json`** (Latin names)

```json
{
    "Advent1": "Dominica I in Adventu Domini",
    "Advent2": "Dominica II in Adventu Domini",
    "Advent3": "Dominica III in Adventu Domini",
    "Advent4": "Dominica IV in Adventu Domini",
    "Advent5": "Dominica V in Adventu Domini",
    "Advent6": "Dominica VI in Adventu Domini (de Incarnatione)",
    "Christmas": "In Nativitate Domini",
    "Circoncisione": "In Circumcisione Domini",
    "Epiphany": "In Epiphania Domini",
    "BaptismLord": "In Baptismate Domini",
    "Lent1": "Dominica I in Quadragesima",
    "Lent2": "Dominica II in Quadragesima (de Samaritana)",
    "Lent3": "Dominica III in Quadragesima (de Abraham)",
    "Lent4": "Dominica IV in Quadragesima (de Caeco)",
    "Lent5": "Dominica V in Quadragesima (de Lazaro)",
    "AshesMonday": "Feria II post Dominicam I in Quadragesima (impositio Cinerum)",
    "SabatoTradSymb": "Sabbato in traditione Symboli",
    "PalmSun": "Dominica in Palmis de Passione Domini",
    "HolyThurs": "Feria V in Cena Domini",
    "GoodFri": "Feria VI in Passione Domini",
    "EasterVigil": "In Vigilia Paschali",
    "Easter": "Dominica Paschae in Resurrectione Domini",
    "MonOctaveEaster": "Feria II infra octavam Paschae (in albis)",
    "TueOctaveEaster": "Feria III infra octavam Paschae (in albis)",
    "WedOctaveEaster": "Feria IV infra octavam Paschae (in albis)",
    "ThuOctaveEaster": "Feria V infra octavam Paschae (in albis)",
    "FriOctaveEaster": "Feria VI infra octavam Paschae (in albis)",
    "SatOctaveEaster": "Sabbato infra octavam Paschae (in albis)",
    "Easter2": "Dominica II Paschae",
    "Easter3": "Dominica III Paschae",
    "Easter4": "Dominica IV Paschae",
    "Easter5": "Dominica V Paschae",
    "Easter6": "Dominica VI Paschae",
    "Easter7": "Dominica VII Paschae",
    "Ascension": "In Ascensione Domini",
    "Pentecost": "Dominica Pentecostes",
    "DedicationDuomo": "In Dedicatione Ecclesiae Cathedralis Mediolanensis",
    "ChristKing": "Domini nostri Iesu Christi universorum Regis"
}
```

- [ ] **Step 4: Write the data-integrity test**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Tests\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeMap;
use LiturgicalCalendar\Api\Utilities;
use PHPUnit\Framework\TestCase;

final class AmbrosianProprioDeTemporeDataTest extends TestCase
{
    /** @return array<string,string> */
    private function loadNames(string $locale): array
    {
        $file = strtr(JsonData::AMBROSIAN_TEMPORALE_I18N_FILE->path(), ['{locale}' => $locale]);
        /** @var array<string,string> $names */
        $names = Utilities::jsonFileToArray($file);
        return $names;
    }

    public function testDataFileLoadsIntoMapWithItalianNames(): void
    {
        $raw   = Utilities::jsonFileToObjectArray(JsonData::AMBROSIAN_TEMPORALE_FILE->path());
        $names = $this->loadNames('it');
        $map   = PropriumDeTemporeMap::fromObject($raw);
        $map->setNames($names);

        // Sentinel keys the engine depends on:
        foreach (['Advent1', 'Advent6', 'Circoncisione', 'Lent5', 'AshesMonday', 'SabatoTradSymb', 'DedicationDuomo', 'ChristKing', 'Pentecost'] as $key) {
            $this->assertTrue($map->offsetExists($key), "Missing temporal key: $key");
        }
    }

    public function testItalianAndLatinI18nCoverEveryDataKey(): void
    {
        $raw   = Utilities::jsonFileToObjectArray(JsonData::AMBROSIAN_TEMPORALE_FILE->path());
        $it    = $this->loadNames('it');
        $la    = $this->loadNames('la');
        foreach ($raw as $event) {
            $key = $event->event_key;
            $this->assertArrayHasKey($key, $it, "it.json missing name for $key");
            $this->assertArrayHasKey($key, $la, "la.json missing name for $key");
        }
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianProprioDeTemporeDataTest.php`
Expected: PASS. (If `PropriumDeTemporeMap::fromObject()` requires the `color` strings to resolve to
`LitColor`, Task 1 already provides `morello`; a failure here on `morello` means Task 1 was skipped.)

- [ ] **Step 6: JSON lint + commit**

Run: `composer parallel-lint` is PHP-only; validate JSON with `php -r
'json_decode(file_get_contents("jsondata/sourcedata/missals/ambrosian/propriumdetempore/propriumdetempore.json"),
false, 512, JSON_THROW_ON_ERROR); echo "ok\n";'` (repeat for both i18n files).

```bash
git add jsondata/sourcedata/missals/ambrosian phpunit_tests/Models/Calendar/Temporale/AmbrosianProprioDeTemporeDataTest.php
git commit -m "feat(ambrosian): add Proprium de Tempore source data (2024 edition, anchor block)"
```

---

## Task 4: `AmbrosianTemporale` skeleton + Advent

**Files:**

- Create: `src/Models/Calendar/Temporale/AmbrosianTemporale.php`
- Test: `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`

**Interfaces:**

- Consumes: `TemporaleContext` (`$ctx->cal`, `$ctx->params->Year`, `$ctx->propriumDeTempore`), the Ambrosian data keys from Task 3.
- Produces: `AmbrosianTemporale implements TemporaleEngine` with `public function
  buildTemporale(TemporaleContext $ctx): void`. Private helpers
  `createPropriumDeTemporeLiturgicalEventByKey(?string $key, TemporaleContext $ctx): LiturgicalEvent` and
  `dateIsSunday(DateTime $dt): bool` (copied from `RomanTemporale` — the shared-helper de-dup is tracked as
  existing debt, not resolved here). Tasks 5–8 add the remaining season methods to this class.

**Advent rule (spec §4):** Advent I = the Sunday strictly after Nov 11 (St Martin); Advent II–VI = successive
Sundays (`Advent1 + k weeks`, `k = 1..5`). Advent VI is "dell'Incarnazione / della Divina Maternità" (the
Sunday before Christmas for the validated years). The Nov-11-on-Sunday edge is **deferred** (spec §4) —
implement the literal "Sunday strictly after Nov 11" rule.

**Deterministic expected dates** (verified): civil `2024` → Advent1 `2024-11-17`, Advent6 `2024-12-22`; civil `2025` → Advent1 `2025-11-16`, Advent6 `2025-12-21`.

- [ ] **Step 1: Write the failing test** (create the file with the shared `buildContext` harness from the "Test Harness Convention" section above, then this case)

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Tests\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\LocaleDateFormatter;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\AmbrosianTemporale;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\TemporaleContext;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeMap;
use LiturgicalCalendar\Api\Params\CalendarParams;
use LiturgicalCalendar\Api\Utilities;
use PHPUnit\Framework\TestCase;

final class AmbrosianTemporaleTest extends TestCase
{
    // ... insert the buildContext() helper from the "Test Harness Convention" section ...

    /** @return array<string,string> map of event_key => 'Y-m-d' after buildTemporale */
    private function runEngine(int $year): array
    {
        $messages = [];
        $ctx      = $this->buildContext($year, $messages);
        (new AmbrosianTemporale())->buildTemporale($ctx);

        $dates = [];
        foreach ($ctx->cal->getLiturgicalEventKeys() as $key) { // confirm accessor name against the collection
            $dates[$key] = $ctx->cal->getLiturgicalEvent($key)->date->format('Y-m-d');
        }
        return $dates;
    }

    public function testAdvent2025(): void
    {
        $d = $this->runEngine(2025);
        $this->assertSame('2025-11-16', $d['Advent1']);
        $this->assertSame('2025-11-23', $d['Advent2']);
        $this->assertSame('2025-11-30', $d['Advent3']);
        $this->assertSame('2025-12-07', $d['Advent4']);
        $this->assertSame('2025-12-14', $d['Advent5']);
        $this->assertSame('2025-12-21', $d['Advent6']);
    }

    public function testAdvent2024(): void
    {
        $d = $this->runEngine(2024);
        $this->assertSame('2024-11-17', $d['Advent1']);
        $this->assertSame('2024-12-22', $d['Advent6']);
    }
}
```

> **Implementer:** confirm the collection's key/enumeration accessors (`getLiturgicalEventKeys()` /
> `getLiturgicalEvent()`) against `LiturgicalEventCollection`; adapt names if they differ. If no enumerator
> exists, read events individually by key. Do not change the collection API.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`
Expected: FAIL — `AmbrosianTemporale` not found.

- [ ] **Step 3: Create the engine with the skeleton + Advent**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Utilities;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;

/**
 * Ambrosian-rite temporale engine (2024 edition, major mobile-anchor block).
 *
 * Mirrors RomanTemporale's scope: it dates the contiguous major mobile block of
 * the Ambrosian liturgical year (Advent through Christ the King) drawn from the
 * Ambrosian Proprium de Tempore, and adds the events to the shared calendar.
 * The after-Epiphany / after-Pentecost Sunday-numbering and weekday fill are
 * handler-level (like Roman Ordinary-Time numbering) and are NOT computed here.
 *
 * Re-runnable per civil year; holds no per-request state between calls.
 */
final class AmbrosianTemporale implements TemporaleEngine
{
    public function buildTemporale(TemporaleContext $ctx): void
    {
        $this->calculateAdvent($ctx);
        // Tasks 5-8 append: Christmas/Epiphany, Lent, Triduum+Easter, after-Pentecost anchors.
    }

    /**
     * Creates a LiturgicalEvent from the Ambrosian Proprium de Tempore by key and
     * adds it to the calendar. (Duplicated from RomanTemporale; shared-helper
     * de-dup tracked as existing debt.)
     */
    private function createPropriumDeTemporeLiturgicalEventByKey(?string $key, TemporaleContext $ctx): LiturgicalEvent
    {
        if (null === $key || false === $ctx->propriumDeTempore->offsetExists($key)) {
            throw new ServiceUnavailableException("createPropriumDeTemporeLiturgicalEventByKey requires a key from the Proprium de Tempore, instead got $key");
        }
        $event = LiturgicalEvent::fromObject($ctx->propriumDeTempore[$key]);
        $ctx->cal->addLiturgicalEvent($key, $event);
        return $event;
    }

    private static function dateIsSunday(DateTime $dt): bool
    {
        return (int) $dt->format('N') === 7;
    }

    /**
     * Advent — 6 Sundays. Advent I = the Sunday strictly after Nov 11 (St Martin);
     * Advent II–VI follow at weekly intervals. Advent VI = "dell'Incarnazione /
     * della Divina Maternità". The Nov-11-on-Sunday edge is deferred (spec §4).
     */
    private function calculateAdvent(TemporaleContext $ctx): void
    {
        $year     = $ctx->params->Year;
        $advent1  = DateTime::fromFormat('11-11-' . $year)->modify('next Sunday');
        for ($i = 1; $i <= 6; $i++) {
            $key  = 'Advent' . $i;
            $date = ( clone $advent1 )->add(new \DateInterval('P' . ( ( $i - 1 ) * 7 ) . 'D'));
            $ctx->propriumDeTempore[$key]->setDate($date);
            $this->createPropriumDeTemporeLiturgicalEventByKey($key, $ctx);
        }
    }
}
```

> **Implementer:** verify `DateTime::fromFormat('11-11-YYYY')` parses as day-month-year (the Roman engine uses
> `'25-12-' . $year`, i.e. `d-m-Y`). Confirm `modify('next Sunday')` on `DateTime` returns strictly the
> following Sunday (matches Roman `->modify('next Sunday')` usage). If `DateTime` (the project subclass) needs
> `clone` semantics different from `\DateTime`, mirror how `RomanTemporale` reuses/clones anchors — Roman
> recomputes `Utilities::calcGregEaster(...)` fresh each time rather than cloning; here cloning `$advent1` is
> fine since it is a local, but confirm `DateTime` supports `clone`.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`
Expected: PASS (both Advent cases).

- [ ] **Step 5: Static analysis + style**

Run: `composer analyse && vendor/bin/phpcs src/Models/Calendar/Temporale/AmbrosianTemporale.php`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Models/Calendar/Temporale/AmbrosianTemporale.php phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php
git commit -m "feat(ambrosian): AmbrosianTemporale skeleton + Advent"
```

---

## Task 5: Christmas / Circoncisione / Epiphany / Baptism

**Files:**

- Modify: `src/Models/Calendar/Temporale/AmbrosianTemporale.php`
- Test: `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`

**Interfaces:**

- Consumes: keys `Christmas`, `Circoncisione`, `Epiphany`, `BaptismLord`.
- Produces: `calculateChristmasEpiphany(TemporaleContext $ctx): void`, called from `buildTemporale()` after `calculateAdvent()`.

**Rules (spec §4):** Christmas = Dec 25 (fixed). Circoncisione = **Jan 1** (Christmas octave day, fixed).
Epiphany = **fixed Jan 6** (no Roman Epiphany-option logic). Baptism of the Lord = the **Sunday after Jan 6**
(if Jan 6 is a Sunday, Baptism is the next Sunday, Jan 13). The Dec 26–28 vigil-shift edge (n. 32) is
**deferred** (spec §4).

**Deterministic expected dates:** civil `2025` → Christmas `2025-12-25`, Circoncisione `2025-01-01`, Epiphany
`2025-01-06`, BaptismLord `2025-01-12` (Jan 6 2025 = Monday → next Sunday = Jan 12). Civil `2024` →
BaptismLord `2024-01-07` (Jan 6 2024 = Saturday → Jan 7).

- [ ] **Step 1: Add failing test cases**

```php
    public function testChristmasEpiphany2025(): void
    {
        $d = $this->runEngine(2025);
        $this->assertSame('2025-12-25', $d['Christmas']);
        $this->assertSame('2025-01-01', $d['Circoncisione']);
        $this->assertSame('2025-01-06', $d['Epiphany']);
        $this->assertSame('2025-01-12', $d['BaptismLord']);
    }

    public function testBaptism2024(): void
    {
        $d = $this->runEngine(2024);
        $this->assertSame('2024-01-07', $d['BaptismLord']);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter 'testChristmasEpiphany2025|testBaptism2024' phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`
Expected: FAIL — keys absent (undefined array offset / event not found).

- [ ] **Step 3: Implement `calculateChristmasEpiphany` + wire into `buildTemporale`**

Add the call in `buildTemporale()` right after `$this->calculateAdvent($ctx);`:

```php
        $this->calculateChristmasEpiphany($ctx);
```

Add the method:

```php
    /**
     * Christmas (Dec 25), Circoncisione (Jan 1, octave day), Epiphany (fixed Jan 6),
     * and Baptism of the Lord (Sunday after Jan 6). Ambrosian Epiphany has no
     * date-option logic; the Dec 26–28 vigil shift is deferred (spec §4).
     */
    private function calculateChristmasEpiphany(TemporaleContext $ctx): void
    {
        $year = $ctx->params->Year;

        $ctx->propriumDeTempore['Christmas']->setDate(DateTime::fromFormat('25-12-' . $year));
        $this->createPropriumDeTemporeLiturgicalEventByKey('Christmas', $ctx);

        $ctx->propriumDeTempore['Circoncisione']->setDate(DateTime::fromFormat('1-1-' . $year));
        $this->createPropriumDeTemporeLiturgicalEventByKey('Circoncisione', $ctx);

        $epiphany = DateTime::fromFormat('6-1-' . $year);
        $ctx->propriumDeTempore['Epiphany']->setDate($epiphany);
        $this->createPropriumDeTemporeLiturgicalEventByKey('Epiphany', $ctx);

        $ctx->propriumDeTempore['BaptismLord']->setDate(( clone $epiphany )->modify('next Sunday'));
        $this->createPropriumDeTemporeLiturgicalEventByKey('BaptismLord', $ctx);
    }
```

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`
Expected: PASS (all cases so far).

- [ ] **Step 5: Analyse + style + commit**

```bash
composer analyse && vendor/bin/phpcs src/Models/Calendar/Temporale/AmbrosianTemporale.php
git add src/Models/Calendar/Temporale/AmbrosianTemporale.php phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php
git commit -m "feat(ambrosian): Christmas, Circoncisione, Epiphany, Baptism"
```

---

## Task 6: Lent (no Ash Wednesday) + Palm Sunday + ashes Monday + Sabato in traditione symboli

**Files:**

- Modify: `src/Models/Calendar/Temporale/AmbrosianTemporale.php`
- Test: `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`

**Interfaces:**

- Consumes: keys `Lent1`..`Lent5`, `AshesMonday`, `SabatoTradSymb`, `PalmSun`.
- Produces: `calculateLent(TemporaleContext $ctx): void`, called after `calculateChristmasEpiphany()`.

**Rules (spec §4):** **No Ash Wednesday** (do not create it — the key does not exist in the Ambrosian data,
and the test asserts its absence). Lent I = Easter − 42 days (the 6th Sunday before Easter, a Sunday). Lent
II–V = Lent I + 1..4 weeks (named Samaritana / Abramo / Cieco / Lazzaro — naming carried by the data). Ashes
imposed the **Monday after Lent I** (`AshesMonday` = Lent I + 1 day). Palm Sunday = Easter − 7. **Sabato in
traditione symboli** = the Saturday before Palm Sunday = Easter − 8. (Aliturgical Lenten Fridays are
weekday-fill, deferred — no `is_aliturgical` in this plan.)

**Deterministic expected dates:** civil `2025` (Easter `2025-04-20`) → Lent1 `2025-03-09`, Lent2 `2025-03-16`,
Lent3 `2025-03-23`, Lent4 `2025-03-30`, Lent5 `2025-04-06`, AshesMonday `2025-03-10`, PalmSun `2025-04-13`,
SabatoTradSymb `2025-04-12`. Civil `2024` (Easter `2024-03-31`) → Lent1 `2024-02-18`, AshesMonday
`2024-02-19`, SabatoTradSymb `2024-03-23`.

- [ ] **Step 1: Add failing test cases**

```php
    public function testLent2025(): void
    {
        $d = $this->runEngine(2025);
        $this->assertSame('2025-03-09', $d['Lent1']);
        $this->assertSame('2025-03-16', $d['Lent2']);
        $this->assertSame('2025-03-23', $d['Lent3']);
        $this->assertSame('2025-03-30', $d['Lent4']);
        $this->assertSame('2025-04-06', $d['Lent5']);
        $this->assertSame('2025-03-10', $d['AshesMonday']);
        $this->assertSame('2025-04-13', $d['PalmSun']);
        $this->assertSame('2025-04-12', $d['SabatoTradSymb']);
    }

    public function testNoAshWednesday2025(): void
    {
        $d = $this->runEngine(2025);
        $this->assertArrayNotHasKey('AshWednesday', $d);
    }

    public function testLent2024(): void
    {
        $d = $this->runEngine(2024);
        $this->assertSame('2024-02-18', $d['Lent1']);
        $this->assertSame('2024-02-19', $d['AshesMonday']);
        $this->assertSame('2024-03-23', $d['SabatoTradSymb']);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter 'testLent2025|testLent2024|testNoAshWednesday2025' phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`
Expected: FAIL — Lent keys absent.

- [ ] **Step 3: Implement `calculateLent` + wire in**

Add to `buildTemporale()` after the Christmas/Epiphany call:

```php
        $this->calculateLent($ctx);
```

Add the method (mirror `RomanTemporale`'s use of `Utilities::calcGregEaster($year)`):

```php
    /**
     * Lent — begins on a Sunday (Lent I = Easter − 42d); NO Ash Wednesday. Ashes
     * are imposed the Monday after Lent I. Lent II–V are the named Sundays
     * (Samaritana / Abramo / Cieco / Lazzaro, naming from data). Palm Sunday =
     * Easter − 7d; Sabato "in traditione symboli" = the Saturday before it
     * (Easter − 8d). Aliturgical Lenten Fridays are weekday-fill (deferred).
     */
    private function calculateLent(TemporaleContext $ctx): void
    {
        $year = $ctx->params->Year;

        $lent1 = Utilities::calcGregEaster($year)->sub(new \DateInterval('P' . ( 6 * 7 ) . 'D'));
        for ($i = 1; $i <= 5; $i++) {
            $key  = 'Lent' . $i;
            $date = ( clone $lent1 )->add(new \DateInterval('P' . ( ( $i - 1 ) * 7 ) . 'D'));
            $ctx->propriumDeTempore[$key]->setDate($date);
            $this->createPropriumDeTemporeLiturgicalEventByKey($key, $ctx);
        }

        $ctx->propriumDeTempore['AshesMonday']->setDate(( clone $lent1 )->add(new \DateInterval('P1D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('AshesMonday', $ctx);

        $ctx->propriumDeTempore['PalmSun']->setDate(Utilities::calcGregEaster($year)->sub(new \DateInterval('P7D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('PalmSun', $ctx);

        $ctx->propriumDeTempore['SabatoTradSymb']->setDate(Utilities::calcGregEaster($year)->sub(new \DateInterval('P8D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('SabatoTradSymb', $ctx);
    }
```

> **Implementer:** if `Utilities::calcGregEaster($year)` returns a fresh object each call (Roman engine relies
> on this), the `clone $lent1` reuse is safe. If `->sub()`/`->add()` mutate in place, follow the Roman pattern
> of recomputing `Utilities::calcGregEaster($year)` per anchor instead of cloning. Verify against
> `RomanTemporale::calculateSundaysMajorSeasons`.

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`
Expected: PASS.

- [ ] **Step 5: Analyse + style + commit**

```bash
composer analyse && vendor/bin/phpcs src/Models/Calendar/Temporale/AmbrosianTemporale.php
git add src/Models/Calendar/Temporale/AmbrosianTemporale.php phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php
git commit -m "feat(ambrosian): Lent (no Ash Wednesday), Palm Sunday, ashes Monday, Sabato in traditione symboli"
```

---

## Task 7: Triduum, Easter octave (in albis), Easter Sundays, Ascension, Pentecost

**Files:**

- Modify: `src/Models/Calendar/Temporale/AmbrosianTemporale.php`
- Test: `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`

**Interfaces:**

- Consumes: `HolyThurs`, `GoodFri`, `EasterVigil`, `Easter`, `Mon..SatOctaveEaster`, `Easter2`..`Easter7`, `Ascension`, `Pentecost`.
- Produces: `calculateEasterCycle(TemporaleContext $ctx): void`, called after `calculateLent()`.

**Rules (spec §4):** Triduum: Holy Thursday = Easter − 3, Good Friday = Easter − 2, Easter Vigil = Easter − 1,
Easter = Gregorian Easter. Easter octave (in albis): Mon..Sat = Easter + 1..6. Easter II–VII = Easter + 1..6
weeks. Ascension = **Easter + 39** (Thursday; Ambrosian keeps Thursday — the `Ascension` request param has no
effect). Pentecost = **Easter + 49**.

**Deterministic expected dates:** civil `2025` (Easter `2025-04-20`) → HolyThurs `2025-04-17`, GoodFri
`2025-04-18`, EasterVigil `2025-04-19`, Easter `2025-04-20`, MonOctaveEaster `2025-04-21`, SatOctaveEaster
`2025-04-26`, Easter2 `2025-04-27`, Easter7 `2025-06-01`, Ascension `2025-05-29`, Pentecost `2025-06-08`.

- [ ] **Step 1: Add failing test case**

```php
    public function testEasterCycle2025(): void
    {
        $d = $this->runEngine(2025);
        $this->assertSame('2025-04-17', $d['HolyThurs']);
        $this->assertSame('2025-04-18', $d['GoodFri']);
        $this->assertSame('2025-04-19', $d['EasterVigil']);
        $this->assertSame('2025-04-20', $d['Easter']);
        $this->assertSame('2025-04-21', $d['MonOctaveEaster']);
        $this->assertSame('2025-04-26', $d['SatOctaveEaster']);
        $this->assertSame('2025-04-27', $d['Easter2']);
        $this->assertSame('2025-06-01', $d['Easter7']);
        $this->assertSame('2025-05-29', $d['Ascension']);
        $this->assertSame('2025-06-08', $d['Pentecost']);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter testEasterCycle2025 phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`
Expected: FAIL — keys absent.

- [ ] **Step 3: Implement `calculateEasterCycle` + wire in**

Add to `buildTemporale()` after the Lent call:

```php
        $this->calculateEasterCycle($ctx);
```

Add the method:

```php
    /**
     * Easter cycle: Triduum (Easter − 3..−1), Easter, the octave "in albis"
     * (Easter + 1..6), Easter Sundays II–VII (Easter + 1..6 weeks), Ascension
     * (Easter + 39d, Thursday) and Pentecost (Easter + 49d). The Ambrosian rite
     * keeps Ascension on Thursday; the Ascension request param has no effect.
     */
    private function calculateEasterCycle(TemporaleContext $ctx): void
    {
        $year = $ctx->params->Year;

        $ctx->propriumDeTempore['HolyThurs']->setDate(Utilities::calcGregEaster($year)->sub(new \DateInterval('P3D')));
        $ctx->propriumDeTempore['GoodFri']->setDate(Utilities::calcGregEaster($year)->sub(new \DateInterval('P2D')));
        $ctx->propriumDeTempore['EasterVigil']->setDate(Utilities::calcGregEaster($year)->sub(new \DateInterval('P1D')));
        $ctx->propriumDeTempore['Easter']->setDate(Utilities::calcGregEaster($year));
        $this->createPropriumDeTemporeLiturgicalEventByKey('HolyThurs', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('GoodFri', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('EasterVigil', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('Easter', $ctx);

        $octaveKeys = ['MonOctaveEaster', 'TueOctaveEaster', 'WedOctaveEaster', 'ThuOctaveEaster', 'FriOctaveEaster', 'SatOctaveEaster'];
        foreach ($octaveKeys as $offset => $key) {
            $ctx->propriumDeTempore[$key]->setDate(Utilities::calcGregEaster($year)->add(new \DateInterval('P' . ( $offset + 1 ) . 'D')));
            $this->createPropriumDeTemporeLiturgicalEventByKey($key, $ctx);
        }

        for ($i = 2; $i <= 7; $i++) {
            $key = 'Easter' . $i;
            $ctx->propriumDeTempore[$key]->setDate(Utilities::calcGregEaster($year)->add(new \DateInterval('P' . ( 7 * ( $i - 1 ) ) . 'D')));
            $this->createPropriumDeTemporeLiturgicalEventByKey($key, $ctx);
        }

        $ctx->propriumDeTempore['Ascension']->setDate(Utilities::calcGregEaster($year)->add(new \DateInterval('P39D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('Ascension', $ctx);

        $ctx->propriumDeTempore['Pentecost']->setDate(Utilities::calcGregEaster($year)->add(new \DateInterval('P49D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('Pentecost', $ctx);
    }
```

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`
Expected: PASS.

- [ ] **Step 5: Analyse + style + commit**

```bash
composer analyse && vendor/bin/phpcs src/Models/Calendar/Temporale/AmbrosianTemporale.php
git add src/Models/Calendar/Temporale/AmbrosianTemporale.php phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php
git commit -m "feat(ambrosian): Triduum, Easter octave, Easter Sundays, Ascension, Pentecost"
```

---

## Task 8: After-Pentecost anchors — Dedication of the Duomo + Christ the King

**Files:**

- Modify: `src/Models/Calendar/Temporale/AmbrosianTemporale.php`
- Test: `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`

**Interfaces:**

- Consumes: `DedicationDuomo`, `ChristKing`.
- Produces: `calculateAfterPentecostAnchors(TemporaleContext $ctx): void`, called last in `buildTemporale()`.

**Rules (spec §4):** Dedication of the Duomo di Milano = the **3rd Sunday of October**. Christ the King = the
**last Sunday after the Dedication** = the Sunday before Advent I (i.e. `Advent I − 7 days`, equivalently the
4th Sunday before Christmas's last Sunday). Only these two anchors are computed; the after-Pentecost
Sunday-numbering / sub-block fill (dopo Pentecoste / dopo il Martirio / dopo la Dedicazione) is handler-level
and **deferred** (Global Constraints).

**Deterministic expected dates:** civil `2025` → DedicationDuomo `2025-10-19`, ChristKing `2025-11-09`. Civil `2024` → DedicationDuomo `2024-10-20`, ChristKing `2024-11-10`.

- [ ] **Step 1: Add failing test cases**

```php
    public function testAfterPentecostAnchors2025(): void
    {
        $d = $this->runEngine(2025);
        $this->assertSame('2025-10-19', $d['DedicationDuomo']);
        $this->assertSame('2025-11-09', $d['ChristKing']);
    }

    public function testAfterPentecostAnchors2024(): void
    {
        $d = $this->runEngine(2024);
        $this->assertSame('2024-10-20', $d['DedicationDuomo']);
        $this->assertSame('2024-11-10', $d['ChristKing']);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter 'testAfterPentecostAnchors' phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`
Expected: FAIL — keys absent.

- [ ] **Step 3: Implement `calculateAfterPentecostAnchors` + wire in**

Add to `buildTemporale()` as the last call:

```php
        $this->calculateAfterPentecostAnchors($ctx);
```

Add the method:

```php
    /**
     * After-Pentecost anchors: Dedication of the Duomo di Milano (3rd Sunday of
     * October) and Christ the King (the Sunday before Advent I = the last Sunday
     * after the Dedication). The after-Pentecost Sunday-numbering / sub-block fill
     * is handler-level and deferred (see plan Global Constraints).
     */
    private function calculateAfterPentecostAnchors(TemporaleContext $ctx): void
    {
        $year = $ctx->params->Year;

        // 3rd Sunday of October = 1st Sunday on/after Oct 1, plus 2 weeks.
        $firstSundayOct = DateTime::fromFormat('1-10-' . $year);
        if (false === self::dateIsSunday($firstSundayOct)) {
            $firstSundayOct = $firstSundayOct->modify('next Sunday');
        }
        $dedication = ( clone $firstSundayOct )->add(new \DateInterval('P14D'));
        $ctx->propriumDeTempore['DedicationDuomo']->setDate($dedication);
        $this->createPropriumDeTemporeLiturgicalEventByKey('DedicationDuomo', $ctx);

        // Christ the King = the Sunday before Advent I (Advent I = Sunday after Nov 11).
        $advent1    = DateTime::fromFormat('11-11-' . $year)->modify('next Sunday');
        $christKing = ( clone $advent1 )->sub(new \DateInterval('P7D'));
        $ctx->propriumDeTempore['ChristKing']->setDate($christKing);
        $this->createPropriumDeTemporeLiturgicalEventByKey('ChristKing', $ctx);
    }
```

> **Implementer:** confirm whether the project `DateTime` supports the string `'third sunday of october YYYY'`
> via `modify()`; if it does, you may use it directly instead of the on/after-Oct-1 + 2-weeks arithmetic (both
> yield the 3rd Sunday). Keep whichever is verified against the expected dates. Ensure
> `DateTime::fromFormat('1-10-' . $year)` on a date that *is* a Sunday is not skipped — the `dateIsSunday` guard
> handles that (Oct 1 rarely a Sunday, but be correct).

- [ ] **Step 4: Run the full engine test to verify pass + internal consistency**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php`
Expected: PASS (every case). Add a final invariant assertion in a new test to lock the season's coherence:

```php
    public function testChristKingIsSundayBeforeAdvent1(): void
    {
        foreach ([2024, 2025] as $year) {
            $d = $this->runEngine($year);
            $ck  = new \DateTimeImmutable($d['ChristKing']);
            $a1  = new \DateTimeImmutable($d['Advent1']);
            $this->assertSame(7, (int) $ck->format('N'), "Christ the King must be a Sunday ($year)");
            $this->assertSame('7', $a1->diff($ck)->format('%R%a') === '-7' ? '7' : '7'); // sanity placeholder
            $this->assertSame($a1->modify('-7 days')->format('Y-m-d'), $d['ChristKing']);
        }
    }
```

> Simplify the assertion to the single meaningful check `assertSame($a1->modify('-7 days')->format('Y-m-d'),
> $d['ChristKing'])` plus the Sunday check; drop the placeholder line. (It is shown only to make the intent
> explicit — the implementer writes the clean form.)

- [ ] **Step 5: Analyse + style + commit**

```bash
composer analyse && vendor/bin/phpcs src/Models/Calendar/Temporale/AmbrosianTemporale.php
git add src/Models/Calendar/Temporale/AmbrosianTemporale.php phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleTest.php
git commit -m "feat(ambrosian): Dedication of the Duomo + Christ the King anchors"
```

---

## Task 9: Wire `AmbrosianRiteProfile::temporaleEngine()` + Roman-regression re-verify

**Files:**

- Modify: `src/Models/Calendar/Rite/AmbrosianRiteProfile.php`
- Test: `phpunit_tests/Models/Calendar/Rite/AmbrosianRiteProfileTest.php` (extend the existing test from Plan 2)

**Interfaces:**

- Consumes: `AmbrosianTemporale` (Task 4).
- Produces: `AmbrosianRiteProfile::temporaleEngine()` returns `new AmbrosianTemporale()` (was throwing
  `\LogicException`). The `/calendar/ambrosian` **endpoint behaviour is unchanged** — the handler still 501s
  before reaching the profile's engine. This only makes the profile self-consistent and lets the factory return
  a usable Ambrosian profile for future wiring/tests.

- [ ] **Step 1: Update the profile test** — replace the "throws until Plan 3" expectation with the real return

Find the existing assertion in `AmbrosianRiteProfileTest` that `temporaleEngine()` throws `\LogicException` and replace it:

```php
    public function testTemporaleEngineReturnsAmbrosianTemporale(): void
    {
        $profile = new AmbrosianRiteProfile();
        $this->assertInstanceOf(AmbrosianTemporale::class, $profile->temporaleEngine());
    }
```

Add `use LiturgicalCalendar\Api\Models\Calendar\Temporale\AmbrosianTemporale;` and remove any now-unused `expectException(\LogicException::class)` import/usage for this method.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Rite/AmbrosianRiteProfileTest.php`
Expected: FAIL — still throws `\LogicException`.

- [ ] **Step 3: Implement**

In `src/Models/Calendar/Rite/AmbrosianRiteProfile.php`, replace the `temporaleEngine()` body (which throws `\LogicException`) with:

```php
    public function temporaleEngine(): TemporaleEngine
    {
        return new AmbrosianTemporale();
    }
```

Add the import: `use LiturgicalCalendar\Api\Models\Calendar\Temporale\AmbrosianTemporale;` (and keep the
existing `TemporaleEngine` import). Remove the now-obsolete comment referencing "throws until Plan 3".

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Rite/AmbrosianRiteProfileTest.php`
Expected: PASS.

- [ ] **Step 5: Prove the endpoint is still 501 and Roman output is byte-identical**

Run:

```bash
vendor/bin/phpunit phpunit_tests/Handlers/CalendarRiteRoutingTest.php phpunit_tests/Handlers/CalendarGoldenMasterTest.php
composer analyse && vendor/bin/phpcs src/Models/Calendar/Rite/AmbrosianRiteProfile.php
```

Expected: `CalendarRiteRoutingTest` still asserts `/calendar/ambrosian` → 501 (unchanged); golden-master
green; analysis/style clean. If any routing test asserted the profile throws, that assertion belonged to the
endpoint-level 501 (thrown in the handler, not the profile) and must remain green without change — investigate
before editing.

- [ ] **Step 6: Commit**

```bash
git add src/Models/Calendar/Rite/AmbrosianRiteProfile.php phpunit_tests/Models/Calendar/Rite/AmbrosianRiteProfileTest.php
git commit -m "feat(ambrosian): wire AmbrosianRiteProfile::temporaleEngine() to AmbrosianTemporale"
```

---

## Task 10: Ordo validation (2024 edition) — Year C 2024–25 & Year A 2025–26

**Files:**

- Create: `phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleOrdoValidationTest.php`
- Modify (docs): append an ordo-validation findings section to `docs/superpowers/plans/2026-07-20-ambrosian-03-temporale-engine.md` (this file) or a sibling `…-ordo-findings.md`.

**Interfaces:**

- Consumes: the full `AmbrosianTemporale` engine.
- Produces: an `@group slow` acceptance test pinning the engine's anchor output for the two liturgical years
  chiesadimilano.it covers, plus a written record of the manual spot-check.

**Validation basis (spec §7.4):** No printed ordo exists. The chiesadimilano.it daily-liturgy widget covers
only the 2024-edition liturgy from Advent 2024. Validate the engine's **civil-year** output that composes
those two liturgical years:

- **Year C (2024–2025):** Advent 2024 → engine civil year `2024` (Advent I `2024-11-17`); the Jan–Nov 2025 portion → engine civil year `2025`.
- **Year A (2025–2026):** Advent 2025 → engine civil year `2025` (Advent I `2025-11-16`); the Jan–Nov 2026 portion → engine civil year `2026`.

- [ ] **Step 1: Write the acceptance test** (`@group slow`) asserting the anchor block for civil years 2024, 2025, 2026

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Tests\Models\Calendar\Temporale;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
// ... same imports + buildContext()/runEngine() helpers as AmbrosianTemporaleTest
// (extract the harness into a shared trait or base to avoid duplication).

#[Group('slow')]
final class AmbrosianTemporaleOrdoValidationTest extends TestCase
{
    /**
     * Expected anchor dates for the three civil years spanning Year C (2024-25)
     * and Year A (2025-26), verified against chiesadimilano.it spot-checks.
     *
     * @return array<int, array<string,string>>
     */
    private const EXPECTED = [
        2024 => ['Advent1' => '2024-11-17', 'Advent6' => '2024-12-22', 'DedicationDuomo' => '2024-10-20', 'ChristKing' => '2024-11-10', 'Lent1' => '2024-02-18', 'Ascension' => '2024-05-09', 'Pentecost' => '2024-05-19'],
        2025 => ['Advent1' => '2025-11-16', 'Advent6' => '2025-12-21', 'DedicationDuomo' => '2025-10-19', 'ChristKing' => '2025-11-09', 'Lent1' => '2025-03-09', 'Ascension' => '2025-05-29', 'Pentecost' => '2025-06-08'],
        2026 => ['Advent1' => '2026-11-15', 'DedicationDuomo' => '2026-10-18', 'ChristKing' => '2026-11-08'],
    ];

    public function testAnchorsAcrossValidatedYears(): void
    {
        foreach (self::EXPECTED as $year => $expected) {
            $d = $this->runEngine($year);
            foreach ($expected as $key => $date) {
                $this->assertSame($date, $d[$key], "$key ($year)");
            }
        }
    }
}
```

> **Implementer:** the `2026` expected dates above are computed the same way as 2024/2025 (Advent I 2026 =
> Sunday after Nov 11 2026; Dedication = 3rd Sunday Oct 2026; Christ the King = Advent I − 7). Re-derive them
> with the same one-off PHP snippet used to verify 2024/2025 before committing, and correct if they differ. Do
> **not** guess.

- [ ] **Step 2: Run the acceptance test**

Run: `vendor/bin/phpunit --group slow phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleOrdoValidationTest.php`
Expected: PASS. If any assertion fails, the engine (not the test) is wrong — fix the engine.

- [ ] **Step 3: Manual spot-check against chiesadimilano.it (record findings)**

Cross-check a representative sample against the site's daily widget (2024-edition only):

- I Domenica di Avvento, Year C → `…/anno-c-2024-2025-la/i-domenica-di-avvento-…` → engine `Advent1` for civil `2024` = `2024-11-17`.
- I Domenica di Avvento, Year A → `…/anno-a-2025-2026-ra/…` → engine `Advent1` for civil `2025` = `2025-11-16`.
- A weekday in an after-Pentecost sub-block (e.g. "settimana della VIII domenica dopo Pentecoste", Year A) →
  confirms the after-Pentecost block starts after `Pentecost` and before `DedicationDuomo` (numbering fill is
  deferred, but the *bounds* must bracket the site's dates).
- Dedication of the Duomo and Christ the King dates for both liturgical years.

Record, in a short findings block appended to this plan (or `…-ordo-findings.md`): each checked item, site
value vs engine value, and any discrepancy. Discrepancies in *deferred* areas (exact Sunday numbering, Dec
26–28 shifts, Nov-11-on-Sunday) are logged as follow-ups, not fixed here. Discrepancies in the *anchor block*
are engine bugs — fix them.

- [ ] **Step 4: Update the rollout memory + commit**

Note in `ambrosian-rite-rollout.md` that Plan 3 anchor block is ordo-validated for Year C 2024-25 & Year A 2025-26; list any logged follow-ups.

```bash
git add phpunit_tests/Models/Calendar/Temporale/AmbrosianTemporaleOrdoValidationTest.php docs/superpowers/plans/2026-07-20-ambrosian-03-temporale-engine.md
git commit -m "test(ambrosian): ordo-validation of temporale anchors (Year C 2024-25, Year A 2025-26)"
```

---

## Self-Review (completed by plan author)

**Spec coverage (spec §4 major-anchor block):** Advent 6 Sundays (Task 4) ·
Christmas/Circoncisione/Epiphany-fixed/Baptism (Task 5) · Lent no-Ash-Wednesday, named Sundays, ashes Monday,
Sabato in traditione symboli, Palm (Task 6) · Triduum/Easter octave/Easter
Sundays/Ascension+39/Pentecost+49 (Task 7) · Dedication of the Duomo and Christ the King (Task 8). Vocabulary:
`LitColor` morello/black (Task 1, the only vocabulary the engine data exercises). Wiring + regression (Task
9). Ordo-validation gate scoped to 2024 edition, Year C 2024-25 & Year A 2025-26 (Task 10). ✓

**Explicitly deferred (documented in Global Constraints, spec-consistent):** after-Epiphany & after-Pentecost
Sunday-numbering / weekday fill; `is_aliturgical` (Lenten Fridays) & `is_dominical` flags;
`LitSeason::AFTER_EPIPHANY`/`AFTER_PENTECOST`; the pre-2008 (1976) edition branch; Dec 26–28 vigil shifts;
Nov-11-on-Sunday Advent edge; endpoint un-501-ing (all later plans). ✓

**Placeholder scan:** every code step carries complete code. The one intentional "sanity placeholder" line in
Task 8 Step 4 is called out and the implementer is told to write the clean form. Provisional grades/colours
are flagged for Task 10 proofing, not left blank. ✓

**Type/name consistency:** engine method names
(`calculateAdvent`/`calculateChristmasEpiphany`/`calculateLent`/`calculateEasterCycle`/`calculateAfterPentecostAnchors`)
are consistent between the skeleton (Task 4) and the tasks that add them. Event keys in the data file (Task 3)
exactly match the keys each engine task dates. Test helper (`buildContext`/`runEngine`) defined once and
reused. Collection accessor names flagged for implementer verification (`addLiturgicalEvent` confirmed from
`RomanTemporale`; read-side accessors to confirm). ✓

## Ordo-validation findings (Task 10)

Automated gate: `AmbrosianTemporaleOrdoValidationTest::testAnchorsAcrossValidatedYears` (`@group slow`) pins
the engine's anchor block for civil years 2024, 2025, and 2026, and is green. The harness (`buildContext()` /
`runEngine()`) was extracted from `AmbrosianTemporaleTest` into `AmbrosianTemporaleHarnessTrait` and is now
`use`d by both test classes; the full 11-test `AmbrosianTemporaleTest` suite still passes unchanged after the
extraction.

2026 anchors were not given by the brief as pre-verified and were re-derived independently (native-PHP
`DateTimeImmutable`, mirroring the engine's own rules) before being committed: `Advent1` `2026-11-15`,
`DedicationDuomo` `2026-10-18`, `ChristKing` `2026-11-08` — all three matched the brief's stated values
exactly, so no correction was needed.

Manual spot-check against chiesadimilano.it (best-effort, web access was available this run):

- **I Domenica di Avvento, Year C 2024-25 (civil 2024):** site = 17 Novembre 2024; engine `Advent1` =
  2024-11-17. Match.
- **I Domenica di Avvento, Year A 2025-26 (civil 2025):** site = 16 Novembre 2025; engine `Advent1` =
  2025-11-16. Match.
- **Dedicazione del Duomo, Year C 2024-25 (civil 2025):** site = 19 Ottobre 2025; engine `DedicationDuomo` =
  2025-10-19. Match.
- **Cristo Re, Year C 2024-25 (civil 2025):** site = 9 Novembre 2025; engine `ChristKing` = 2025-11-09. Match.
- **After-Pentecost bound check, Year A 2025-26 (civil 2026):** site's "Lunedì della settimana della VIII
  Domenica dopo Pentecoste" = 20 Luglio 2026; engine `Pentecost` 2026-05-24 through `DedicationDuomo`
  2026-10-18 brackets that date correctly (exact numbering fill itself is deferred, not asserted).

Pages checked via site search (`chiesadimilano.it/almanacco/letture-rito-ambrosiano/...`); URLs recorded in
the Task 10 report. All four exact-date spot-checks matched the engine with no discrepancy. The bound check
confirms the after-Pentecost sub-block sits between `Pentecost` and `DedicationDuomo` as expected, without
asserting exact Sunday numbering (deferred, per Global Constraints).

Not spot-checked: `DedicationDuomo`/`ChristKing` for civil year 2024 (Oct/Nov 2024) fall in liturgical Year B
2023-2024, which predates the chiesadimilano.it 2024-edition widget's Advent-2024 start — outside this task's
validation basis (spec §7.4). Those two civil-2024 anchors remain covered only by the deterministic
per-season unit tests from Task 8, which already assert them.

**Follow-ups logged (deferred areas only, no anchor-block discrepancies found):**

- After-Pentecost / after-Epiphany exact Sunday numbering and weekday fill — still deferred per Global
  Constraints; the site's "VIII Domenica dopo Pentecoste" naming for 2026-07-20 was used only as a bound
  check, not asserted as an exact ordinal.
- Dec 26–28 vigil shifts and the Nov-11-on-Sunday Advent edge — not exercised by 2024/2025/2026 (Nov 11 never
  fell on a Sunday in those years), remain deferred as before.
