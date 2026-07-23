# Rite-Partitioned Sourcedata Migration — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Partition `jsondata/sourcedata/` by liturgical rite (`rite/roman/`, `rite/ambrosian/`) as a pure,
behavior-preserving relocation, so a later milestone can attach Ambrosian diocesan calendars to dioceses that also have
Roman-rite communities.

**Architecture:** All source-data paths resolve through base-folder constants in `src/Enum/JsonDataConstants.php`
(mirrored as pure aliases in `src/Enum/JsonData.php`). Introduce two rite-base constants and re-base the four Roman
folder constants + the two Ambrosian base-folder constants; every derived constant recomposes with no edit. Physically
`git mv` the folders to match. No discovery, loader, schema, or overlay logic changes.

**Tech Stack:** PHP 8.4, PHPUnit, PHPStan level 10, phpcs (PSR-12), Composer scripts.

## Global Constraints

- **Roman + Ambrosian API output byte-identical** before/after — golden-master 9/9 is the primary gate.
- PHP >= 8.4; PHPStan level 10 clean; phpcs clean; `composer parallel-lint` clean.
- Single source of truth for paths is `JsonDataConstants.php`; `JsonData.php` cases only alias it — never give a case a literal string value.
- The Ambrosian base constants MUST re-base onto `AMBROSIAN_RITE_FOLDER`, NOT compose off `MISSALS_FOLDER` (which now lives under `rite/roman/`).
- `jsondata/world_dioceses.json` and `jsondata/schemas/` are at `jsondata/` root (not under `sourcedata/`) — they do NOT move.
- Work in an isolated git worktree with a REAL composer `vendor/` (not a symlink — see the worktree-vendor-symlink trap) and a copied `.env.local` (golden-master SKIPs without it).

---

## File Structure

**Modified:**

- `src/Enum/JsonDataConstants.php` — add `ROMAN_RITE_FOLDER` + `AMBROSIAN_RITE_FOLDER`; re-base `DECREES_FOLDER`,
  `MISSALS_FOLDER`, `LECTIONARY_FOLDER`, `CALENDARS_FOLDER`, `AMBROSIAN_TEMPORALE_FOLDER`, `AMBROSIAN_SANCTORALE_FOLDER`;
  update 61 "Evaluates to …" docblocks.
- `src/Enum/JsonData.php` — update 61 mirrored "Evaluates to …" docblocks (no code change; cases already alias the constants).
- `phpunit_tests/Enum/JsonDataTest.php` — update expected constant-value strings.
- `phpunit_tests/Enum/JsonDataAmbrosianPathTest.php` — update expected Ambrosian temporale paths.
- `phpunit_tests/Enum/JsonDataAmbrosianSanctoralePathTest.php` — update expected Ambrosian sanctorale paths.
- `phpunit_tests/Handlers/RegionalDataHandlerTest.php` — update 3 literal filesystem paths.
- `phpunit_tests/Routes/Readonly/DecreesTest.php`, `.../MissalsTest.php`, `phpunit_tests/Services/ResourceExistenceCheckerTest.php` — update path mentions in comments.

**Physically moved (via `git mv`):**

- `jsondata/sourcedata/{calendars,decrees,lectionary,missals}` → `jsondata/sourcedata/rite/roman/{...}` (Ambrosian subtree extracted first, see Task 1 Step 4).
- `jsondata/sourcedata/missals/ambrosian/{propriumdetempore,propriumdesanctis_2024}` → `jsondata/sourcedata/rite/ambrosian/missals/{...}`.

**Created:**

- `jsondata/sourcedata/rite/ambrosian/calendars/dioceses/.gitkeep` — stable empty scan root for the next milestone.

---

## Task 1: Atomic rite-partition (constants + folder move + functional tests)

This is a single atomic unit: constants, folder move, and constant-value tests must land in one commit, because any
intermediate state (constants pointing at not-yet-moved folders, or vice versa) breaks file resolution. TDD order: red
the value-tests first, make them green via constants, then move folders to make the file-reading suite green.

**Files:**

- Modify: `src/Enum/JsonDataConstants.php`
- Modify: `phpunit_tests/Enum/JsonDataTest.php`
- Modify: `phpunit_tests/Enum/JsonDataAmbrosianPathTest.php`
- Modify: `phpunit_tests/Enum/JsonDataAmbrosianSanctoralePathTest.php`
- Modify: `phpunit_tests/Handlers/RegionalDataHandlerTest.php`
- Move: the folders listed in File Structure
- Create: `jsondata/sourcedata/rite/ambrosian/calendars/dioceses/.gitkeep`

