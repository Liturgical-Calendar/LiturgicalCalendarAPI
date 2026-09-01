# Ambrosian Edition Catalogue and Per-Missal Lectionary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Coin the Ambrosian rite's first post-conciliar edition (`EDITIO_TYPICA_1976`), make the Ambrosian missal
resolver year-aware, report honestly when the sanctorale of a governing edition is not held, and move the Ambrosian
lectionary onto the per-missal seam that already exists.

**Architecture:** `AmbrosianMissal` declares a second, data-less edition with an exclusive `until_year`.
`AmbrosianMissalResolver::resolve()` stops returning a hard-coded id and reads the declared year windows.
A separate `selectSanctoraleEdition()` answers the different question of which edition we actually hold data for,
returning a `MissalEditionSelection` that says whether a substitution happened, so `CalendarHandler` can report it.
The lectionary becomes a per-missal map on `AmbrosianMissal`, mirroring `RomanMissal`.

**Tech Stack:** PHP 8.4, PHPUnit 12, PHPStan level 10, PHP_CodeSniffer (PSR-12 + `phpcs.xml`), gettext.

**Spec:** `docs/superpowers/specs/2026-09-01-ambrosian-editions-and-per-missal-lectionary-design.md`

## Global Constraints

- Work **only** in the worktree `/home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian`, on branch
  `feat/957-ambrosian-editions`. The sibling `LiturgicalCalendarAPI` checkout is shared with other agents — never run
  `git checkout`, `git commit` or any file edit there.
- `cd` into the worktree at the start of every shell command. Do not rely on a persisted working directory.
- **Never use `--no-verify`.** CaptainHook pre-commit hooks enforce phpcs and markdownlint; fix and re-commit instead.
- PSR-12 with the repo's modifications: short array syntax, 4-space indent, single quotes unless interpolating.
  Line length is not enforced in PHP.
- PHPStan runs at **level 10** and scans `src` only. Every new method needs precise types; `array_key_first()` on a
  possibly-empty array must be null-checked.
- `until_year` in a missal's year limits is **EXCLUSIVE**. `CalendarHandler` drops a missal when
  `Year >= until_year`, and `RomanMissal::$yearLimits` pairs `ITALY_EDITION_1983 => until_year 2002` with
  `EDITIO_TYPICA_TERTIA_2002 => since_year 2002`.
- Do **not** add `EDITIO_TYPICA_1976` to `AccessRequestRepository::GRC_OBJECT_IDS`. Data-less editions stay out, as
  every data-less Roman edition already does. Generalising that object type is issue #955, a separate plan.
- Do **not** coin `EDITIO_TYPICA_1981`, `EDITIO_TYPICA_1990` or `EDITIO_TYPICA_2026`. 1981 and 2026 are Latin
  translations (i18n sidecars, not delta layers); 1990 is a revised reprint inside the first edition.
- Do **not** add sanctorale or lectionary source data. Every new map entry is `false`.

## Prerequisites

**A `.env.local` MUST exist in the worktree before any task is verified.** `CalendarHandlerAmbrosianSanctoraleLoadTest`
extends `AbstractHandlerTestCase`, whose `setUpBeforeClass()` calls `markTestSkipped()` when `JWT_SECRET` is absent —
and PHPUnit does not print a skip reason without `--display-skipped`. Without this file, Task 5's new test reports
green **without ever executing**.

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
cp ../LiturgicalCalendarAPI/.env.local .env.local
```

Confirm it took effect before trusting any Handler-test result:

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
vendor/bin/phpunit phpunit_tests/Handlers/CalendarHandlerAmbrosianSanctoraleLoadTest.php --display-skipped
```

Expected: tests run and pass. If you see `Skipped: 1` with a JWT reason, the file is missing or unreadable — stop and
fix it, do not proceed.

If `composer analyse` fails with a PHPStan cache error mentioning a path that "is not a file", run `rm -rf /tmp/phpstan`
— the result cache is shared across worktrees.

## File Structure

| file                                                     | responsibility                                                                |
|----------------------------------------------------------|-------------------------------------------------------------------------------|
| `src/Enum/AmbrosianMissal.php`                           | declares the rite's editions, their paths, year limits and lectionary map     |
| `src/Enum/AmbrosianMissalSource.php`                     | `MissalSource` wrapper; delegates the lectionary lookup instead of hardcoding |
| `src/Models/Calendar/Missal/AmbrosianMissalResolver.php` | which edition governs a year, and which one we hold data for                  |
| `src/Models/Calendar/Missal/MissalEditionSelection.php`  | NEW: the governing/effective edition pair and whether they differ             |
| `src/Handlers/CalendarHandler.php`                       | reads the effective edition and reports a substitution in `Messages`          |
| `src/Handlers/EventsHandler.php`                         | reads the effective edition's own files instead of hard-coded paths           |

---

### Task 1: Rebase `AmbrosianMissal` file paths on the missals folder

`JsonData::AMBROSIAN_SANCTORALE_FOLDER` is hard-wired to `.../ambrosian/missals/propriumdesanctis_2024`, and
`AmbrosianMissal` builds every path on top of it. That works only while one edition exists: whoever later adds 1976
data would write `'/propriumdesanctis_1976.json'` into the map and get
`.../propriumdesanctis_2024/propriumdesanctis_1976.json`.

Rebase onto `JsonData::AMBROSIAN_MISSALS_FOLDER` with folder-qualified relative paths, exactly as `RomanMissal` does
(`JsonData::MISSALS_FOLDER->path() . '/propriumdesanctis_US_2011/lectionary/'`). The resulting absolute paths are
byte-identical, so the existing tests are the safety net.

**Files:**

- Modify: `src/Enum/AmbrosianMissal.php` (`$jsonFiles`, `$i18nPath`, `getSanctoraleFileName()`, `getSanctoraleI18nFilePath()`)
- Test: `phpunit_tests/Enum/AmbrosianMissalTest.php` (existing, unchanged)

**Interfaces:**

- Consumes: nothing.
- Produces: `AmbrosianMissal::$jsonFiles` and `$i18nPath` values are now relative to the **missals** folder and include
  the edition's own folder segment. Task 2 and Task 7 add entries in this shape.

- [ ] **Step 1: Run the existing tests to record the green baseline**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
vendor/bin/phpunit phpunit_tests/Enum/AmbrosianMissalTest.php phpunit_tests/Enum/JsonDataAmbrosianSanctoralePathTest.php
```

Expected: PASS. These assert the absolute paths this refactor must preserve.

- [ ] **Step 2: Rebase the two path maps**

In `src/Enum/AmbrosianMissal.php`, replace the two map bodies:

```php
    private static array $jsonFiles = [
        self::EDITIO_TYPICA_2024 => '/propriumdesanctis_2024/propriumdesanctis_2024.json'
    ];
```

```php
    private static array $i18nPath = [
        self::EDITIO_TYPICA_2024 => '/propriumdesanctis_2024/i18n/'
    ];
