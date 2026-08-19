# Inventory Covers Everything Checkable — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Grow `/validations` from 18 static items to every checkable source artifact, so a later change can make the
inventory id the only address a client ever sends.

**Architecture:** `CheckableInventory` gains a second half: per-calendar source data enumerated from
`CalendarMetadataProvider::create()`, the same builder `/calendars` uses, plus test definitions read from the
rite-partitioned tests folders. The static half is untouched. No message shape changes in this plan.

**Tech Stack:** PHP 8.4, PHPUnit 12.

**This is plan 1 of 2 for the section B spec.** It grows the inventory only, and ships useful on its own: `/validations`
becomes complete, and a client can derive its whole check list from one fetch while still speaking the legacy protocol.
Plan 2 adds the typed `target`, the tagged calendar identity, and the `Health` rewiring that consumes these ids.

## Global Constraints

- Work in the worktree `/home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-target` on branch
  `feat/806-typed-target` (PR base: `development`). **Never commit in the main checkout**
  `/home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI` — shared with other agents.
- Never use `git commit --no-verify`. Commits are GPG-signed; if signing fails, stop and ask.
- PSR-12 per `phpcs.xml`; short array syntax; 4-space indent; single quotes unless interpolating.
- PHPStan level 10 over `src` only.
- **Paths come from `JsonData` enum cases, never string literals.**
- **`CheckableItem` must never serialize its `path`.**
- **The endpoint never stats a target to decide whether to list it.** An item appears because a calendar is *registered*,
  not because a file was found. Presence stays the `exists` step's job.
- Id vocabulary is `kind:rite[:qualifier][:i18n]`, fully qualified. Ids are opaque to clients.
- **No message-shape changes in this plan.** `executeValidation`, `validateCalendar`, `executeUnitTest` are untouched.
- New schema files must declare `https://json-schema.org/draft-07/schema#` and carry no `$id` — `SchemaConventionsTest`
  enforces both.
- Spec: `docs/superpowers/specs/2026-08-20-typed-target-design.md`.

---

## File Structure

| File                                                              | Responsibility                                                    |
|-------------------------------------------------------------------|-------------------------------------------------------------------|
| `src/Models/ValidationsPath/CheckableInventory.php`               | Gains per-calendar and test enumeration alongside the static half |
| `phpunit_tests/Models/ValidationsPath/CheckableInventoryTest.php` | Extended: the new kinds, their regions, their ids                 |
| `phpunit_tests/Models/ValidationsPath/InventoryDriftTest.php`     | Extended: every registered calendar has an inventory entry        |
| `phpunit_tests/Handlers/ValidationsHandlerTest.php`               | Count assertion updated; no path may still leak                   |
| `docs/superpowers/specs/2026-08-19-checkable-inventory-design.md` | Amended: the filesystem principle, and the inventory's new reach  |

### The four new kinds

| Id form                          | Source                                   | Schema                     |
|----------------------------------|------------------------------------------|----------------------------|
| `nation:roman:IT`                | `MetadataCalendars::$national_calendars` | `NationalCalendar.json`    |
| `widerregion:roman:Europe`       | `MetadataCalendars::$wider_regions`      | `WiderRegionCalendar.json` |
| `diocese:{rite}:roma_lazio_it`   | `MetadataCalendars::$diocesan_calendars` | `DiocesanCalendar.json`    |
| `test:{rite}:StIgnatiusOfLoyola` | `jsondata/tests/{rite}/*.json`           | `LitCalTest.json`          |

Each file-kind item gets an `:i18n` folder sibling where the folder exists on disk, except tests, which have no `i18n`.

### `region` on the new kinds

`region` answers one question: *is this item specific to one nation's calendar?*

- `nation:roman:IT` → `'IT'`.
- `diocese:roman:roma_lazio_it` → its nation, `'IT'`.
- `test:roman:X` → `null`; a test definition is not nation-scoped.
- `widerregion:roman:Europe` → `null`, **and this is a known limitation to document, not to paper over.** A wider region
  applies to several nations, which a scalar cannot express. Clients already have the mapping — `/calendars` gives each
  national calendar its `wider_region` — so a client scoping to one calendar reads that field rather than relying on
  `region`. Do not invent a `nations` array to fix this; that is a wire-contract change and belongs to the section B
  plan if it is wanted at all.