**Interfaces:**

- Consumes: nothing from earlier tasks.
- Produces: new constant `JsonDataConstants::ROMAN_RITE_FOLDER = 'jsondata/sourcedata/rite/roman'` and
  `JsonDataConstants::AMBROSIAN_RITE_FOLDER = 'jsondata/sourcedata/rite/ambrosian'`. All existing constant NAMES are
  unchanged (only their string values move). Task 2 relies on these names and the moved tree existing.

- [ ] **Step 1: Update the constant-value tests to expect the new paths (write the failing assertions first)**

In `phpunit_tests/Enum/JsonDataTest.php`, method `testConstantHierarchyComposesCorrectly()`, replace the four Roman-path
assertions (leave `FOLDER`, `SCHEMAS_FOLDER`, `SOURCEDATA_FOLDER`, and `CATHOLIC_DIOCESES_LATIN_RITE` untouched):

```php
        self::assertSame('jsondata/sourcedata/rite/roman/decrees', JsonDataConstants::DECREES_FOLDER);
        self::assertSame('jsondata/sourcedata/rite/roman/decrees/decrees.json', JsonDataConstants::DECREES_FILE);
        self::assertSame('jsondata/sourcedata/rite/roman/missals', JsonDataConstants::MISSALS_FOLDER);
        self::assertSame(
            'jsondata/sourcedata/rite/roman/missals/propriumdetempore',
            JsonDataConstants::TEMPORALE_FOLDER
        );
        self::assertSame(
            'jsondata/sourcedata/rite/roman/missals/propriumdetempore/propriumdetempore.json',
            JsonDataConstants::TEMPORALE_FILE
        );
        self::assertSame('jsondata/sourcedata/rite/roman/calendars', JsonDataConstants::CALENDARS_FOLDER);
        self::assertSame(
            'jsondata/sourcedata/rite/roman/calendars/nations',
            JsonDataConstants::NATIONAL_CALENDARS_FOLDER
        );
        self::assertSame(
            'jsondata/sourcedata/rite/roman/calendars/dioceses',
            JsonDataConstants::DIOCESAN_CALENDARS_FOLDER
        );
```

Add two assertions for the new rite-base constants immediately after the `SOURCEDATA_FOLDER` assertion:

```php
        self::assertSame('jsondata/sourcedata/rite/roman', JsonDataConstants::ROMAN_RITE_FOLDER);
        self::assertSame('jsondata/sourcedata/rite/ambrosian', JsonDataConstants::AMBROSIAN_RITE_FOLDER);
```

In `phpunit_tests/Enum/JsonDataAmbrosianPathTest.php`, update the two expected strings:

```php
            'jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore/propriumdetempore.json',
```

```php
            'jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore/i18n/{locale}.json',
```

In `phpunit_tests/Enum/JsonDataAmbrosianSanctoralePathTest.php`, update the two expected strings:

```php
            'jsondata/sourcedata/rite/ambrosian/missals/propriumdesanctis_2024/propriumdesanctis.json',
```

```php
            'jsondata/sourcedata/rite/ambrosian/missals/propriumdesanctis_2024/i18n/{locale}.json',
```

- [ ] **Step 2: Run the value-tests to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Enum/JsonDataTest.php phpunit_tests/Enum/JsonDataAmbrosianPathTest.php phpunit_tests/Enum/JsonDataAmbrosianSanctoralePathTest.php`
Expected: FAIL — assertions compare new expected strings against the still-old constant values (and `ROMAN_RITE_FOLDER` is an undefined constant → error).

- [ ] **Step 3: Add the rite-base constants and re-base the six base-folder constants**

In `src/Enum/JsonDataConstants.php`, immediately after the `SOURCEDATA_FOLDER` constant (currently line ~29), add:

```php
    /**
     * The base folder for Roman-rite source data.
     * Evaluates to 'jsondata/sourcedata/rite/roman'.
     */
    public const ROMAN_RITE_FOLDER = JsonDataConstants::SOURCEDATA_FOLDER . '/rite/roman';

    /**
     * The base folder for Ambrosian-rite source data.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian'.
     */
    public const AMBROSIAN_RITE_FOLDER = JsonDataConstants::SOURCEDATA_FOLDER . '/rite/ambrosian';
