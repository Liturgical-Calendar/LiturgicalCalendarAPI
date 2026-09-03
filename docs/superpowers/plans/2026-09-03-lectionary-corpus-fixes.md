# Lectionary Corpus Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan
task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Correct three data defects in the rite-level Roman lectionary corpus — wrong readings for the Nativity of St John the Baptist, psalm numbering that matches no
locale's liturgical books, and a Dutch corpus that is a verbatim Latin copy — and add the guards that would have caught each.

**Architecture:** Pure source-data work under `jsondata/sourcedata/rite/roman/lectionary/`, plus one new PHPUnit test method per defect family and one new lint script.
No `src/` changes, no API surface changes. Three sequential branches, each merged before the next starts, because PR 2 decides the psalm form that PR 3 writes.

**Tech Stack:** PHP 8.4, PHPUnit 12, `swaggest/json-schema`, CaptainHook pre-commit hooks, markdownlint-cli2.

**Spec:** `docs/superpowers/specs/2026-09-03-lectionary-corpus-fixes-design.md`

## Global Constraints

- **Never mutate `jsondata/` from a test.** These tests only read. Do not add fixtures under `jsondata/`.
- **JSON formatting:** 4-space indent, one key per line, trailing newline, UTF-8 with non-ASCII written literally (`Isaiæ`, `Isaïe`, `Mattheüs`) — never `\uXXXX`.
  `composer lint:jsondata` enforces canonical encoding.
- **PHP:** PSR-12 via `phpcs`, PHPStan level 10 over `src` only. `declare(strict_types=1);` in every new PHP file.
- **Tests:** do NOT add `#[Group('slow')]` to anything in this plan — every test here is millisecond-scale, and the group is an exclusion mechanism that would hide them
  from `composer test:quick`.
- **`#[CoversNothing]`** on data-integrity tests, matching `LectionaryCorpusTest`. Never `#[CoversClass]` on a non-`src/` class — it reds the coverage CI job.
- **Commits:** never `--no-verify`. Pre-commit runs phpcs and `composer lint:md`.
- **Chapter and verse numbers are never translated.** Only book names and psalm numbering change per locale.
- **Psalm alignment rule:** Hebrew and Vulgate numbering coincide for Ps 1–8 and Ps 148–150; no parenthetical gloss is written for those.

---

## File Structure

| File                                                                         | Responsibility                                                 | PR      |
|------------------------------------------------------------------------------|----------------------------------------------------------------|---------|
| `phpunit_tests/LectionaryCorpusTest.php`                                     | Add `vigil !== day` and `no two locale files identical` guards | 1, 3    |
| `jsondata/sourcedata/rite/roman/lectionary/sanctorum/{6 locales}.json`       | `NativityJohnBaptist` day block; psalm renumbering; Dutch      | 1, 2, 3 |
| `scripts/lint-lectionary-psalms.php`                                         | New lint: each locale's psalm citation form                    | 2       |
| `composer.json`                                                              | Register `lint:lectionary-psalms`                              | 2       |
| `.github/workflows/main.yml`                                                 | New `lectionary_psalms_lint` job                               | 2       |
| `jsondata/sourcedata/rite/roman/lectionary/README.md`                        | The convention table, with sources, beside the data            | 2       |
| `jsondata/sourcedata/rite/roman/lectionary/{4 other sections}/{locale}.json` | Psalm renumbering; Dutch rendering                             | 2, 3    |

---

## PR 1 — Issue #971

Branch: `fix/971-nativity-john-baptist-day-readings` (already created, spec already committed on it).

### Task 1: Correct the Nativity of St John the Baptist day readings, and guard the shape

Both blocks of `NativityJohnBaptist` currently hold the **Vigil's** readings in all six locales. The `vigil` block is correct and must not be touched; the `day` block is wrong.

**Files:**