---

## Task 1: National calendars and wider regions

**Files:**

- Modify: `src/Models/ValidationsPath/CheckableInventory.php`
- Test: `phpunit_tests/Models/ValidationsPath/CheckableInventoryTest.php`

**Interfaces:**

- Consumes: `CalendarMetadataProvider::create(): MetadataCalendars` (static, reads source data on every call);
  `MetadataCalendars::$national_calendars` — a list of `MetadataNationalCalendarItem`, each with public
  `string $calendar_id` (e.g. `'IT'`), `array $locales`, `array $missals`, `?string $wider_region`;
  `MetadataCalendars::$wider_regions` — a list of `MetadataWiderRegionItem`, each with public `string $name`
  (e.g. `'Europe'`), `array $locales`, `string $api_path`. Path templates
  `JsonData::NATIONAL_CALENDAR_FILE` (`{nation}`), `JsonData::NATIONAL_CALENDAR_I18N_FOLDER` (`{nation}`),
  `JsonData::WIDER_REGION_FILE` (`{wider_region}`), `JsonData::WIDER_REGION_I18N_FOLDER` (`{wider_region}`).
- Produces: inventory items with ids `nation:roman:{calendar_id}`, `nation:roman:{calendar_id}:i18n`,
  `widerregion:roman:{name}`, `widerregion:roman:{name}:i18n`.

- [ ] **Step 1: Confirm the worktree**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-target
git rev-parse --show-toplevel   # must end in -target
git branch --show-current       # expect: feat/806-typed-target
ls vendor/bin/phpunit
```

- [ ] **Step 2: Write the failing test**

Append to `phpunit_tests/Models/ValidationsPath/CheckableInventoryTest.php`:

```php
    public function testNationalCalendarsAreEnumeratedFromTheMetadataProvider(): void
    {
        $ids = array_map(static fn (CheckableItem $i): string => $i->id, CheckableInventory::all());

        // Italy is a national calendar the repository ships; if it ever stops being one, this test
        // should be updated deliberately rather than the assertion loosened.
        self::assertContains('nation:roman:IT', $ids);
        self::assertContains('nation:roman:IT:i18n', $ids);

        $italy = CheckableInventory::byId('nation:roman:IT');
        self::assertNotNull($italy);
        self::assertSame('file', $italy->kind);
        self::assertSame(Rite::ROMAN, $italy->rite);
        self::assertSame('IT', $italy->region, 'a national calendar is specific to its own nation');
        self::assertSame(LitSchema::NATIONAL, $italy->schema);
        self::assertStringContainsString('/calendars/nations/IT/IT.json', $italy->path);

        $italyI18n = CheckableInventory::byId('nation:roman:IT:i18n');
        self::assertNotNull($italyI18n);
        self::assertSame('folder', $italyI18n->kind);
        self::assertSame(LitSchema::I18N, $italyI18n->schema);
    }

    public function testWiderRegionsAreEnumeratedAndAreNotNationScoped(): void
    {
        $europe = CheckableInventory::byId('widerregion:roman:Europe');
        self::assertNotNull($europe);
        self::assertSame('file', $europe->kind);
        self::assertSame(LitSchema::WIDERREGION, $europe->schema);
        self::assertNull(
            $europe->region,
            'a wider region spans several nations, which the scalar region cannot express; '
                . 'clients scope it via the wider_region field on /calendars instead'
        );

        self::assertNotNull(CheckableInventory::byId('widerregion:roman:Europe:i18n'));
    }

    public function testEveryEnumeratedItemStillHidesItsPath(): void
    {
        foreach (CheckableInventory::all() as $item) {
            self::assertStringNotContainsString('jsondata', json_encode($item, JSON_THROW_ON_ERROR));
        }
    }
```

Add `use LiturgicalCalendar\Api\Enum\LitSchema;` and `use LiturgicalCalendar\Api\Enum\Rite;` to the test's imports if
they are not already present.

- [ ] **Step 3: Run the test to verify it fails**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-target
vendor/bin/phpunit phpunit_tests/Models/ValidationsPath/CheckableInventoryTest.php
```

Expected: the two new enumeration tests fail on `assertContains` / `assertNotNull` — no such ids exist yet.
`testEveryEnumeratedItemStillHidesItsPath` should pass already; that is intentional, it is a guard for later steps.