```

- [ ] **Step 3: Point both accessors at the missals folder**

In the same file, in `getSanctoraleFileName()` and `getSanctoraleI18nFilePath()`, change the base from
`JsonData::AMBROSIAN_SANCTORALE_FOLDER->path()` to `JsonData::AMBROSIAN_MISSALS_FOLDER->path()`:

```php
        return is_string(self::$jsonFiles[$missal_id])
            ? JsonData::AMBROSIAN_MISSALS_FOLDER->path() . self::$jsonFiles[$missal_id]
            : false;
```

```php
        return is_string(self::$i18nPath[$missal_id])
            ? JsonData::AMBROSIAN_MISSALS_FOLDER->path() . self::$i18nPath[$missal_id]
            : false;
```

Add this note to each map's docblock, so the constraint survives the next edition:

```php
     * Paths are relative to {@see JsonData::AMBROSIAN_MISSALS_FOLDER} and MUST carry the edition's own folder
     * segment. They used to be relative to `AMBROSIAN_SANCTORALE_FOLDER`, which is hard-wired to
     * `propriumdesanctis_2024` — fine while one edition existed, and silently wrong for the second, whose file
     * would have resolved inside the 2024 edition's folder. `RomanMissal` has always been keyed this way.
```

- [ ] **Step 4: Run the tests to verify the paths are unchanged**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
vendor/bin/phpunit phpunit_tests/Enum/AmbrosianMissalTest.php phpunit_tests/Enum/JsonDataAmbrosianSanctoralePathTest.php phpunit_tests/Enum/MissalCatalogTest.php
```

Expected: PASS, same counts as Step 1.

- [ ] **Step 5: Static analysis and style**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
composer analyse && composer lint
```

Expected: no errors.

- [ ] **Step 6: Commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
git add src/Enum/AmbrosianMissal.php
git commit -m "refactor(ambrosian): key missal paths off the missals folder, not the 2024 edition folder

AMBROSIAN_SANCTORALE_FOLDER is hard-wired to propriumdesanctis_2024, so a
second edition's file would have resolved inside the 2024 edition's folder.
Absolute paths are unchanged; the existing path tests pin that.

Refs #957"
```

---

### Task 2: Coin `EDITIO_TYPICA_1976`

**Files:**

- Modify: `src/Enum/AmbrosianMissal.php` (class docblock, new constant, `$values`, `$names`, `$jsonFiles`, `$i18nPath`,
  `$yearLimits`, `$editioTypicaIds`)
- Modify: `src/Models/Calendar/Missal/AmbrosianMissalResolver.php` (docblock only)
- Test: `phpunit_tests/Enum/AmbrosianMissalTest.php`
- Test: `phpunit_tests/Enum/MissalCatalogTest.php`

**Interfaces:**

- Consumes: the map shape from Task 1.
- Produces: `AmbrosianMissal::EDITIO_TYPICA_1976` (string `'EDITIO_TYPICA_1976'`);
  `AmbrosianMissal::getMissalIds()` now returns `['EDITIO_TYPICA_2024', 'EDITIO_TYPICA_1976']` (declaration order);
  `AmbrosianMissal::getYearLimits('EDITIO_TYPICA_1976')` returns `['since_year' => 1976, 'until_year' => 2024]`;
  `AmbrosianMissal::getSanctoraleFileName('EDITIO_TYPICA_1976')` returns `false`. Tasks 3, 4 and 7 rely on all four.

- [ ] **Step 1: Write the failing tests**

Append to `phpunit_tests/Enum/AmbrosianMissalTest.php`, inside the class:

```php
    public function testEditio1976IsDeclaredAndTypical(): void
    {
        self::assertTrue(AmbrosianMissal::isValid(AmbrosianMissal::EDITIO_TYPICA_1976));
        self::assertTrue(AmbrosianMissal::isEditioTypica(AmbrosianMissal::EDITIO_TYPICA_1976));
        self::assertContains(AmbrosianMissal::EDITIO_TYPICA_1976, AmbrosianMissal::getMissalIds());
    }

    /**
     * `until_year` is EXCLUSIVE across this codebase: `CalendarHandler` drops a missal when
     * `Year >= until_year`, and `RomanMissal` pairs `ITALY_EDITION_1983 => until 2002` with
     * `EDITIO_TYPICA_TERTIA_2002 => since 2002`. So the first Ambrosian edition applies
     * through 2023 inclusive and hands over in 2024.
     */
    public function testEditio1976YearLimitsHandOverToEditio2024(): void
    {
        $limits = AmbrosianMissal::getYearLimits(AmbrosianMissal::EDITIO_TYPICA_1976);

        self::assertSame(1976, $limits['since_year']);
        self::assertSame(2024, $limits['until_year']);
        self::assertSame(2024, AmbrosianMissal::getYearLimits(AmbrosianMissal::EDITIO_TYPICA_2024)['since_year']);
    }

    /**
     * Coined data-less on purpose, exactly as `RomanMissal` declares EDITIO_TYPICA_1971,
     * ITALY_EDITION_2020, NETHERLANDS_EDITION_1978 and the two Canadian editions. `api_path` is
     * the field that carries the "no sanctorale data at all" signal.
     */
    public function testEditio1976ShipsNoDataAndIsAdvertisedAsSuch(): void
    {
        self::assertFalse(AmbrosianMissal::getSanctoraleFileName(AmbrosianMissal::EDITIO_TYPICA_1976));
        self::assertFalse(AmbrosianMissal::getSanctoraleI18nFilePath(AmbrosianMissal::EDITIO_TYPICA_1976));

        $metadata = AmbrosianMissal::produceMetadata(false);
        self::assertArrayHasKey(AmbrosianMissal::EDITIO_TYPICA_1976, $metadata);
        self::assertNull($metadata[AmbrosianMissal::EDITIO_TYPICA_1976]['api_path']);
        self::assertSame([], $metadata[AmbrosianMissal::EDITIO_TYPICA_1976]['locales']);
        self::assertSame(1976, $metadata[AmbrosianMissal::EDITIO_TYPICA_1976]['year_published']);
    }
```

- [ ] **Step 2: Run them to verify they fail**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
vendor/bin/phpunit phpunit_tests/Enum/AmbrosianMissalTest.php
```

Expected: FAIL with `Undefined constant LiturgicalCalendar\Api\Enum\AmbrosianMissal::EDITIO_TYPICA_1976`.

- [ ] **Step 3: Declare the constant**

In `src/Enum/AmbrosianMissal.php`, immediately **above** `EDITIO_TYPICA_2024`:

```php
    /**
     * The I edizione, italiana of the Ambrosian Missal, promulgated by Card. Giovanni Colombo in 1976 together
     * with the new Ambrosian Calendar — see the "Edition history" table on this class's docblock.
     *
     * The authority for this edition is the ITALIAN text. Its 1981 Latin counterpart (*Missale Ambrosianum*) is
     * a translation with identical contents, so it is an i18n sidecar, not a `missal_id` of its own; and the
     * 1990 Martini *aggiornamento* is a revised reprint WITHIN this edition, which is precisely why 2024 is the
     * SECOND edition. Neither is coined.
     *
     * Declared with no source files, exactly as {@see \LiturgicalCalendar\Api\Enum\RomanMissal} declares
     * `EDITIO_TYPICA_1971`, `ITALY_EDITION_2020`, `NETHERLANDS_EDITION_1978` and the two Canadian editions.
     * `MissalMetadataMap::buildIndex()` skips any id whose structure file is absent, so this edition cannot
     * appear under `/missals/ambrosian` until it has data, and `produceMetadata()` gives it a null `api_path`.
     */
    public const EDITIO_TYPICA_1976 = 'EDITIO_TYPICA_1976';