- Modify: `phpunit_tests/LectionaryCorpusTest.php`
- Modify: `jsondata/sourcedata/rite/roman/lectionary/sanctorum/en.json`
- Modify: `jsondata/sourcedata/rite/roman/lectionary/sanctorum/it.json`
- Modify: `jsondata/sourcedata/rite/roman/lectionary/sanctorum/la.json`
- Modify: `jsondata/sourcedata/rite/roman/lectionary/sanctorum/fr.json`
- Modify: `jsondata/sourcedata/rite/roman/lectionary/sanctorum/nl.json`
- Modify: `jsondata/sourcedata/rite/roman/lectionary/sanctorum/hr.json`

**Interfaces:**

- Consumes: `self::lectionaryFiles()`, the existing private static helper in `LectionaryCorpusTest`.
- Produces: `private static function readingsAreAllEmpty(mixed $block): bool` — reused by Task 4.

- [ ] **Step 1: Write the failing test**

Add to `phpunit_tests/LectionaryCorpusTest.php`, after `testEveryLocaleFileInALectionaryFolderDeclaresTheSameKeySet()`:

```php
    /**
     * True when every reading in one Mass block is present as an empty string.
     *
     * 85% of the corpus is unfilled placeholders (#712), and two empty blocks are trivially
     * equal, so the duplication check below has to be able to tell "not yet filled in" from
     * "filled in wrongly".
     *
     * Anything that is not an empty string makes this false — a populated reading, but equally
     * a null, a number or a nested array — so the entry is COMPARED rather than skipped. The
     * skip is the only way an entry escapes this guard, so it has to be the narrow case: a block
     * of nulls is not a placeholder this test understands, and passing silently over one would
     * make the guard report an untruth. The schema admits only strings, so the non-string arms
     * are unreachable against today's corpus and cost nothing.
     */
    private static function readingsAreAllEmpty(mixed $block): bool
    {
        if (!is_array($block)) {
            return false;
        }

        foreach ($block as $reading) {
            if (!is_string($reading) || '' !== $reading) {
                return false;
            }
        }

        return true;
    }

    /**
     * An entry that carries both a `vigil` and a `day` block must not hold the same readings in both.
     *
     * A Vigil Mass has its own proper readings; that is what makes it a vigil rather than an
     * anticipation of the day. `NativityJohnBaptist` held the Vigil's readings in BOTH blocks in
     * all six locales, so the Mass during the Day served Jeremiah 1:4-10 instead of Isaiah 49:1-6
     * (#971).
     *
     * This is the check #969 could not be. #969 compares `event_key` SETS across the locale files
     * of a folder, so a defect that is uniform across all six files and internal to one entry
     * passes it by construction — every file agreed, and every file was wrong.
     *
     * Entries whose blocks are both entirely empty are skipped: `Christmas` and `Pentecost` are in
     * that state in seventeen files, and flagging them would report #712 as if it were this defect.
     *
     * The two blocks are compared by key-sorted value, not by literal identity. PHP's `===` on
     * associative arrays is order-sensitive, so a duplicate whose keys had merely been written in
     * a different order would slip past — a false negative in the one direction this guard exists
     * to prevent.
     */
    public function testNoEntryHoldsTheSameReadingsForItsVigilAndItsDay(): void
    {
        $root     = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        $failures = [];

        /** @var array<string, list<string>> $comparedIn event_key => the files it was compared in */
        $comparedIn = [];

        foreach (self::lectionaryFiles() as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents, "could not read {$file}");

            /** @var array<string, mixed> $data */
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            foreach ($data as $key => $entry) {
                if (!is_array($entry) || !array_key_exists('vigil', $entry) || !array_key_exists('day', $entry)) {
                    continue;
                }

                if (self::readingsAreAllEmpty($entry['vigil']) && self::readingsAreAllEmpty($entry['day'])) {
                    continue;
                }

                $relative           = str_replace($root, '', $file);
                $comparedIn[$key][] = $relative;

                $vigil = $entry['vigil'];
                $day   = $entry['day'];

                if (is_array($vigil) && is_array($day)) {
                    ksort($vigil);
                    ksort($day);
                }

                if ($vigil === $day) {
                    $failures[] = sprintf(
                        '%s: "%s" repeats its vigil readings verbatim as its day readings',
                        $relative,
                        $key
                    );
                }
            }
        }

        // Pin the entry this invariant was written for, and pin it per FILE rather than once
        // globally: a name recorded from any one locale would leave the guard green while five
        // of the six sanctorale files had silently stopped being compared.
        $sanctorum     = str_replace('/', DIRECTORY_SEPARATOR, 'jsondata/sourcedata/rite/roman/lectionary/sanctorum/');
        $comparedFiles = $comparedIn['NativityJohnBaptist'] ?? [];

        foreach (['en', 'fr', 'hr', 'it', 'la', 'nl'] as $locale) {
            $this->assertContains(
                $sanctorum . $locale . '.json',
                $comparedFiles,
                sprintf('the sanctorale %s file never had its NativityJohnBaptist vigil/day pair compared', $locale)
            );
        }

        $this->assertSame(
            [],
            $failures,
            "Lectionary entries repeat one Mass's readings for both the vigil and the day:\n" . implode("\n", $failures)
        );
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd "$(git rev-parse --show-toplevel)"
vendor/bin/phpunit --filter testNoEntryHoldsTheSameReadingsForItsVigilAndItsDay phpunit_tests/LectionaryCorpusTest.php
```