- [ ] **Step 4: Add the enumeration**

In `src/Models/ValidationsPath/CheckableInventory.php`, add these imports:

```php
use LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars;
use LiturgicalCalendar\Api\Services\CalendarMetadataProvider;
```

Change `all()` to merge the new source, and add a memoized provider accessor:

```php
    /** @var MetadataCalendars|null */
    private static ?MetadataCalendars $metadata = null;

    /**
     * The calendar index, from the same builder that serves `/calendars`.
     *
     * Memoized for the lifetime of the request only. `CalendarMetadataProvider` deliberately re-reads
     * source data on every call because the `/data` write endpoints can mutate calendar definitions at
     * runtime; caching it here for one request keeps a single `/validations` response internally
     * consistent without outliving the write that would invalidate it.
     */
    private static function metadata(): MetadataCalendars
    {
        return self::$metadata ??= CalendarMetadataProvider::create();
    }

    /** @return list<CheckableItem> */
    public static function all(): array
    {
        if (null === self::$items) {
            self::$items = array_merge(
                self::derivedRomanSanctorale(),
                self::explicitItems(),
                self::nationalCalendarItems(),
                self::widerRegionItems()
            );
        }

        return self::$items;
    }
```

Add the two producers:

```php
    /**
     * National calendar definitions, enumerated from the calendar index rather than listed.
     *
     * A national calendar is specific to its own nation, so `region` is its calendar id — that is what
     * lets a client scoping to one calendar keep it and drop the other nine.
     *
     * @return list<CheckableItem>
     */
    private static function nationalCalendarItems(): array
    {
        $items = [];
        foreach (self::metadata()->national_calendars as $nation) {
            $id   = $nation->calendar_id;
            $file = strtr(JsonData::NATIONAL_CALENDAR_FILE->path(), ['{nation}' => $id]);

            $items[] = new CheckableItem(
                "nation:roman:{$id}",
                'file',
                Rite::ROMAN,
                $id,
                "National calendar: {$id}",
                LitSchema::NATIONAL,
                self::STEPS,
                $file
            );

            $items[] = new CheckableItem(
                "nation:roman:{$id}:i18n",
                'folder',
                Rite::ROMAN,
                $id,
                "National calendar translations: {$id}",
                LitSchema::I18N,
                self::STEPS,
                rtrim(strtr(JsonData::NATIONAL_CALENDAR_I18N_FOLDER->path(), ['{nation}' => $id]), '/')
            );
        }

        return $items;
    }

    /**
     * Wider region definitions.
     *
     * `region` is null: a wider region spans several nations, which a scalar cannot express. Clients
     * scoping to one calendar use the `wider_region` field `/calendars` already gives them, rather than
     * this field. Widening `region` into a list would be a wire-contract change, not a fix here.
     *
     * @return list<CheckableItem>
     */
    private static function widerRegionItems(): array
    {
        $items = [];
        foreach (self::metadata()->wider_regions as $region) {
            $name = $region->name;
            $file = strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => $name]);

            $items[] = new CheckableItem(
                "widerregion:roman:{$name}",
                'file',
                Rite::ROMAN,
                null,
                "Wider region: {$name}",
                LitSchema::WIDERREGION,
                self::STEPS,
                $file
            );

            $items[] = new CheckableItem(
                "widerregion:roman:{$name}:i18n",
                'folder',
                Rite::ROMAN,
                null,
                "Wider region translations: {$name}",
                LitSchema::I18N,
                self::STEPS,
                rtrim(strtr(JsonData::WIDER_REGION_I18N_FOLDER->path(), ['{wider_region}' => $name]), '/')
            );
        }

        return $items;
    }
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-target
vendor/bin/phpunit phpunit_tests/Models/ValidationsPath/CheckableInventoryTest.php
```

Expected: all tests pass **except** `testItHoldsNineFilesAndNineFolders`, which asserts the old static counts and must
now fail. That failure is correct and expected — fix it in the next step rather than reverting the enumeration.

- [ ] **Step 6: Replace the fixed count assertion with a structural one**