```

- [ ] **Step 4: Add it to every map**

```php
    private static array $values = [ 'EDITIO_TYPICA_2024', 'EDITIO_TYPICA_1976' ];
```

```php
    private static array $names = [
        self::EDITIO_TYPICA_2024 => 'Messale Ambrosiano, Editio 2024',
        self::EDITIO_TYPICA_1976 => 'Messale Ambrosiano, I edizione italiana, 1976'
    ];
```

```php
    private static array $jsonFiles = [
        self::EDITIO_TYPICA_2024 => '/propriumdesanctis_2024/propriumdesanctis_2024.json',
        self::EDITIO_TYPICA_1976 => false
    ];
```

```php
    private static array $i18nPath = [
        self::EDITIO_TYPICA_2024 => '/propriumdesanctis_2024/i18n/',
        self::EDITIO_TYPICA_1976 => false
    ];
```

```php
    private static array $yearLimits = [
        self::EDITIO_TYPICA_2024 => [ 'since_year' => 2024 ],
        self::EDITIO_TYPICA_1976 => [ 'since_year' => 1976, 'until_year' => 2024 ]
    ];
```

```php
    private static array $editioTypicaIds = [ self::EDITIO_TYPICA_2024, self::EDITIO_TYPICA_1976 ];
```

- [ ] **Step 5: Correct the two stale docblock claims**

In the class docblock of `src/Enum/AmbrosianMissal.php`, delete this sentence from the opening paragraph:

```text
 * Mirrors the shape of {@see \LiturgicalCalendar\Api\Enum\RomanMissal}. Only the 2024 edition is defined for
 * now; the 1976 edition (with its own `since_year`/`until_year` historical gating) is deferred to a later plan.
```

and replace it with:

```text
 * Mirrors the shape of {@see \LiturgicalCalendar\Api\Enum\RomanMissal}. Both post-conciliar editions are
 * declared: `EDITIO_TYPICA_1976` (data-less) and `EDITIO_TYPICA_2024`.
```

Then, near the end of that docblock, replace:

```text
 * The only two ids this rite will ever need for the editions known today are `EDITIO_TYPICA_1976`
 * and `EDITIO_TYPICA_2024`. Coining the former and its per-missal lectionary data is tracked by
 * issue #957; this class declares only 2024 for now.