Expected: FAIL, listing exactly six failures — `sanctorum/en.json`, `it.json`, `la.json`, `fr.json`, `nl.json`, `hr.json`, each naming `"NativityJohnBaptist"`. If any
other key appears, stop: the skip rule for empty placeholders is wrong.

- [ ] **Step 3: Correct the day block in all six locale files**

In each file, replace **only** the `"day"` object of `"NativityJohnBaptist"`. Leave `"vigil"` exactly as it is. Match each file's existing punctuation — `en` uses `Book
C:v-v`, `it`/`la`/`fr`/`nl` use `Book C, v-v.v`, `hr` uses `Bk C,v-v.v`.

`sanctorum/en.json`:

```json
        "day": {
            "first_reading": "Isaiah 49:1-6",
            "responsorial_psalm": "Psalm 139",
            "second_reading": "Acts 13:22-26",
            "gospel_acclamation": "Luke 1:76",
            "gospel": "Luke 1:57-66, 80"
        }
```

`sanctorum/it.json`:

```json
        "day": {
            "first_reading": "Isaia 49, 1-6",
            "responsorial_psalm": "Salmo 139",
            "second_reading": "Atti 13, 22-26",
            "gospel_acclamation": "Luca 1, 76",
            "gospel": "Luca 1, 57-66.80"
        }
```

`sanctorum/la.json`:

```json
        "day": {
            "first_reading": "Isaiæ 49, 1-6",
            "responsorial_psalm": "Psalmo 139",
            "second_reading": "Actus 13, 22-26",
            "gospel_acclamation": "Lucam 1, 76",
            "gospel": "Lucam 1, 57-66.80"
        }
```

`sanctorum/fr.json`:

```json
        "day": {
            "first_reading": "Isaïe 49, 1-6",
            "responsorial_psalm": "Psaume 139",
            "second_reading": "Actes 13, 22-26",
            "gospel_acclamation": "Luc 1, 76",
            "gospel": "Luc 1, 57-66.80"
        }
```

`sanctorum/nl.json` — written in Dutch, not the Latin the rest of this file still holds. Book names follow `decrees/lectionary/nl.json`, the repository's only existing
Dutch lectionary data. PR 3 converts the remainder of the file:

```json
        "day": {
            "first_reading": "Jesaja 49, 1-6",
            "responsorial_psalm": "Psalm 139",
            "second_reading": "Handelingen 13, 22-26",
            "gospel_acclamation": "Lucas 1, 76",
            "gospel": "Lucas 1, 57-66.80"
        }