`testItHoldsNineFilesAndNineFolders` pinned 9 and 9. Those numbers described a hand-listed inventory; the inventory is
now partly enumerated, so a fixed total would fail whenever a calendar is added — a false alarm rather than drift.
Replace that test with one that asserts the properties that still hold:

```php
    public function testEveryItemIsEitherAFileOrAFolderAndFoldersAreI18n(): void
    {
        $files = 0;
        $folders = 0;
        foreach (CheckableInventory::all() as $item) {
            if ('folder' === $item->kind) {
                ++$folders;
                self::assertStringEndsWith(':i18n', $item->id, "folder item {$item->id} is not an i18n folder");
                self::assertSame(LitSchema::I18N, $item->schema, "folder item {$item->id} must validate as i18n");
            } else {
                ++$files;
                self::assertStringEndsNotWith(':i18n', $item->id, "file item {$item->id} looks like an i18n folder");
            }
        }

        // The static half alone contributes nine of each; enumeration only adds.
        self::assertGreaterThanOrEqual(9, $files);
        self::assertGreaterThanOrEqual(9, $folders);
    }
```

- [ ] **Step 7: Run lint, analysis and the model suite**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-target
vendor/bin/phpunit phpunit_tests/Models/ValidationsPath/
composer lint
composer analyse
```

Expected: all clean. If `composer analyse` reports a path "is not a file", the PHPStan cache is shared across worktrees
and stale: `rm -rf /tmp/phpstan` and retry.

- [ ] **Step 8: Commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-target
git add src/Models/ValidationsPath/CheckableInventory.php phpunit_tests/Models/ValidationsPath/CheckableInventoryTest.php
git commit -m "feat(validations): enumerate national and wider-region source data

The inventory listed only static source data, so a client still had to
construct its own identifiers for everything per-calendar — which is the
duplication #806 exists to end.

These come from CalendarMetadataProvider, the same builder that serves
/calendars, so the two lists cannot disagree: a calendar that exists is a
calendar that is checkable.

region is the nation's own id for a national calendar, and null for a wider
region — a wider region spans several nations and a scalar cannot say so.
Clients scoping to one calendar use the wider_region field /calendars
already gives them; widening region into a list would be a wire-contract
change rather than a fix.

The fixed 9-and-9 count assertion is replaced by a structural one. Those
numbers described a hand-listed inventory; now that it is partly
enumerated, a fixed total would fail whenever a calendar is added, which is
a false alarm rather than drift.

Refs #806

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Diocesan calendars

**Files:**

- Modify: `src/Models/ValidationsPath/CheckableInventory.php`
- Test: `phpunit_tests/Models/ValidationsPath/CheckableInventoryTest.php`

**Interfaces:**

- Consumes: `MetadataCalendars::$diocesan_calendars` — a list of `MetadataDiocesanCalendarItem`, each with public
  `string $calendar_id` (e.g. `'roma_lazio_it'`), `string $diocese` (the diocese *name*), `string $nation`,
  `readonly Rite $rite`. Path templates `JsonData::DIOCESAN_CALENDAR_FILE` and
  `JsonData::AMBROSIAN_DIOCESAN_CALENDAR_FILE`, both carrying **three** placeholders — `{nation}`, `{diocese}`,
  `{diocese_name}` — plus their `*_I18N_FOLDER` siblings, which carry only `{nation}` and `{diocese}`.
- Produces: ids `diocese:{rite}:{calendar_id}` and `diocese:{rite}:{calendar_id}:i18n`.

- [ ] **Step 1: Write the failing test**

Append to `phpunit_tests/Models/ValidationsPath/CheckableInventoryTest.php`:

```php
    public function testDiocesanCalendarsAreEnumeratedUnderTheirOwnRite(): void
    {
        $roman = CheckableInventory::byId('diocese:roman:roma_lazio_it');
        self::assertNotNull($roman, 'the Diocese of Rome should be a checkable target');
        self::assertSame(Rite::ROMAN, $roman->rite);
        self::assertSame('IT', $roman->region, 'a diocesan calendar is scoped to its nation');
        self::assertSame(LitSchema::DIOCESAN, $roman->schema);
        self::assertNotNull(CheckableInventory::byId('diocese:roman:roma_lazio_it:i18n'));
    }

    /**
     * The rite is not cosmetic here: an Ambrosian diocese lives under a different path template
     * entirely, so getting it wrong produces an item pointing at a file that does not exist — which
     * the exists step would report as a failure of the data rather than of this class.
     */
    public function testAnAmbrosianDioceseResolvesToTheAmbrosianTree(): void
    {
        $ambrosian = array_values(array_filter(
            CheckableInventory::all(),
            static fn (CheckableItem $i): bool => str_starts_with($i->id, 'diocese:ambrosian:')
                && false === str_ends_with($i->id, ':i18n')
        ));

        self::assertNotEmpty($ambrosian, 'the repository ships Ambrosian dioceses; none were enumerated');

        foreach ($ambrosian as $item) {
            self::assertSame(Rite::AMBROSIAN, $item->rite);
            self::assertStringContainsString('/rite/ambrosian/calendars/dioceses/', $item->path);
            self::assertFileExists($item->path, "{$item->id} points at a file that does not exist");
        }
    }