```

Re-base the four Roman folder constants (change only the right-hand side base):

```php
    public const DECREES_FOLDER = JsonDataConstants::ROMAN_RITE_FOLDER . '/decrees';
    public const MISSALS_FOLDER = JsonDataConstants::ROMAN_RITE_FOLDER . '/missals';
    public const LECTIONARY_FOLDER = JsonDataConstants::ROMAN_RITE_FOLDER . '/lectionary';
    public const CALENDARS_FOLDER = JsonDataConstants::ROMAN_RITE_FOLDER . '/calendars';
```

Re-base the two Ambrosian base-folder constants onto `AMBROSIAN_RITE_FOLDER`, dropping the `/ambrosian` segment:

```php
    public const AMBROSIAN_TEMPORALE_FOLDER = JsonDataConstants::AMBROSIAN_RITE_FOLDER . '/missals/propriumdetempore';
    public const AMBROSIAN_SANCTORALE_FOLDER = JsonDataConstants::AMBROSIAN_RITE_FOLDER . '/missals/propriumdesanctis_2024';
```

Do NOT touch any other constant — every derived constant recomposes automatically. Do NOT edit docblocks yet (Task 2).

- [ ] **Step 4: Run the value-tests to verify they pass**

Run: `vendor/bin/phpunit phpunit_tests/Enum/JsonDataTest.php phpunit_tests/Enum/JsonDataAmbrosianPathTest.php phpunit_tests/Enum/JsonDataAmbrosianSanctoralePathTest.php`
Expected: PASS. (These assert string values only; files have not moved yet, so the broader file-reading suite is now temporarily broken — fixed in Step 5.)

- [ ] **Step 5: Physically move the folders to match the constants**

Extract the Ambrosian subtree FIRST (before moving the Roman `missals/` folder, since Ambrosian currently lives inside
it), then move the four Roman folders, then create the empty diocesan scan root:

```bash
# Ambrosian subtree out of the old missals/ambrosian/ location:
mkdir -p jsondata/sourcedata/rite/ambrosian/missals
git mv jsondata/sourcedata/missals/ambrosian/propriumdetempore      jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore
git mv jsondata/sourcedata/missals/ambrosian/propriumdesanctis_2024 jsondata/sourcedata/rite/ambrosian/missals/propriumdesanctis_2024
rmdir jsondata/sourcedata/missals/ambrosian 2>/dev/null || true

# Roman folders down one level under rite/roman/:
mkdir -p jsondata/sourcedata/rite/roman
git mv jsondata/sourcedata/calendars  jsondata/sourcedata/rite/roman/calendars
git mv jsondata/sourcedata/decrees    jsondata/sourcedata/rite/roman/decrees
git mv jsondata/sourcedata/lectionary jsondata/sourcedata/rite/roman/lectionary
git mv jsondata/sourcedata/missals    jsondata/sourcedata/rite/roman/missals

# Stable empty scan root for the next milestone's Ambrosian diocesan discovery:
mkdir -p jsondata/sourcedata/rite/ambrosian/calendars/dioceses
touch jsondata/sourcedata/rite/ambrosian/calendars/dioceses/.gitkeep
git add jsondata/sourcedata/rite/ambrosian/calendars/dioceses/.gitkeep
```

- [ ] **Step 6: Update the three literal filesystem paths in `RegionalDataHandlerTest.php`**

Change the two `nations/HR` and one `nations/MT` base-path literals:

```php
        $base              = Router::$apiFilePath . 'jsondata/sourcedata/rite/roman/calendars/nations/HR';
```

(and the `MT` one:)

```php
        $base              = Router::$apiFilePath . 'jsondata/sourcedata/rite/roman/calendars/nations/MT';