```

`sanctorum/hr.json`:

```json
        "day": {
            "first_reading": "Iz 49,1-6",
            "responsorial_psalm": "Ps 139",
            "second_reading": "Dj 13,22-26",
            "gospel_acclamation": "Lk 1,76",
            "gospel": "Lk 1,57-66.80"
        }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
vendor/bin/phpunit --filter testNoEntryHoldsTheSameReadingsForItsVigilAndItsDay phpunit_tests/LectionaryCorpusTest.php
```

Expected: PASS, 1 test.

- [ ] **Step 5: Run the whole corpus test and the source-data lints**

```bash
vendor/bin/phpunit phpunit_tests/LectionaryCorpusTest.php
composer lint:jsondata
composer lint:locales
```

Expected: all green. `lint:jsondata` proves the six files kept canonical encoding — that `Isaiæ` and `Isaïe` were written literally, not escaped.

- [ ] **Step 6: Commit**

```bash
git add phpunit_tests/LectionaryCorpusTest.php jsondata/sourcedata/rite/roman/lectionary/sanctorum/
git commit -m "fix(lectionary): give the Nativity of St John the Baptist its own day readings (#971)

Both blocks held the Vigil's readings in all six locales, so the Mass
during the Day served Jeremiah 1:4-10 in place of Isaiah 49:1-6. The
issue reported this as the vigil duplicating the day; it is the reverse.