```

Note that `assertFileExists` here is checking **this class's path construction**, not deciding whether to list the item.
The inventory still lists what is registered; the test is what verifies the constructed path is right.

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-target
vendor/bin/phpunit phpunit_tests/Models/ValidationsPath/CheckableInventoryTest.php
```

Expected: both new tests fail — `assertNotNull` on a missing id, and `assertNotEmpty` on an empty Ambrosian list.

- [ ] **Step 3: Add the enumeration**

Add `diocesanCalendarItems()` to the `all()` merge, and implement it:

```php
    /**
     * Diocesan calendar definitions.
     *
     * The rite selects the path template, not just a label: an Ambrosian diocese lives under
     * `rite/ambrosian/calendars/dioceses/`, and the file name is the diocese *name* rather than its id,
     * which is why the template carries three placeholders.
     *
     * @return list<CheckableItem>
     */
    private static function diocesanCalendarItems(): array
    {
        $items = [];
        foreach (self::metadata()->diocesan_calendars as $diocese) {
            $isAmbrosian = Rite::AMBROSIAN === $diocese->rite;

            $fileTemplate = $isAmbrosian
                ? JsonData::AMBROSIAN_DIOCESAN_CALENDAR_FILE->path()
                : JsonData::DIOCESAN_CALENDAR_FILE->path();
            $i18nTemplate = $isAmbrosian
                ? JsonData::AMBROSIAN_DIOCESAN_CALENDAR_I18N_FOLDER->path()
                : JsonData::DIOCESAN_CALENDAR_I18N_FOLDER->path();

            $replacements = [
                '{nation}'       => $diocese->nation,
                '{diocese}'      => $diocese->calendar_id,
                '{diocese_name}' => $diocese->diocese
            ];

            $items[] = new CheckableItem(
                "diocese:{$diocese->rite->value}:{$diocese->calendar_id}",
                'file',
                $diocese->rite,
                $diocese->nation,
                "Diocesan calendar: {$diocese->diocese}",
                LitSchema::DIOCESAN,
                self::STEPS,
                strtr($fileTemplate, $replacements)
            );

            $items[] = new CheckableItem(
                "diocese:{$diocese->rite->value}:{$diocese->calendar_id}:i18n",
                'folder',
                $diocese->rite,
                $diocese->nation,
                "Diocesan calendar translations: {$diocese->diocese}",
                LitSchema::I18N,
                self::STEPS,
                rtrim(strtr($i18nTemplate, $replacements), '/')
            );
        }

        return $items;
    }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-target
vendor/bin/phpunit phpunit_tests/Models/ValidationsPath/CheckableInventoryTest.php
```

Expected: OK. If `assertFileExists` fails for an Ambrosian diocese, the path template or a placeholder is wrong — fix
the construction, do not relax the assertion.

- [ ] **Step 5: Lint, analyse, commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-target
composer lint
composer analyse
git add src/Models/ValidationsPath/CheckableInventory.php phpunit_tests/Models/ValidationsPath/CheckableInventoryTest.php
git commit -m "feat(validations): enumerate diocesan calendar definitions

Adds the 32 diocesan definitions, each with its translations folder.

The rite selects the path template rather than merely labelling the item:
an Ambrosian diocese lives under rite/ambrosian/calendars/dioceses/, and
the file name is the diocese NAME while the directory is its id, which is
why the template carries three placeholders. Getting that wrong would
produce an item pointing at a file that does not exist, and the exists
step would then report it as a failure of the data rather than of this
class — so the test asserts the constructed paths resolve.