```

- [ ] **Step 7: Verify no scanner globs `SOURCEDATA_FOLDER/*` directly (discovery invariant)**

Run: `grep -rnE "glob\(|scandir\(|DirectoryIterator|GlobIterator" src/ | grep -iE "SOURCEDATA_FOLDER"`
Expected: NO output. (Every scan is rooted on a derived folder constant — `NATIONAL_CALENDARS_FOLDER`,
`DIOCESAN_CALENDARS_FOLDER`, `WIDER_REGIONS_FOLDER`, `MISSALS_FOLDER`, `AMBROSIAN_TEMPORALE_I18N_FOLDER` — which
recompose, so the metadata index rebuilds identically. If anything prints, stop and reassess.)

- [ ] **Step 8: Run the full test suite**

Run: `composer test`
Expected: PASS (the file-reading suite — schema validation, calendar generation, Ambrosian path tests — resolves every file at its new location).

- [ ] **Step 9: Run PHPStan and lint**

Run: `composer analyse && composer lint && composer parallel-lint`
Expected: no errors.

- [ ] **Step 10: Golden-master byte-identical gate**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/CalendarGoldenMasterTest.php`
Expected: PASS, 9/9 fixtures byte-identical. (Requires `.env.local` in the worktree; if the test SKIPs, `.env.local` is missing — fix before proceeding.)

- [ ] **Step 11: Sanity-check the working tree is only renames + the intended edits**

Run: `git status && git diff --stat`
Expected: renames for the moved folders, modifications only to `JsonDataConstants.php` + the four test files, and the
new `.gitkeep`. No deleted data files, no untracked source data.

- [ ] **Step 12: Commit**

```bash
git add -A
git commit -m "refactor(data): partition sourcedata by rite (rite/{roman,ambrosian})

Move all Roman source-data folders under rite/roman/ and the Ambrosian
comune under rite/ambrosian/missals/, re-basing the JsonData base-folder
constants. Pure relocation: Roman and Ambrosian output byte-identical
(golden-master 9/9). Foundational for attaching Ambrosian diocesan
calendars to dioceses that also have Roman-rite communities.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Docblock and comment path accuracy

Cosmetic-only: bring the 61+61 "Evaluates to …" docblocks and a handful of test comments in line with the moved paths,
so the documentation never lies. Independently reviewable and rejectable from Task 1.

**Files:**

- Modify: `src/Enum/JsonDataConstants.php` (docblocks)
- Modify: `src/Enum/JsonData.php` (docblocks)
- Modify: `phpunit_tests/Routes/Readonly/DecreesTest.php` (comment)
- Modify: `phpunit_tests/Routes/Readonly/MissalsTest.php` (comment)
- Modify: `phpunit_tests/Services/ResourceExistenceCheckerTest.php` (comments)

**Interfaces:**

- Consumes: the moved tree and re-based constants from Task 1.
- Produces: nothing consumed downstream (documentation only).

- [ ] **Step 1: Bulk-rewrite the Roman "Evaluates to" docblocks in both enum files**

The Roman docblocks say `Evaluates to 'jsondata/sourcedata/{decrees,missals,lectionary,calendars}...'`; they must become
`.../rite/roman/{...}`. Run four explicit passes over both files (the folder immediately after `sourcedata/` is the
anchor):

```bash
for f in src/Enum/JsonDataConstants.php src/Enum/JsonData.php; do
  sed -i "s#Evaluates to 'jsondata/sourcedata/decrees#Evaluates to 'jsondata/sourcedata/rite/roman/decrees#g" "$f"
  sed -i "s#Evaluates to 'jsondata/sourcedata/missals#Evaluates to 'jsondata/sourcedata/rite/roman/missals#g" "$f"
  sed -i "s#Evaluates to 'jsondata/sourcedata/lectionary#Evaluates to 'jsondata/sourcedata/rite/roman/lectionary#g" "$f"
  sed -i "s#Evaluates to 'jsondata/sourcedata/calendars#Evaluates to 'jsondata/sourcedata/rite/roman/calendars#g" "$f"
done
```

- [ ] **Step 2: Fix the Ambrosian docblocks (they were `rite/roman/missals/ambrosian` after Step 1 — restore to `rite/ambrosian/missals`)**

Step 1 rewrote `.../missals/...` lines, so the Ambrosian docblocks now wrongly read `jsondata/sourcedata/rite/roman/missals/ambrosian/...`. Correct them:

```bash
for f in src/Enum/JsonDataConstants.php src/Enum/JsonData.php; do
  sed -i "s#jsondata/sourcedata/rite/roman/missals/ambrosian/#jsondata/sourcedata/rite/ambrosian/missals/#g" "$f"
done
```

- [ ] **Step 3: Verify the two new rite-base docblocks (written with the constants in Task 1 Step 3) read correctly**

Run: `grep -nE "Evaluates to 'jsondata/sourcedata/rite/(roman|ambrosian)'" src/Enum/JsonDataConstants.php`
Expected: two lines — `.../rite/roman'` and `.../rite/ambrosian'`.

- [ ] **Step 4: Verify no stale docblock paths remain**

Run: `grep -rnE "Evaluates to 'jsondata/sourcedata/(decrees|missals|lectionary|calendars)'?" src/Enum/JsonDataConstants.php src/Enum/JsonData.php | grep -v "rite/"`
Expected: NO output (every source-data docblock now carries a `rite/` segment). Also spot-check: `grep -n "rite/roman/missals/ambrosian" src/Enum/*.php` must print nothing.

- [ ] **Step 5: Update the path mentions in test comments**

`phpunit_tests/Routes/Readonly/DecreesTest.php` — the comment referencing `jsondata/sourcedata/decrees/decrees.json`:

```php
 * jsondata/sourcedata/rite/roman/decrees/decrees.json + applies the requested locale's
```

`phpunit_tests/Routes/Readonly/MissalsTest.php` — the comment referencing the 1970 missal:

```php
        // committed under jsondata/sourcedata/rite/roman/missals/propriumdesanctis_1970/.
```

`phpunit_tests/Services/ResourceExistenceCheckerTest.php` — the three comments:

```php
        // 'ZZ' has no folder under jsondata/sourcedata/rite/roman/calendars/nations
```

```php
        // IT has jsondata/sourcedata/rite/roman/calendars/nations/IT/IT.json
```

```php
        // Europe has jsondata/sourcedata/rite/roman/calendars/wider_regions/Europe/
```

- [ ] **Step 6: Re-run analysis, lint, and the value-tests (docblocks must not have disturbed code)**

Run: `composer analyse && composer lint && vendor/bin/phpunit phpunit_tests/Enum/JsonDataTest.php`
Expected: no errors; JsonDataTest PASS.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "docs(data): sync JsonData path docblocks + test comments to rite/ tree

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Final Verification (manual, before opening the PR)

Automated gates run inside the tasks; these end-to-end checks are done once by the human driver against the running
stack (the memory notes Routes integration + docker-stack checks are CI-only in the sandbox):

- [ ] Bring up the frontend docker stack (litcal-api live-mounts `src|public|jsondata`) or `composer start`, then capture
  and diff responses **before vs after** is not possible post-merge — instead confirm the endpoints return valid,
  populated data at the new tree:
  - `GET /calendar/2025` → 200, full Roman calendar.
  - `GET /calendar/ambrosian/2025?year_type=CIVIL` → 200, StAmbrose anticipated to 2025-12-06 (the Plan-7 end-to-end marker).
  - `GET /calendars` → announces national, diocesan, wider-region, and `ambrosian_calendars` exactly as before.
  - `GET /events`, `GET /events/ambrosian`, `GET /missals`, `GET /decrees` → 200, populated.
- [ ] `composer test` and `composer analyse` green on the branch head.
- [ ] `git log --stat` shows the moves as renames (history preserved).
- [ ] Markdown lint the spec + this plan: `composer lint:md` (or `npx --yes markdownlint-cli "docs/superpowers/**/*.md"`).

---

## Self-Review Notes

- **Spec coverage:** target tree (Task 1 Step 5) ✓; constant re-base incl. the Ambrosian-must-not-compose-off-MISSALS trap
  (Task 1 Step 3 + Global Constraints) ✓; empty diocesan scan root (Task 1 Step 5) ✓; docblock accuracy (Task 2) ✓; test
  path fixes (Task 1 Step 1/6, Task 2 Step 5) ✓; discovery invariant re-grep (Task 1 Step 7) ✓; golden-master gate (Task 1
  Step 10) ✓; Dockerfile wholesale COPY — no edit needed, verified `COPY ./jsondata ./jsondata`, covered by Final
  Verification ✓; out-of-scope items (discovery/overlay/città) not touched ✓.
- **Type consistency:** constant names unchanged across tasks; new names `ROMAN_RITE_FOLDER` / `AMBROSIAN_RITE_FOLDER` used identically in Task 1 Steps 1, 3 and Task 2 Step 3.
- **No placeholders:** every step carries exact paths, code, commands, and expected output.