Day readings per CEI and USCCB: Is 49:1-6, Ps 139, Acts 13:22-26,
Lk 1:76, Lk 1:57-66.80. The vigil block was already correct and is
untouched. The nl row is written in Dutch rather than the Latin the rest
of that file still holds (#972 converts the remainder).

Guards the shape with a vigil-differs-from-day assertion, skipping
entries whose blocks are both still empty (#712). This is the check #969
could not be: it compares key sets across locales, so a defect uniform
across all six files passes it by construction.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01C1mzMZgPg3y9KKQRMfHK38"
```

- [ ] **Step 7: Open the PR against `development`**

```bash
gh pr create --base development --title "fix(lectionary): Nativity of St John the Baptist day readings (#971)" --body "..."
```

Body must state that the issue's description is inverted and that the `day` block was the defective one. Close with the attribution footer. Do NOT retry on a non-zero
exit from `gh pr merge` later — verify with `gh pr view --json state`.

---

## PR 2 — Issue #973

Branch off `development` **after PR 1 merges**: `fix/973-psalm-numbering-convention`.

## Measured baseline

| locale | psalm citations | currently bare | currently dual | change                       |
|--------|-----------------|----------------|----------------|------------------------------|
| `en`   | 405             | 385            | 20             | strip the 20 duals           |
| `it`   | 130             | 111            | 19             | gloss all but aligned psalms |
| `fr`   | 130             | 111            | 19             | gloss all but aligned psalms |
| `la`   | 128             | 109            | 19             | renumber all to bare Vulgate |
| `hr`   | 137             | 137            | 0              | none                         |
| `nl`   | 1 (rest Latin)  | 1              | 0              | none; PR 3 writes the file   |

All existing numbers are **Hebrew**, including the parenthesised half of the 19/20 duals.

### Target forms

| locale | form             | example          |
|--------|------------------|------------------|
| `la`   | bare Vulgate     | `Psalmo 88`      |
| `it`   | Vulgate (Hebrew) | `Salmo 88 (89)`  |
| `fr`   | Vulgate (Hebrew) | `Psaume 88 (89)` |
| `en`   | bare Hebrew      | `Psalm 89`       |
| `hr`   | bare Hebrew      | `Ps 89`          |
| `nl`   | Hebrew (Vulgate) | `Psalm 89 (88)`  |

### Hebrew → Vulgate mapping

| Hebrew               | Vulgate   |
|----------------------|-----------|
| 1–8                  | same      |
| 9–10                 | 9         |
| 11–113               | −1        |
| 114–115              | 113       |
| 116:1-9 / 116:10-19  | 114 / 115 |
| 117–146              | −1        |
| 147:1-11 / 147:12-20 | 146 / 147 |
| 148–150              | same      |

### Task 2: The psalm-form lint

**Files:**

- Create: `scripts/lint-lectionary-psalms.php`
- Create: `jsondata/sourcedata/rite/roman/lectionary/README.md`
- Modify: `composer.json`
- Modify: `.github/workflows/main.yml`

**Interfaces:**

- Produces: `composer lint:lectionary-psalms`, exit 0 when every citation matches its locale's form, exit 1 naming each offender on stderr. Task 3 runs it to prove the data fix.

- [ ] **Step 1: Write the lint script**

Create `scripts/lint-lectionary-psalms.php`, modelled on `scripts/lint-missals.php` (same shebang, `declare(strict_types=1)`, docblock explaining *why*, failures
accumulated then printed to stderr, exit 1). It must:

1. Walk every `{locale}.json` under `jsondata/sourcedata/rite/roman/lectionary/*/`.
2. For each string leaf, split on `|` (alternative readings) and match each alternative against **every** psalm prefix the corpus uses — `Psalm`, `Salmo`, `Psalmo`,
   `Psaume`, `Ps` — each optionally preceded by `Cf.`. Recognise the citation first, then check the prefix against the one this locale requires (`Psalm` for
   `en`/`nl`, `Salmo` for `it`, `Psalmo` for `la`, `Psaume` for `fr`, `Ps` for `hr`) and fail it when they disagree.

   Matching only the locale's own prefix would make the lint blind in exactly the case it most needs to see: while `nl.json` still holds Latin (`Psalmo 71`), an
   `nl`-only matcher finds nothing and the file passes having been checked for nothing. That is the "check that reports an untruth" family this repository has hit
   repeatedly (#822, #833, #834, #835). Recognising all prefixes turns that silence into the finding it should be — a Latin citation in a Dutch file.
3. Fail a citation when:
   - `en`/`hr` carry a parenthetical at all;
   - `it`/`fr`/`la`/`nl` gloss a psalm in 1–8 or 148–150 (the numberings coincide, so there is nothing to gloss);
   - `it`/`fr` lack a parenthetical for a psalm outside those ranges;
   - `la` carries a parenthetical at all;
   - `nl` lacks a parenthetical for a psalm outside those ranges;
   - the two numbers of a dual citation are not a valid Hebrew/Vulgate pair per the mapping table, in the order that locale requires (`it`/`fr` Vulgate-first, `nl` Hebrew-first).
4. Print a per-locale citation count in the success line, so a locale the lint matched nothing in is visible rather than silently green.
5. Skip `Ps 116` and `Ps 147` dual citations from the pair check when no verse numbers are present, since the mapping is verse-dependent there; report the count of such
   skips in the success line so they stay visible.

- [ ] **Step 2: Register the script**

In `composer.json`, beside `"lint:missals"`:

```json
        "lint:lectionary-psalms": "@php scripts/lint-lectionary-psalms.php",
```

- [ ] **Step 3: Run it to verify it fails on current data**

```bash
composer lint:lectionary-psalms
```

Expected: exit 1, reporting roughly 20 `en` violations (the duals), ~110 `it` and ~110 `fr` (missing glosses), and ~128 `la` (Hebrew numbers and 19 stray
parentheticals). If `hr` reports anything, stop — `hr` is meant to be already conformant, and a failure there means the prefix match is wrong.

- [ ] **Step 4: Write the convention README**

Create `jsondata/sourcedata/rite/roman/lectionary/README.md` carrying the two tables above (target forms and the Hebrew→Vulgate mapping) with the sources from the spec,
and stating plainly that `hr` is recorded as bare Hebrew on corpus-internal evidence only, since the Croatian conference's site could not be reached. Aligned tables,
180-char lines, per `.markdownlint.yml`.

- [ ] **Step 5: Add the CI job**

In `.github/workflows/main.yml`, copy the `missals_lint` job to `lectionary_psalms_lint` — same `needs: build`, `permissions: contents: read`, `persist-credentials:
false`, pinned action SHAs, vendor download and `chmod` steps — with a comment explaining that a psalm number in the wrong numbering names a *different psalm*, not a
differently-formatted one, and that nothing else in CI can see it. Final step: `run: composer lint:lectionary-psalms`.

- [ ] **Step 6: Lint the markdown and commit**

```bash
composer lint:md
git add scripts/lint-lectionary-psalms.php composer.json .github/workflows/main.yml jsondata/sourcedata/rite/roman/lectionary/README.md
git commit -m "feat(lectionary): lint each locale's psalm numbering convention (#973)"
```

The lint is red at this commit, and that is intentional — Task 3 is what makes it green. Note this in the commit body.

### Task 3: Apply the convention to the corpus

**Files:**

- Modify: all `{locale}.json` under `jsondata/sourcedata/rite/roman/lectionary/*/` for `en`, `it`, `fr`, `la`

- [ ] **Step 1: Resolve the nine verse-dependent citations by hand**

`it`, `fr` and `la` each carry a bare `Salmo 147` / `Psaume 147` / `Psalmo 147` for three keys in `feriale_tempus_nativitatis`: `ChristmasWeekdayJan6`,
`DayAfterEpiphanyJan11`, `DayAfterEpiphanyFriday`. Hebrew 147 splits into Vulgate 146 (vv. 1-11) and 147 (vv. 12-20), so the whole-psalm citation is ambiguous.
Determine the verses from the Lectionary for each of the three days, then write the correct Vulgate number. Record the resolution in the PR body.

All nine must be resolved. Leaving one bare is not an option here: a bare citation in `it` or `fr` violates that locale's own rule and the lint of Task 2 fails it, so
"leave it and note it" would ship a red lint. If a source genuinely cannot be found for one of the three days, add a narrowly scoped exception to the lint that permits
those specific `event_key`s and no others, cover it with a test that fails if the exception is widened, and record in the README that the exception must be removed once
the citation is resolved. Do not guess a number.

`en` needs no resolution here — it stays bare Hebrew — and `hr` is untouched.

- [ ] **Step 2: Apply the per-locale forms**

For `en`: strip the parenthetical from the 20 dual citations, keeping the **parenthesised** number, which is the Hebrew one. `Psalm 33 (34)` becomes `Psalm 34`, not
`Psalm 33`. Getting this backwards silently changes which psalm is served.

For `it` and `fr`: convert every citation to `Vulgate (Hebrew)` except psalms 1–8 and 148–150, which stay bare. The existing 19 duals in each are already in the correct
form and order — verify rather than rewrite them.

For `la`: rewrite every citation to the bare Vulgate number, dropping the 19 existing parentheticals. `Psalmo 89` becomes `Psalmo 88`; `Psalmo 33 (34)` becomes `Psalmo 33`.

- [ ] **Step 3: Fix the one punctuation stray**

In `sanctorum/en.json`, `StJoseph`'s `gospel_acclamation` is `Psalm 84,5` in an otherwise colon-style file. Change to `Psalm 84:5`.

- [ ] **Step 4: Verify**

```bash
composer lint:lectionary-psalms
composer lint:jsondata
vendor/bin/phpunit phpunit_tests/LectionaryCorpusTest.php
```

Expected: all green. Then confirm nothing but psalm citations moved:

```bash
git diff -U0 -- jsondata/ | grep '^[+-]' | grep -v '^[+-][+-]' | grep -viE '"(responsorial_psalm|responsorial_psalm_[0-9]|responsorial_psalm_epistle|gospel_acclamation)"'
```

Expected: no output. Any line here is a reading that was edited by accident.

- [ ] **Step 5: Commit and open the PR**

```bash
git add jsondata/sourcedata/rite/roman/lectionary/
git commit -m "fix(lectionary): render psalm numbers in each locale's own convention (#973)"
gh pr create --base development --title "fix(lectionary): per-locale psalm numbering convention (#973)" --body "..."
```

The PR body must state the finding that reframes the issue: the corpus was not split between two conventions but stored one numbering across six files needing three
different renderings, and the dual form is `Vulgate (Hebrew)`, not the reverse. Cite the 1987 Latin lectionary incipits for the Latin change.

---

## PR 3 — Issue #972

Branch off `development` **after PR 2 merges**: `fix/972-dutch-lectionary`.

### Task 4: Guard against placeholder locale files

**Files:**

- Modify: `phpunit_tests/LectionaryCorpusTest.php`

**Interfaces:**

- Consumes: `self::lectionaryFiles()` from Task 1.
- Produces: `private static function fileIsAllPlaceholders(array $data): bool` — a NEW, file-level
  predicate. Do **not** reuse `readingsAreAllEmpty()` for this: that one takes a single Mass block
  and does not recurse, whereas a lectionary file is `event_key => block` and its blocks may
  themselves nest (`vigil`/`day`). Applied to a whole file it would inspect the top-level values —
  arrays, never strings — and return false for every file, disabling the exclusion silently and
  flooding this guard with the dozens of legitimately-identical all-empty pairs (`feriale_per_annum_I`
  alone has five). Recurse to the string leaves, and return false the moment one is non-empty. Cover
  it with cases for an all-empty file, a mixed file, and a fully populated one.

- [ ] **Step 1: Write the failing test**

Add a method asserting that within one lectionary folder, no two locale files have identical content — excluding files in which every reading is still
empty, which are unfilled placeholders (#712) and identical by construction. Follow the house pattern of the neighbouring methods: accumulate `$failures`,
pin the folder the guard exists for by name (`rite/roman/lectionary/sanctorum`), assert a lower bound on how many folders were compared, then
`assertSame([], $failures, ...)`.

The docblock must say what the test is for: `nl.json` was a byte-identical copy of `la.json`, so the API served Latin citations as Dutch. Per-file schema validation
cannot see it, and #969's key-set comparison cannot either — two files with identical keys and identical values agree perfectly.

- [ ] **Step 2: Run to verify it fails**

```bash
vendor/bin/phpunit --filter testNoTwoLocaleFilesInALectionaryFolderAreIdentical phpunit_tests/LectionaryCorpusTest.php
```

Expected: FAIL naming four folders — `sanctorum`, `feriale_tempus_nativitatis`, `dominicale_et_festivum_B`, `dominicale_et_festivum_C` — each reporting `nl.json`
identical to `la.json`. `dominicale_et_festivum_A` will NOT appear: its two files differ in bytes though not in values.

- [ ] **Step 3: Commit the red test**

```bash
git add phpunit_tests/LectionaryCorpusTest.php
git commit -m "test(lectionary): assert no locale file duplicates another (#972)"
```

Red at this commit by design; Task 5 makes it green.

### Task 5: Render the Dutch corpus

**Files:**

- Modify: `jsondata/sourcedata/rite/roman/lectionary/sanctorum/nl.json` (349 leaves)
- Modify: `jsondata/sourcedata/rite/roman/lectionary/dominicale_et_festivum_A/nl.json` (27)
- Modify: `jsondata/sourcedata/rite/roman/lectionary/dominicale_et_festivum_B/nl.json` (27)
- Modify: `jsondata/sourcedata/rite/roman/lectionary/dominicale_et_festivum_C/nl.json` (27)
- Modify: `jsondata/sourcedata/rite/roman/lectionary/feriale_tempus_nativitatis/nl.json` (56)

- [ ] **Step 1: Build the book-name mapping table**

Enumerate every distinct book name in the Latin source across the five files, and give each its Dutch equivalent. Seed it from `decrees/lectionary/nl.json` (`Jesaja`,
`Mattheüs`, `Psalm`) and from the values already written in Task 1 (`Handelingen`, `Lucas`). Record the table in the PR body so a reviewer can check it in one place
rather than across 486 leaves.

Flag rather than guess: any book appearing in the Latin source for which no Dutch rendering can be confirmed goes on a list in the PR body, and its entries are left in
Latin, not invented.

- [ ] **Step 2: Apply the mapping**

Translate **book names only**. Chapter and verse numbers, the `|` alternatives separator, the `;` between two citations, and the `Cf.` prefix all stay exactly as they
are. Psalm citations take the `Psalm H (V)` form established by PR 2 — Hebrew first, Vulgate in parentheses, and bare for psalms 1–8 and 148–150.

- [ ] **Step 3: Verify structurally**

```bash
composer lint:lectionary-psalms
composer lint:jsondata
vendor/bin/phpunit phpunit_tests/LectionaryCorpusTest.php
```

Expected: all green, including the Task 4 guard and the #969 key-set assertion — the key set must be unchanged, since only values were edited.

- [ ] **Step 4: Verify no digits moved**

```bash
python3 - <<'PY'
import json, re
for s in ("sanctorum","dominicale_et_festivum_A","dominicale_et_festivum_B",
          "dominicale_et_festivum_C","feriale_tempus_nativitatis"):
    base = f"jsondata/sourcedata/rite/roman/lectionary/{s}"
    la, nl = json.load(open(f"{base}/la.json")), json.load(open(f"{base}/nl.json"))
    def leaves(d, p=()):
        for k, v in d.items():
            if isinstance(v, dict): yield from leaves(v, p+(k,))
            elif isinstance(v, str): yield p+(k,), v
    L, N = dict(leaves(la)), dict(leaves(nl))
    for path, lv in L.items():
        nv = N.get(path, "")
        # Strip psalm parentheticals before comparing: PR 2 gives nl a gloss la does not carry.
        strip = lambda s: re.sub(r"\s*\(\d+\)", "", s)
        ld, nd = re.findall(r"\d+", strip(lv)), re.findall(r"\d+", strip(nv))
        if ld != nd:
            print(f"  {s}/{'/'.join(path)}\n    la={lv}\n    nl={nv}")
PY
```

Expected: output only for psalm citations whose Dutch number is Hebrew where the Latin is now Vulgate — every other line is a transcription error. Read every line
before proceeding.

- [ ] **Step 5: Commit and open the PR**

```bash
git add jsondata/sourcedata/rite/roman/lectionary/
git commit -m "fix(lectionary): render the Dutch lectionary corpus in Dutch (#972)"
gh pr create --base development --title "fix(lectionary): Dutch lectionary corpus (#972)" --body "..."
```

The PR body must carry the book-name mapping table, any books left in Latin for want of a source, and the survey result that `nl` was a Latin copy in five sections
rather than the one the issue named — and that every other identical-file pair in the repository is an empty placeholder (#712), not a mistranslation.

---

## Self-review notes

- **Spec coverage.** #971 → Task 1. #973 → Tasks 2–3, including the README, the lint, the CI job and the `Psalm 84,5` stray. #972 → Tasks 4–5, including the survey
  finding and the identical-file guard. The spec's non-goals (#712, serve-time rendering, national/diocesan layers) have no tasks, correctly.
- **`readingsAreAllEmpty()`** is defined once in Task 1 and reused in Task 4; the name is identical in both.
- **Open question carried forward.** Croatian remains unverified against a printed book. Task 2 Step 4 requires the README to say so, so the gap is recorded where a
  contributor will find it rather than lost with this plan.