region is the diocese's nation, so a client scoped to one national calendar
keeps its own dioceses and drops the rest.

Refs #806

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Test definitions

**Files:**

- Modify: `src/Models/ValidationsPath/CheckableInventory.php`
- Test: `phpunit_tests/Models/ValidationsPath/CheckableInventoryTest.php`

**Interfaces:**

- Consumes: `JsonData::ROMAN_TESTS_FOLDER` and `JsonData::AMBROSIAN_TESTS_FOLDER`, each a directory of `*.json` test
  definitions. `LitSchema::TEST_SRC` is the schema a test definition validates against.
- Produces: ids `test:{rite}:{basename without .json}`. Tests have **no** `i18n` sibling.

- [ ] **Step 1: Write the failing test**

```php
    /**
     * A test *definition* is a source artifact: does the JSON match LitCalTest.json. That is a different
     * thing from running the test against a computed calendar, which stays a separate action.
     */
    public function testTestDefinitionsAreEnumeratedPerRite(): void
    {
        $ids = array_map(static fn (CheckableItem $i): string => $i->id, CheckableInventory::all());
        $testIds = array_values(array_filter($ids, static fn (string $id): bool => str_starts_with($id, 'test:')));

        self::assertNotEmpty($testIds, 'the repository ships test definitions; none were enumerated');

        foreach ($testIds as $id) {
            self::assertMatchesRegularExpression('/^test:(roman|ambrosian):[A-Za-z0-9_]+$/', $id);
            self::assertStringEndsNotWith(':i18n', $id, 'test definitions have no translations folder');
        }

        foreach (CheckableInventory::all() as $item) {
            if (str_starts_with($item->id, 'test:')) {
                self::assertSame('file', $item->kind);
                self::assertSame(LitSchema::TEST_SRC, $item->schema);
                self::assertNull($item->region, 'a test definition is not nation-scoped');
                self::assertFileExists($item->path, "{$item->id} points at a file that does not exist");
            }
        }
    }
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-target
vendor/bin/phpunit phpunit_tests/Models/ValidationsPath/CheckableInventoryTest.php
```

Expected: fails on `assertNotEmpty` — no `test:` ids exist.

- [ ] **Step 3: Add the enumeration**

Add `testDefinitionItems()` to the `all()` merge, and implement it:

```php
    /**
     * Test definitions, one per JSON file in each rite's tests folder.
     *
     * This item is the *definition* — does the file validate against LitCalTest.json. Running the test
     * against a computed calendar is a separate action with its own addressing, and the two must not be
     * conflated: a definition can be valid while the test it describes fails, and vice versa.
     *
     * Unlike every other kind here, a test has no `i18n` sibling.
     *
     * @return list<CheckableItem>
     */
    private static function testDefinitionItems(): array
    {
        $items = [];
        $folders = [
            Rite::ROMAN->value     => JsonData::ROMAN_TESTS_FOLDER->path(),
            Rite::AMBROSIAN->value => JsonData::AMBROSIAN_TESTS_FOLDER->path()
        ];

        foreach ($folders as $riteValue => $folder) {
            $rite = Rite::from($riteValue);
            foreach (glob(rtrim($folder, '/') . '/*.json') ?: [] as $file) {
                $name = basename($file, '.json');
                $items[] = new CheckableItem(
                    "test:{$riteValue}:{$name}",
                    'file',
                    $rite,
                    null,
                    "Liturgical test: {$name}",
                    LitSchema::TEST_SRC,
                    self::STEPS,
                    $file
                );
            }
        }

        return $items;
    }
```

Note this kind is enumerated from the filesystem rather than from the metadata index, because test definitions are not
calendars and the index does not carry them. That is reading a directory to learn what is *registered*, which is the
same thing `CalendarMetadataProvider` does for calendars — it is still not stat-ing a target to decide whether to list it.