```

with:

```text
 * The only two ids this rite will ever need for the editions known today are `EDITIO_TYPICA_1976`
 * and `EDITIO_TYPICA_2024`, and both are now declared (#957). The 1976 edition ships no source data
 * yet; see {@see \LiturgicalCalendar\Api\Models\Calendar\Missal\AmbrosianMissalResolver} for how a
 * year it governs is served in the meantime.
```

In `src/Models/Calendar/Missal/AmbrosianMissalResolver.php`, replace this docblock sentence:

```text
 * Only the 2024 edition is defined so far ({@see AmbrosianMissal}); every
 * in-range year resolves to it. The 1976 edition and its `since_year`/
 * `until_year` historical split are deferred to a later plan.
```

with:

```text
 * Both post-conciliar editions are declared ({@see AmbrosianMissal}), so a
 * year resolves to whichever one governs it. Task 3 of this plan makes that
 * lookup read the declared year windows.
```

- [ ] **Step 6: Update the catalog test that asserts a one-element id list**

In `phpunit_tests/Enum/MissalCatalogTest.php`, in `testTheAmbrosianSourceKnowsTheAmbrosianMissal()`, replace:

```php
        self::assertSame(['EDITIO_TYPICA_2024'], $source->getMissalIds());
```

with:

```php
        self::assertSame(['EDITIO_TYPICA_2024', 'EDITIO_TYPICA_1976'], $source->getMissalIds());
        self::assertSame('AMBROSIAN', $source->regionFor('EDITIO_TYPICA_1976'));
```

**The `/missals/ambrosian` listing itself does NOT change.** `MissalMetadataMap::jsonSerialize()` always reads
`$this->missals` (the folder-glob result), never `$allMissals` (from `produceMetadata()`) — `includeEmpty` is
consulted only by `getMissalRegions()` and `getMissalYears()`, which feed parameter validation, not the listing.
So `buildIndex()` skipping an id whose structure file is absent means `EDITIO_TYPICA_1976` cannot appear in the
listing, with or without `include_empty`.

**A response DOES change here, and it is a narrower one than that.** `GET /missals/ambrosian?year=1976` used to
be rejected with a 400 (`Invalid value 1976 for param year`), because `getMissalYears()` drew only from
`$missals`. With `include_empty=true` added to the request, `getMissalYears()` now draws from `$allMissals`
instead, so `year=1976` is accepted as a valid combination — and because the listing itself is still built from
`$missals`, the response is a 200 with an empty `litcal_missals` array, not an entry for `EDITIO_TYPICA_1976`.
This matches the pre-existing behaviour for the data-less Roman editions (e.g. `EDITIO_TYPICA_1971`).

- [ ] **Step 7: Run the tests to verify they pass**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
vendor/bin/phpunit phpunit_tests/Enum/ phpunit_tests/Models/MissalsPath/
```

Expected: PASS. `MissalMetadataMapRiteTest` must still pass untouched — `buildIndex()` skips 1976 because its
structure file is `false`.

- [ ] **Step 8: Static analysis and style**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
composer analyse && composer lint
```

Expected: no errors.

- [ ] **Step 9: Commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
git add src/Enum/AmbrosianMissal.php src/Models/Calendar/Missal/AmbrosianMissalResolver.php phpunit_tests/Enum/AmbrosianMissalTest.php phpunit_tests/Enum/MissalCatalogTest.php
git commit -m "feat(ambrosian): coin EDITIO_TYPICA_1976, the first post-conciliar edition

Declared data-less, as RomanMissal already declares five editions, so it
cannot appear under /missals/ambrosian until it has source data. until_year
is exclusive, so 1976 applies through 2023 and hands over in 2024.

Refs #957"
```

---

### Task 3: Make `AmbrosianMissalResolver::resolve()` year-aware

**Files:**

- Modify: `src/Models/Calendar/Missal/AmbrosianMissalResolver.php`
- Test: `phpunit_tests/Models/Calendar/Missal/AmbrosianMissalResolverTest.php`

**Interfaces:**

- Consumes: `AmbrosianMissal::getMissalIds()`, `AmbrosianMissal::getYearLimits()` from Task 2.
- Produces: `AmbrosianMissalResolver::resolve(int $year): list<string>` returning exactly one id — the edition whose
  `[since_year, until_year)` window contains `$year`. Also a private
  `AmbrosianMissalResolver::editionsBySinceYear(): array<string,array{since_year:int,until_year?:int}>`, ascending,
  which Task 4 reuses.

- [ ] **Step 1: Write the failing tests**

Replace the whole body of `phpunit_tests/Models/Calendar/Missal/AmbrosianMissalResolverTest.php` class with:

```php
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
```

- [ ] **Step 2: Run them to verify they fail**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
vendor/bin/phpunit phpunit_tests/Models/Calendar/Missal/AmbrosianMissalResolverTest.php
```

Expected: FAIL — `testResolveReturnsEditio1976ForEveryYearBefore2024` and
`testAYearBelowTheFloorFallsBackToTheEarliestEdition` get `['EDITIO_TYPICA_2024']`.

- [ ] **Step 3: Implement the year lookup**

Replace the body of `resolve()` in `src/Models/Calendar/Missal/AmbrosianMissalResolver.php` and add the private
helper:

```php
    /**
     * @return list<string>
     */
    public function resolve(int $year): array
    {
        $editions = self::editionsBySinceYear();

        foreach ($editions as $id => $limits) {
            if ($year < $limits['since_year']) {
                continue;
            }
            // `until_year` is EXCLUSIVE — the successor's `since_year` is the same number.
            if (array_key_exists('until_year', $limits) && $year >= $limits['until_year']) {
                continue;
            }

            return [$id];
        }

        $earliest = array_key_first($editions);
        if (null === $earliest) {
            throw new \LogicException('AmbrosianMissal declares no editions; AmbrosianMissalResolver cannot resolve a year.');
        }

        return [$earliest];
    }

    /**
     * Every declared Ambrosian edition with its year limits, ascending by `since_year`.
     *
     * Sorted rather than trusting declaration order, so adding an edition to `AmbrosianMissal` anywhere in its
     * maps cannot change which edition a year resolves to.
     *
     * @return array<string,array{since_year:int,until_year?:int}>
     */
    private static function editionsBySinceYear(): array
    {
        $editions = [];
        foreach (AmbrosianMissal::getMissalIds() as $id) {
            $editions[$id] = AmbrosianMissal::getYearLimits($id);
        }

        uasort($editions, static fn (array $a, array $b): int => $a['since_year'] <=> $b['since_year']);

        return $editions;
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
vendor/bin/phpunit phpunit_tests/Models/Calendar/Missal/AmbrosianMissalResolverTest.php
```

Expected: PASS.

- [ ] **Step 5: Static analysis and style**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
composer analyse && composer lint
```

Expected: no errors.

- [ ] **Step 6: Commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
git add src/Models/Calendar/Missal/AmbrosianMissalResolver.php phpunit_tests/Models/Calendar/Missal/AmbrosianMissalResolverTest.php
git commit -m "feat(ambrosian): resolve the missal edition from the declared year windows

resolve() no longer returns a hard-coded id; it reads since_year/until_year
from AmbrosianMissal, treating until_year as exclusive like the rest of the
codebase. A year below the rite floor is unreachable through the API but
returns the earliest edition rather than an empty list.

Refs #957"
```

---

### Task 4: Separate "which edition governs" from "which edition we hold"

**Files:**

- Create: `src/Models/Calendar/Missal/MissalEditionSelection.php`
- Modify: `src/Models/Calendar/Missal/AmbrosianMissalResolver.php` (add `selectSanctoraleEdition()`)
- Test: `phpunit_tests/Models/Calendar/Missal/AmbrosianMissalResolverTest.php`

**Interfaces:**

- Consumes: `resolve()` and `editionsBySinceYear()` from Task 3;
  `AmbrosianMissal::getSanctoraleFileName()` from Task 2.
- Produces:
  - `MissalEditionSelection` with public readonly `string $requested`, `string $effective`, and
    `isSubstituted(): bool`.
  - `AmbrosianMissalResolver::selectSanctoraleEdition(int $year): MissalEditionSelection`.
  Tasks 5 and 6 call `selectSanctoraleEdition()` and read `->effective`, `->requested` and `->isSubstituted()`.

- [ ] **Step 1: Write the failing tests**

Append to `phpunit_tests/Models/Calendar/Missal/AmbrosianMissalResolverTest.php`, inside the class:

```php
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
```

Add the import at the top of the file:

```php
use LiturgicalCalendar\Api\Models\Calendar\Missal\MissalEditionSelection;
```

Note: the test file's namespace is `LiturgicalCalendar\Tests\Models\Calendar\Missal`, so `MissalEditionSelection`
must be imported explicitly even though the last segment matches.

- [ ] **Step 2: Run them to verify they fail**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
vendor/bin/phpunit phpunit_tests/Models/Calendar/Missal/AmbrosianMissalResolverTest.php
```

Expected: FAIL with `Call to undefined method ...AmbrosianMissalResolver::selectSanctoraleEdition()`.

- [ ] **Step 3: Create the value object**

Create `src/Models/Calendar/Missal/MissalEditionSelection.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Missal;

/**
 * The pair of Missal editions a sanctorale read involves: the one that GOVERNS the requested year, and
 * the one whose data is actually read.
 *
 * These are two different questions, and conflating them is what let the API build every Ambrosian
 * calendar from 1976 onward out of the 2024 sanctorale without saying so. `$requested` answers "which
 * edition is in force for this year" — a fact about liturgical history that does not depend on what this
 * codebase happens to ship. `$effective` answers "which edition do we hold a proper for" — a fact about
 * this repository's source data, which changes as data lands.
 *
 * When the two differ the caller is expected to SAY SO (see `CalendarHandler::addAmbrosianSanctoraleToCalendar()`),
 * rather than silently serve the substitute.
 */
final readonly class MissalEditionSelection
{
    public function __construct(
        public string $requested,
        public string $effective
    ) {
    }

    public function isSubstituted(): bool
    {
        return $this->requested !== $this->effective;
    }
}
```

- [ ] **Step 4: Add `selectSanctoraleEdition()` to the resolver**

Add to `src/Models/Calendar/Missal/AmbrosianMissalResolver.php`, after `resolve()`, and add
`use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;` to the file's imports:

```php
    /**
     * The edition that governs `$year`, paired with the edition whose sanctorale is actually read.
     *
     * `resolve()` stays a pure statement about which edition is in force. This method adds the separate
     * question of whether this codebase holds that edition's proper, and where it does not, walks FORWARD
     * to the nearest later edition that ships one. Forward, not backward: a later edition is a revision of
     * this rite's own proper and is the closest thing held to the missing one, whereas walking backward
     * reaches for an edition that is itself absent.
     *
     * The day the missing edition's data lands, this method simply stops substituting — no caller changes.
     *
     * @throws ServiceUnavailableException if neither the governing edition nor any later one ships a sanctorale
     */
    public function selectSanctoraleEdition(int $year): MissalEditionSelection
    {
        $requested = $this->resolve($year)[0];

        if (false !== AmbrosianMissal::getSanctoraleFileName($requested)) {
            return new MissalEditionSelection($requested, $requested);
        }

        $editions       = self::editionsBySinceYear();
        $requestedSince = $editions[$requested]['since_year'];

        foreach ($editions as $id => $limits) {
            if ($limits['since_year'] <= $requestedSince) {
                continue;
            }
            if (false !== AmbrosianMissal::getSanctoraleFileName($id)) {
                return new MissalEditionSelection($requested, $id);
            }
        }

        throw new ServiceUnavailableException(sprintf(
            'No Ambrosian Missal edition with sanctorale data is available for the year %d: the %s governs it and ships none, and neither does any later edition.',
            $year,
            AmbrosianMissal::getName($requested)
        ));
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
vendor/bin/phpunit phpunit_tests/Models/Calendar/Missal/AmbrosianMissalResolverTest.php
```

Expected: PASS, 6 tests.

- [ ] **Step 6: Static analysis and style**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
composer analyse && composer lint
```

Expected: no errors.

- [ ] **Step 7: Commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
git add src/Models/Calendar/Missal/MissalEditionSelection.php src/Models/Calendar/Missal/AmbrosianMissalResolver.php phpunit_tests/Models/Calendar/Missal/AmbrosianMissalResolverTest.php
git commit -m "feat(ambrosian): separate the governing edition from the one we hold data for

resolve() stays a pure statement about which edition is in force.
selectSanctoraleEdition() adds the separate question of whether the proper is
held, walking forward to the nearest later edition that ships one and
reporting the substitution through MissalEditionSelection.

Refs #957"
```

---

### Task 5: Report the substitution on `/calendar`

**Files:**

- Modify: `src/Handlers/CalendarHandler.php` (`addAmbrosianSanctoraleToCalendar()`, around line 1066)
- Test: `phpunit_tests/Handlers/CalendarHandlerAmbrosianSanctoraleLoadTest.php`

**Interfaces:**

- Consumes: `AmbrosianMissalResolver::selectSanctoraleEdition()` and `MissalEditionSelection` from Task 4.
- Produces: one additional `$this->Messages[]` entry when a substitution happened. No signature changes.

**Precondition:** the `.env.local` step in the Prerequisites section MUST be done, or this task's test skips silently.

- [ ] **Step 1: Write the failing tests**

Append to `phpunit_tests/Handlers/CalendarHandlerAmbrosianSanctoraleLoadTest.php`, inside the class. Note that
`runSanctoraleStep()` already returns `[$cal, $resultMessages]` and already accepts a year:

```php
    /**
     * A pre-2024 year is governed by the 1976 edition, whose proper this API does not hold. The sanctorale is
     * still served — from the 2024 edition — but the response must SAY that, rather than present the 2024
     * proper as though it were the one in force for that year.
     */
    public function testAPre2024YearReportsThatTheSanctoraleCameFromALaterEdition(): void
    {
        [, $messages] = $this->runSanctoraleStep(1990);

        $substitutionMessages = array_values(array_filter(
            $messages,
            static fn (string $m): bool => str_contains($m, 'I edizione italiana, 1976')
        ));

        self::assertCount(1, $substitutionMessages, 'Expected exactly one sanctorale-substitution message.');
        self::assertStringContainsString('1990', $substitutionMessages[0]);
        self::assertStringContainsString('Editio 2024', $substitutionMessages[0]);
    }

    public function testAPost2024YearReportsNoSubstitution(): void
    {
        [, $messages] = $this->runSanctoraleStep(2025);

        foreach ($messages as $message) {
            self::assertStringNotContainsString('1976', $message, 'A year governed by the 2024 edition must not report a substitution.');
        }
    }

    /**
     * The substitution must not change WHICH events are served — it reproduces exactly what this API already
     * returned for pre-2024 years, and adds the message. If this ever fails, the substitution changed output.
     */
    public function testTheSubstitutedYearStillCarriesTheComuneSanctorale(): void
    {
        [$cal] = $this->runSanctoraleStep(1990);

        $stAmbrose = $cal->getLiturgicalEvent('StAmbrose');
        self::assertNotNull($stAmbrose, 'Expected `StAmbrose` to still be present for a substituted year.');
        self::assertSame('1990-12-07', $stAmbrose->date->format('Y-m-d'));
    }
```

- [ ] **Step 2: Run them to verify they fail**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
vendor/bin/phpunit phpunit_tests/Handlers/CalendarHandlerAmbrosianSanctoraleLoadTest.php --display-skipped
```

Expected: FAIL on `testAPre2024YearReportsThatTheSanctoraleCameFromALaterEdition` (0 substitution messages found).
If instead you see the whole class **skipped**, go back and do the Prerequisites step — a skip here is not a pass.

- [ ] **Step 3: Wire the selection into the handler**

In `src/Handlers/CalendarHandler.php`, in `addAmbrosianSanctoraleToCalendar()`, replace:

```php
        $year    = $this->CalendarParams->Year;
        $edition = ( new AmbrosianMissalResolver() )->resolve($year)[0];
```

with:

```php
        $year      = $this->CalendarParams->Year;
        $selection = ( new AmbrosianMissalResolver() )->selectSanctoraleEdition($year);
        $edition   = $selection->effective;

        if ($selection->isSubstituted()) {
            /**translators:
             * 1. Requested civil year
             * 2. Name of the Ambrosian Missal edition in force for that year
             * 3. Name of the Ambrosian Missal edition the sanctorale was actually read from
             */
            $this->Messages[] = sprintf(
                _('The sanctorale for the year %1$d was taken from the %3$s: this API does not yet hold the proper of the %2$s, which is the edition in force for that year.'),
                $year,
                AmbrosianMissal::getName($selection->requested),
                AmbrosianMissal::getName($selection->effective)
            );
        }
```

`$edition` keeps its meaning for the rest of the method — it names the edition actually read, which is what the
existing collision message should report.

- [ ] **Step 4: Run the tests to verify they pass**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
vendor/bin/phpunit phpunit_tests/Handlers/CalendarHandlerAmbrosianSanctoraleLoadTest.php --display-skipped
```

Expected: PASS, with no skips reported.

- [ ] **Step 5: Run the wider Ambrosian calendar suites for regressions**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
vendor/bin/phpunit phpunit_tests/Handlers/ phpunit_tests/Models/Calendar/ --display-skipped
```

Expected: PASS. Pre-2024 output is unchanged by design; only a message is added.

- [ ] **Step 6: Static analysis and style**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
composer analyse && composer lint
```

Expected: no errors.

- [ ] **Step 7: Commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
git add src/Handlers/CalendarHandler.php phpunit_tests/Handlers/CalendarHandlerAmbrosianSanctoraleLoadTest.php
git commit -m "feat(ambrosian): say so when the sanctorale comes from a later edition

Every Ambrosian calendar from 1976 to 2023 has been built from the 2024
sanctorale without disclosing it. The output is unchanged; a translatable
message now names the edition in force and the one actually read.

Refs #957"
```

---

### Task 6: Make `/events` honour the resolved edition

`EventsHandler::processAmbrosianSanctoraleEvents()` resolves an edition and then reads the hard-coded
`JsonData::AMBROSIAN_SANCTORALE_FILE` / `AMBROSIAN_SANCTORALE_I18N_FILE` paths, using `$edition` only inside an error
string. With one edition that is invisible; with two the resolver's answer is computed and discarded.

The defect is not observable from the response while both editions resolve to the same file, so the edition lookup is
extracted to its own seam and pinned there. That seam fails for the old constant-based code (the method does not
exist) **and** for the obvious wrong fix (`resolve()[0]`, which hands back the data-less 1976 edition for a pre-2024
year and would make `getSanctoraleFileName()` return `false`).

**Files:**

- Modify: `src/Handlers/EventsHandler.php` (`processAmbrosianSanctoraleEvents()`, around line 471; new private
  `ambrosianSanctoraleEdition()`)
- Test: `phpunit_tests/Handlers/EventsHandlerAmbrosianEditionTest.php` (create)

**Interfaces:**

- Consumes: `AmbrosianMissalResolver::selectSanctoraleEdition()` from Task 4.
- Produces: `EventsHandler::ambrosianSanctoraleEdition(): string` (private) — the id of the edition whose sanctorale
  the handler reads for `$this->EventsParams->Year`. No public signature changes.

- [ ] **Step 1: Write the failing tests**

Create `phpunit_tests/Handlers/EventsHandlerAmbrosianEditionTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\AmbrosianMissal;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\EventsHandler;
use LiturgicalCalendar\Api\Params\EventsParams;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * `processAmbrosianSanctoraleEvents()` used to resolve an edition and then read a hard-coded path, so the
 * resolver's answer was computed and thrown away (#957). Harmless while one edition was declared; silently
 * wrong from the second onward.
 *
 * Both editions resolve to the same file today, so the defect is invisible in the response. The edition
 * lookup is therefore pinned at its own seam: it must yield an edition that SHIPS a sanctorale.
 */
#[CoversClass(EventsHandler::class)]
final class EventsHandlerAmbrosianEditionTest extends AbstractHandlerTestCase
{
    /**
     * `EventsHandler::setLocale()` calls the process-global `setlocale()`, which persists across tests in
     * the same PHPUnit process — the same reset `EventsHandlerRiteRoutingTest` performs, for the same reason.
     */
    protected function tearDown(): void
    {
        setlocale(LC_ALL, 'C');
        parent::tearDown();
    }

    private function editionFor(int $year): string
    {
        $handler = new EventsHandler([], Rite::AMBROSIAN);

        $ref  = new \ReflectionClass($handler);
        $prop = $ref->getProperty('EventsParams');
        $prop->setAccessible(true);
        $prop->setValue($handler, new EventsParams(['year' => $year]));

        $method = $ref->getMethod('ambrosianSanctoraleEdition');
        $method->setAccessible(true);

        return (string) $method->invoke($handler);
    }

    /**
     * 1990 is governed by the data-less 1976 edition, so the handler must fall through to the edition whose
     * proper is actually held. `resolve()[0]` here would hand `getSanctoraleFileName()` an edition that
     * returns `false`, and the sanctorale read would blow up.
     */
    public function testThePre2024EditionLookupYieldsAnEditionThatShipsASanctorale(): void
    {
        $edition = $this->editionFor(1990);

        self::assertIsString(
            AmbrosianMissal::getSanctoraleFileName($edition),
            'The edition /events reads for 1990 must ship a sanctorale file.'
        );
        self::assertSame(AmbrosianMissal::EDITIO_TYPICA_2024, $edition);
    }

    public function testAPost2024YearUsesTheEditionInForce(): void
    {
        self::assertSame(AmbrosianMissal::EDITIO_TYPICA_2024, $this->editionFor(2025));
    }

    /**
     * End-to-end guard: a pre-2024 Ambrosian catalog still carries the comune sanctorale, so the
     * substitution did not merely stop the handler throwing by serving nothing.
     */
    public function testThePre2024AmbrosianCatalogStillCarriesTheComuneSanctorale(): void
    {
        $handler = new EventsHandler([], Rite::AMBROSIAN);
        $request = $this->requestFor('GET', '/events/ambrosian', ['Accept-Language' => 'it'])
            ->withQueryParams(['year' => '1990']);

        $response = $handler->handle($request);
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeJsonBody($response);
        self::assertArrayHasKey('litcal_events', $body);

        /** @var array<int,array<string,mixed>> $events */
        $events = $body['litcal_events'];
        self::assertContains(
            'StAmbrose',
            array_column($events, 'event_key'),
            'Expected the comune sanctorale to still be present for a pre-2024 year.'
        );
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
vendor/bin/phpunit phpunit_tests/Handlers/EventsHandlerAmbrosianEditionTest.php --display-skipped
```

Expected: FAIL on the first two tests with
`ReflectionException: Method ...EventsHandler::ambrosianSanctoraleEdition() does not exist`.
If instead the whole class is **skipped**, go back and do the Prerequisites step — a skip here is not a pass.

- [ ] **Step 3: Extract the edition lookup**

In `src/Handlers/EventsHandler.php`, add this private method immediately **after**
`processAmbrosianSanctoraleEvents()`:

```php
    /**
     * The Ambrosian Missal edition whose sanctorale is read for the request year.
     *
     * Deliberately `selectSanctoraleEdition()->effective`, not `resolve()[0]`: a year before 2024 is governed
     * by `EDITIO_TYPICA_1976`, which ships no proper, so the edition in force and the edition we hold data for
     * are not the same thing (see {@see MissalEditionSelection}).
     *
     * Unlike `CalendarHandler::addAmbrosianSanctoraleToCalendar()`, no message is emitted when the two differ:
     * `EventsHandler` has no `Messages` sink, the same structural divergence recorded elsewhere in this class.
     *
     * Extracted rather than inlined because it is the one place the defect this fixes is observable — both
     * editions currently resolve to the same file, so nothing in the response can tell a correct lookup from
     * a discarded one.
     */
    private function ambrosianSanctoraleEdition(): string
    {
        return ( new AmbrosianMissalResolver() )->selectSanctoraleEdition($this->EventsParams->Year)->effective;
    }
```

Then, in `processAmbrosianSanctoraleEvents()`, replace:

```php
        $edition = ( new AmbrosianMissalResolver() )->resolve($this->EventsParams->Year)[0];

        $MissalDataFile = JsonData::AMBROSIAN_SANCTORALE_FILE->path();
        $i18nFile       = strtr(JsonData::AMBROSIAN_SANCTORALE_I18N_FILE->path(), ['{locale}' => $this->resolveAmbrosianLocale()]);
```

with:

```php
        $edition = $this->ambrosianSanctoraleEdition();

        $MissalDataFile = AmbrosianMissal::getSanctoraleFileName($edition);
        $i18nPath       = AmbrosianMissal::getSanctoraleI18nFilePath($edition);

        if (false === $MissalDataFile || false === $i18nPath) {
            throw new ServiceUnavailableException(
                'AmbrosianMissal did not give the file or i18n path with Proprium de Sanctis data for the sanctorale from '
                . AmbrosianMissal::getName($edition)
            );
        }

        $i18nFile = $i18nPath . $this->resolveAmbrosianLocale() . '.json';
```

Verify the imports at the top of `EventsHandler.php` include all three of these, and add whichever are missing:

```php
use LiturgicalCalendar\Api\Enum\AmbrosianMissal;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Models\Calendar\Missal\MissalEditionSelection;
```

`MissalEditionSelection` is imported only for the `{@see}` in the docblock; if phpcs flags it as unused, spell the
reference fully-qualified in the docblock instead and drop the import.

Do **not** remove the `JsonData` import without checking — run
`grep -n 'JsonData::' src/Handlers/EventsHandler.php` first; other methods use it, so it stays.

- [ ] **Step 4: Run the tests to verify they pass**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
vendor/bin/phpunit phpunit_tests/Handlers/EventsHandlerAmbrosianEditionTest.php --display-skipped
```

Expected: PASS, 3 tests, no skips.

- [ ] **Step 5: Run the handler suites for regressions**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
vendor/bin/phpunit phpunit_tests/Handlers/ --display-skipped
```

Expected: PASS.

- [ ] **Step 6: Static analysis and style**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
composer analyse && composer lint
```

Expected: no errors.

- [ ] **Step 7: Commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
git add src/Handlers/EventsHandler.php phpunit_tests/Handlers/EventsHandlerAmbrosianEditionTest.php
git commit -m "fix(events): read the resolved Ambrosian edition's files, not hard-coded paths

processAmbrosianSanctoraleEvents() resolved an edition and then discarded it,
reading JsonData::AMBROSIAN_SANCTORALE_FILE regardless. Invisible with one
declared edition, silently wrong from the second onward.

Refs #957"
```

---

### Task 7: Move the Ambrosian lectionary onto the per-missal seam

`MissalSource::getLectionaryFilePath()` is already keyed per missal; `AmbrosianMissalSource` returns a hard-coded
`false`. The renewed Lezionario was published in 2008, between the two editions, so this rite genuinely needs the
per-missal seam rather than one corpus per rite.

**Files:**

- Modify: `src/Enum/AmbrosianMissal.php` (add `$lectionaryPath` and `getLectionaryFilePath()`)
- Modify: `src/Enum/AmbrosianMissalSource.php` (delegate `getLectionaryFilePath()`)
- Test: `phpunit_tests/Enum/AmbrosianMissalTest.php`
- Test: `phpunit_tests/Enum/MissalCatalogTest.php`

**Interfaces:**

- Consumes: the folder-relative path convention from Task 1; both edition ids from Task 2.
- Produces: `AmbrosianMissal::getLectionaryFilePath(string $missal_id): string|false`, throwing `ValidationException`
  for an unknown id — the same contract as `getSanctoraleFileName()` and as `RomanMissal::getLectionaryFilePath()`.

- [ ] **Step 1: Write the failing tests**

Append to `phpunit_tests/Enum/AmbrosianMissalTest.php`, inside the class:

```php
    /**
     * Both editions map to `false` today: this rite ships no lectionary data yet. What matters is that the
     * lookup is now PER MISSAL, so landing the 2008 Lezionario against the 2024 edition is one map entry plus
     * data files — `MissalsHandler::resolveSanctoraleTarget()` flips `readings_tier` to 'missal' by itself.
     */
    public function testGetLectionaryFilePathIsDeclaredPerEdition(): void
    {
        self::assertFalse(AmbrosianMissal::getLectionaryFilePath(AmbrosianMissal::EDITIO_TYPICA_1976));
        self::assertFalse(AmbrosianMissal::getLectionaryFilePath(AmbrosianMissal::EDITIO_TYPICA_2024));
    }

    public function testGetLectionaryFilePathRejectsInvalidId(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid missal_id: nope');
        AmbrosianMissal::getLectionaryFilePath('nope');
    }
```

- [ ] **Step 2: Run them to verify they fail**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
vendor/bin/phpunit phpunit_tests/Enum/AmbrosianMissalTest.php
```

Expected: FAIL with `Call to undefined method ...AmbrosianMissal::getLectionaryFilePath()`.

- [ ] **Step 3: Add the map and the accessor**

In `src/Enum/AmbrosianMissal.php`, add after `$i18nPath`:

```php
    /**
     * An associative array of the lectionary directory paths, where the key is the value of an Ambrosian Missal
     * constant. Mirrors {@see \LiturgicalCalendar\Api\Enum\RomanMissal::$lectionaryPath}, and paths are relative
     * to {@see JsonData::AMBROSIAN_MISSALS_FOLDER} including the edition's own folder segment.
     *
     * Both entries are `false`: no Ambrosian lectionary data ships yet. The map exists anyway because for THIS
     * rite the lectionary is genuinely per-edition — the renewed Lezionario appeared in 2008, between the two
     * editions — so it cannot be a per-rite constant the way the Roman `sanctorum` corpus is. See
     * {@see \LiturgicalCalendar\Api\Enum\AmbrosianMissalSource::riteLectionaryFolder()}, which stays `false`
     * and must never fall back to the Roman corpus (101 of the 254 Ambrosian event_keys collide with Roman
     * lectionary keys).
     *
     * @static
     * @var array<string,string|false>
     * @see \LiturgicalCalendar\Api\Enum\AmbrosianMissal::getLectionaryFilePath()
     */
    private static array $lectionaryPath = [
        self::EDITIO_TYPICA_2024 => false,
        self::EDITIO_TYPICA_1976 => false
    ];
```

and add the accessor after `getSanctoraleI18nFilePath()`:

```php
    /**
     * Gets the path to the lectionary directory for the given Ambrosian Missal.
     *
     * @param string $missal_id the id of the Ambrosian Missal
     * @return string|false the path to the lectionary directory, or false if this edition ships no lectionary data
     * @throws ValidationException if missal_id is not valid
     */
    public static function getLectionaryFilePath(string $missal_id): string|false
    {
        if (false === self::isValid($missal_id)) {
            throw new ValidationException('Invalid missal_id: ' . $missal_id);
        }
        return is_string(self::$lectionaryPath[$missal_id])
            ? JsonData::AMBROSIAN_MISSALS_FOLDER->path() . self::$lectionaryPath[$missal_id]
            : false;
    }
```

- [ ] **Step 4: Delegate from the source wrapper**

In `src/Enum/AmbrosianMissalSource.php`, replace the whole `getLectionaryFilePath()` method (docblock included) with:

```php
    /**
     * Declared per edition on {@see AmbrosianMissal::$lectionaryPath}, not hard-coded here: the Ambrosian
     * lectionary genuinely varies by edition (the renewed Lezionario is from 2008, between the 1976 and 2024
     * editions), which is exactly the case the per-missal seam on {@see MissalSource} exists for. Both
     * editions map to `false` today, so behaviour is unchanged; the id is still validated first, so an
     * unknown id fails the same way it does everywhere else on this interface.
     */
    public function getLectionaryFilePath(string $missalId): string|false
    {
        return AmbrosianMissal::getLectionaryFilePath($missalId);
    }
```

Then update this class's docblock: replace

```text
 * Every Ambrosian edition currently declared is a typical edition of the Ambrosian rite, and none
 * ships a lectionary — `/lectionary/ambrosian/sanctorale` reports that absence honestly, and this
 * change does not invent readings.
```

with

```text
 * Every Ambrosian edition currently declared is a typical edition of the Ambrosian rite, and none
 * ships a lectionary yet — `/lectionary/ambrosian/sanctorale` reports that absence honestly, and
 * nothing here invents readings. The lectionary lookup is nonetheless declared PER EDITION on
 * {@see AmbrosianMissal}, because for this rite it varies by edition (#957).
```

- [ ] **Step 5: Extend the catalog test to cover both editions**

In `phpunit_tests/Enum/MissalCatalogTest.php`, replace `testTheAmbrosianMissalHasNoLectionary()` with:

```php
    public function testNoAmbrosianEditionShipsALectionaryYet(): void
    {
        $source = MissalCatalog::for(Rite::AMBROSIAN);

        foreach ($source->getMissalIds() as $id) {
            self::assertFalse($source->getLectionaryFilePath($id), "$id must not claim lectionary data it does not ship");
        }
    }
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
vendor/bin/phpunit phpunit_tests/Enum/ phpunit_tests/Handlers/MissalsHandlerTest.php phpunit_tests/Handlers/MissalsRiteRoutingTest.php
```

Expected: PASS. `MissalSourceWrappersTest::testAmbrosianGetLectionaryFilePathIsFalseForAValidId` and
`testGetLectionaryFilePathRejectsAnUnknownIdInBothRites` must still pass untouched — the contract is identical.

- [ ] **Step 7: Static analysis and style**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
composer analyse && composer lint
```

Expected: no errors.

- [ ] **Step 8: Commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
git add src/Enum/AmbrosianMissal.php src/Enum/AmbrosianMissalSource.php phpunit_tests/Enum/AmbrosianMissalTest.php phpunit_tests/Enum/MissalCatalogTest.php
git commit -m "feat(ambrosian): declare the lectionary per edition instead of hard-coding false

The renewed Lezionario is from 2008, between the 1976 and 2024 editions, so
this rite's lectionary is genuinely per-edition. Both entries are still false
— no data ships — but landing the 2008 Lezionario is now one map entry.

Refs #957"
```

---

### Task 8: Pin the no-national-tier invariant, and verify the whole plan

The Ambrosian rite has no national tier and never will: every edition is rite-level. That makes the
`national_calendar` branch of `OpenFgaAuthorizationMiddleware::forMissals()` and of `ChangeResource::missal()`
unreachable for this rite — but only for as long as every declared Ambrosian id is a typical edition. Assert it, so
the day someone coins a non-typical Ambrosian id the test says so instead of a change request being filed against a
national calendar that does not exist.

**Files:**

- Test: `phpunit_tests/Enum/MissalCatalogTest.php`

**Interfaces:**

- Consumes: `MissalCatalog::for(Rite::AMBROSIAN)->getMissalIds()` and `->isEditioTypica()`.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the test**

Append to `phpunit_tests/Enum/MissalCatalogTest.php`, inside the class:

```php
    /**
     * There is no Ambrosian equivalent of `US_2011` or `IT_1983`: the Italian edition IS the authority for this
     * rite, its Latin counterpart is a translation, and no bishops' conference adapts it. So every declared
     * Ambrosian id must be a typical edition — and while that holds, the `national_calendar` branch of
     * `OpenFgaAuthorizationMiddleware::forMissals()` and of `ChangeResource::missal()` is unreachable for this
     * rite.
     *
     * The day someone coins a non-typical Ambrosian id, this test fails rather than the middleware quietly
     * filing a change request against an Ambrosian national calendar that does not exist.
     */
    public function testEveryDeclaredAmbrosianEditionIsTypicalSoTheRiteHasNoNationalTier(): void
    {
        $source = MissalCatalog::for(Rite::AMBROSIAN);
        $ids    = $source->getMissalIds();

        self::assertNotSame([], $ids, 'The Ambrosian rite must declare at least one edition.');

        foreach ($ids as $id) {
            self::assertTrue(
                $source->isEditioTypica($id),
                "$id is a declared Ambrosian id that is NOT a typical edition; the Ambrosian rite has no national tier, "
                . 'so either the id is wrong or OpenFgaAuthorizationMiddleware::forMissals() now has a reachable '
                . 'national_calendar branch for this rite that nothing covers.'
            );
        }
    }
```

- [ ] **Step 2: Run it to verify it passes**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
vendor/bin/phpunit phpunit_tests/Enum/MissalCatalogTest.php
```

Expected: PASS. This one is written green on purpose — it is an invariant guard, not a change driver. Confirm it can
fail by temporarily removing `self::EDITIO_TYPICA_1976` from `AmbrosianMissal::$editioTypicaIds`, re-running (expect
FAIL), then restoring it.

- [ ] **Step 3: Run the full suite**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
composer test:quick 2>&1 | tail -40
```

Expected: PASS. Use `composer test:quick`, never a bare `--exclude-group` on the CLI: a CLI `--exclude-group`
overrides the XML config and un-fences `golden-master-generate`, which then rewrites the fixtures it is checked
against.

- [ ] **Step 4: Full static analysis, style and markdown**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
composer analyse && composer lint && composer lint:md && composer lint:missals
```

Expected: no errors. `composer lint:missals` is the gate on the missal folder conventions and on `event_key`
identity across editions — it must stay green after a new edition is declared.

- [ ] **Step 5: Commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
git add phpunit_tests/Enum/MissalCatalogTest.php
git commit -m "test(ambrosian): pin that every declared edition is typical

The rite has no national tier, which is what makes the national_calendar
branch of forMissals() unreachable for it. Assert it rather than remember it.

Refs #957"
```

- [ ] **Step 6: Push and open the pull request**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LCAPI-957-ambrosian
git push -u origin feat/957-ambrosian-editions
```

Open the PR against **`development`**, never `stable`.

## Notes for the executor

- **A skipped test is not a passing test.** Every `phpunit_tests/Handlers/*` class extends `AbstractHandlerTestCase`
  and skips its whole class without `JWT_SECRET`. Always pass `--display-skipped` when running Handler tests, and
  treat any skip in Tasks 5 and 6 as a failure to fix, not a result to accept.
- **Do not run two suites at once across worktrees.** The PHPUnit repository tests share one Postgres and TRUNCATE
  project tables; a concurrent run fails in files you never touched and looks exactly like a regression.
- **Do not `composer install` in two worktrees simultaneously.** The loser gets a `vendor/` with no
  `vendor/bin/captainhook` and can no longer commit at all.
- **One response changes on purpose, and it is not the listing.** The `/missals/ambrosian` listing is
  unchanged in every mode: `jsonSerialize()` always reads the folder-glob result, so `EDITIO_TYPICA_1976`
  never appears in it. What changes is parameter validation: `include_empty=true&year=1976` used to 400 and
  now returns 200 with an empty `litcal_missals` array, because `getMissalYears()` draws from
  `produceMetadata()` under `include_empty`. That matches the pre-existing behaviour for the data-less
  Roman editions. A diff in the listing itself, in any mode, is a regression.
- **Never mutate `jsondata/`.** No task in this plan needs to, and none should. If you find yourself wanting a
  fixture, point `Router::$apiFilePath` at a temporary copy via `ShadowProjectRootTrait` instead.
- Commits are GPG-signed. If a commit fails headlessly with a passphrase error, stop and ask the user to unlock the
  key — never disable signing and never edit `~/.gnupg`.