- [ ] **Step 4: Run it to verify it passes, then lint and analyse**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-target
vendor/bin/phpunit phpunit_tests/Models/ValidationsPath/
composer lint
composer analyse
```

Expected: all clean.

- [ ] **Step 5: Commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-target
git add src/Models/ValidationsPath/CheckableInventory.php phpunit_tests/Models/ValidationsPath/CheckableInventoryTest.php
git commit -m "feat(validations): enumerate test definitions per rite

A test definition is a source artifact: does the file validate against
LitCalTest.json. Running that test against a computed calendar is a
different thing with its own addressing, and the current protocol blurs
them — tests-X validates the definition while executeUnitTest runs it.
Listing the definition here keeps the distinction explicit.

Enumerated from the rite-partitioned tests folders rather than the calendar
index, because test definitions are not calendars. That is still reading
what is registered, not stat-ing a target to decide whether to list it.

Tests are the one kind with no i18n sibling.

Refs #806

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Drift coverage for the enumerated half, and the docs

**Files:**

- Modify: `phpunit_tests/Models/ValidationsPath/InventoryDriftTest.php`
- Modify: `phpunit_tests/Handlers/ValidationsHandlerTest.php`
- Modify: `docs/superpowers/specs/2026-08-19-checkable-inventory-design.md`

**Interfaces:**

- Consumes: `CheckableInventory::all()` and `CheckableInventory::byId()`; `CalendarMetadataProvider::create()`.

- [ ] **Step 1: Write the failing drift test**

The section A drift test walks the source tree and asserts every file found has an entry. That guards the hand-listed
half. The enumerated half needs the mirror guarantee: every calendar the index reports must have an entry, so a
registered calendar that this class forgets to enumerate fails the build.

Append to `phpunit_tests/Models/ValidationsPath/InventoryDriftTest.php`:

```php
    /**
     * Every registered calendar is a checkable target.
     *
     * The other tests in this class walk the filesystem; this one walks the calendar index, because the
     * per-calendar half of the inventory is enumerated from that index rather than from disk. A diocese
     * added to source data and picked up by /calendars, but missed here, would otherwise be
     * unvalidatable by any client with no failure anywhere — which is #800 exactly.
     */
    public function testEveryRegisteredCalendarHasAnInventoryEntry(): void
    {
        $metadata = CalendarMetadataProvider::create();

        self::assertNotEmpty($metadata->national_calendars, 'no national calendars in the index');
        self::assertNotEmpty($metadata->diocesan_calendars, 'no diocesan calendars in the index');
        self::assertNotEmpty($metadata->wider_regions, 'no wider regions in the index');

        foreach ($metadata->national_calendars as $nation) {
            self::assertNotNull(
                CheckableInventory::byId("nation:roman:{$nation->calendar_id}"),
                "national calendar {$nation->calendar_id} is registered but not checkable"
            );
        }

        foreach ($metadata->diocesan_calendars as $diocese) {
            $id = "diocese:{$diocese->rite->value}:{$diocese->calendar_id}";
            self::assertNotNull(
                CheckableInventory::byId($id),
                "diocesan calendar {$diocese->calendar_id} is registered but not checkable as {$id}"
            );
        }

        foreach ($metadata->wider_regions as $region) {
            self::assertNotNull(
                CheckableInventory::byId("widerregion:roman:{$region->name}"),
                "wider region {$region->name} is registered but not checkable"
            );
        }
    }
```

Add `use LiturgicalCalendar\Api\Services\CalendarMetadataProvider;` to the test's imports.

- [ ] **Step 2: Run it — it should pass**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-target
vendor/bin/phpunit phpunit_tests/Models/ValidationsPath/InventoryDriftTest.php
```

Expected: OK. Tasks 1-3 already added every kind this asserts, so a pass here is the point — it locks in what they
built.

- [ ] **Step 3: Prove it can fail**

Temporarily comment `self::widerRegionItems()` out of the `all()` merge, re-run, and confirm the test fails naming a
wider region. Restore it, re-run, confirm green, and check `git diff -- src/` is empty before continuing. Record the
failure output. A drift test that cannot fail reads like cover.

- [ ] **Step 4: Update the handler test's count assertion**

`phpunit_tests/Handlers/ValidationsHandlerTest.php` asserts `assertCount(18, $body['litcal_validations'])`. That number
described the static inventory. Replace it with a bound plus the invariants that still hold:

```php
        $body = $this->decodeJsonBody($response);
        self::assertArrayHasKey('litcal_validations', $body);

        // The static half alone is 18; enumeration only adds, so a fixed total would fail whenever a
        // calendar is added — a false alarm rather than drift. The drift test is what pins coverage.
        self::assertGreaterThan(18, count($body['litcal_validations']));

        $ids = array_column($body['litcal_validations'], 'id');
        self::assertSame(array_unique($ids), $ids, 'advertised ids must be unique');
```

- [ ] **Step 5: Run the handler and schema suites**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-target
vendor/bin/phpunit phpunit_tests/Handlers/ValidationsHandlerTest.php phpunit_tests/Handlers/ReadonlyPathsResponseSchemaTest.php phpunit_tests/Schemas/
```

Expected: OK. `ReadonlyPathsResponseSchemaTest` validates the real response against `LitCalValidationsPath.json`; if it
fails, an enumerated item is emitting a field shape the schema rejects — most likely an id that breaks the schema's
patterns, or a `region` that is neither null nor two uppercase letters. Fix the item, not the schema, unless the schema
is genuinely wrong.

- [ ] **Step 6: Amend the section A design document**

Two statements in `docs/superpowers/specs/2026-08-19-checkable-inventory-design.md` are now false, and this branch is
the reason:

1. The scope table lists "Per-calendar items — wider regions, nations, dioceses, tests" as **out of scope**. It is now
   in scope. Move that row, and add a sentence saying the scoping was revisited in the section B design because making
   the id the only address requires the inventory to cover everything addressable.
2. The "Deliberately not doing" section says "The endpoint **does not stat the filesystem**." Replace the absolute claim
   with the principle that actually holds: it never stats a *target* to decide whether to list it — an item appears
   because its calendar is registered — while enumeration does read the index and the tests folder. Keep the reason:
   a list that quietly omitted what it could not stat would reintroduce #800's blindness.

Do not rewrite the rest of the document. Lint it:

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-target
npx --yes markdownlint-cli docs/superpowers/specs/2026-08-19-checkable-inventory-design.md
```

- [ ] **Step 7: Run the full local suite**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-target
composer test:quick
```

Use the composer script, never a bare `phpunit --exclude-group` — a CLI `--exclude-group` overrides the XML config and
un-fences the golden-master-generate group, which silently rewrites the fixtures it is checked against.

Expected: no new failures. `Routes/*` tests fail with "Could not detect API binding" because they need a live server
this worktree does not run; that is pre-existing. Check anything ambiguous against the base commit rather than guessing.

- [ ] **Step 8: Commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-target
git add phpunit_tests/Models/ValidationsPath/InventoryDriftTest.php \
        phpunit_tests/Handlers/ValidationsHandlerTest.php \
        docs/superpowers/specs/2026-08-19-checkable-inventory-design.md
git commit -m "test(validations): guard the enumerated half against drift

The section A drift test walks the source tree and asserts every file found
has an entry, which guards the hand-listed half. The enumerated half needs
the mirror guarantee: every calendar the index reports must have an entry.
A diocese added to source data and picked up by /calendars but missed here
would otherwise be unvalidatable with no failure anywhere — #800 exactly,
arrived at from the other direction.

Two fixed counts are replaced by bounds. 18, and 9-and-9, described a
hand-listed inventory; now that it is partly enumerated they would fail
whenever a calendar is added, which is a false alarm rather than drift. The
drift tests are what pin coverage, and they do it by name.

Amends the section A design document, which said per-calendar items were
out of scope and that the endpoint does not stat the filesystem. The first
was revisited deliberately in the section B design: making the id the only
address requires the inventory to cover everything addressable. The second
is narrowed to what actually mattered — it never stats a target to decide
whether to list it.

Refs #806

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Publish

**Files:** none — repository metadata only.

- [ ] **Step 1: Push**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-target
git push -u origin feat/806-typed-target
```

- [ ] **Step 2: Open the PR**

Base `development`. The body must state: that `/validations` now covers every checkable source artifact, enumerated
from the same builder that serves `/calendars`; the id vocabulary and that nothing previously published was renamed;
what `region` means for each new kind and why a wider region is `null`; that fixed counts were replaced by bounds and
why; what the new drift test guards and that it was verified by mutation; and the verification output from each task.

State plainly that **no message shape changed** in this PR — the protocol still speaks the legacy vocabulary, and the
typed target that consumes these ids is the next PR.
