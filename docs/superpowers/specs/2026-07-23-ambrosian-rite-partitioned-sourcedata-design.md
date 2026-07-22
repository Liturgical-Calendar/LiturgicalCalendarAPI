# Rite-Partitioned Sourcedata Migration — Design

**Date:** 2026-07-23
**Status:** Approved (pending written-spec review)
**Part of:** Ambrosian rite integration arc (foundational milestone preceding the diocesan overlays)
**Supersedes storage decision in:** `2026-07-20-ambrosian-rite-integration-design.md` §data-sources
**Related plans:** Ambrosian 01–07 (all merged; `/calendar/ambrosian` is live)

## Summary

Reorganize the API's source-data tree so that liturgical **rite** is the top-level
partition of `jsondata/sourcedata/`. Every current Roman data folder moves down one
level under `rite/roman/`, and the Ambrosian comune moves under `rite/ambrosian/`.

This is a **pure relocation**: the only functional change is the value of a handful
of base-path constants. Roman and Ambrosian API output must be **byte-identical**
before and after. No discovery, loader, schema, or overlay logic changes in this
milestone.

## Motivation

The diocesan-overlay work (next milestone) must attach an Ambrosian diocesan calendar
to dioceses that **also** have Roman-rite communities — Milano, Bergamo, and Novara
each have both Ambrosian and Roman parishes. A single `dioceses/IT/milano_it/` folder
cannot cleanly hold two rites' calendars for the same diocese, and marking rite in
file metadata within a shared tree co-mingles the rites at the storage layer.

Partitioning by rite at the root solves this structurally and generalizes to future
rites (e.g. Mozarabic): every rite gets an identical `{calendars, decrees, lectionary,
missals}` subtree, and a diocese present in two rites is simply two files in two
rite subtrees with the same `diocese_id`.

Doing this as an isolated, behavior-preserving milestone — mirroring how the Plan 1
"Foundation" milestone isolated the golden-master lock and engine extraction — keeps
the broad path-layer touch reviewable and gated, before any new feature logic builds
on the settled tree.

## Target Tree

```text
jsondata/sourcedata/
├── rite/
│   ├── roman/
│   │   ├── calendars/        # nations/, dioceses/, wider_regions/   (moved)
│   │   ├── decrees/                                                   (moved)
│   │   ├── lectionary/                                                (moved)
│   │   └── missals/          # propriumdetempore/, propriumdesanctis_*/  (moved)
│   └── ambrosian/
│       ├── missals/          # propriumdetempore/, propriumdesanctis_2024/  (moved from missals/ambrosian/)
│       └── calendars/        # dioceses/ — created empty here; populated by the NEXT milestone
(jsondata/schemas/ is a sibling of sourcedata/, not under it — UNCHANGED, referenced relatively)
```

Notes:

- The Ambrosian comune loses its redundant `/ambrosian` path segment — it is now
  implied by `rite/ambrosian/`. E.g.
  `missals/ambrosian/propriumdetempore` → `rite/ambrosian/missals/propriumdetempore`.
- Per-folder `i18n/` subfolders travel with their parent folder (they are children of
  the moved folders). The repo-root gettext `i18n/` (`.po`/`.pot`) is **not** under
  `sourcedata/` and is untouched.
- `rite/ambrosian/calendars/dioceses/` is created (empty, e.g. with a `.gitkeep`) so
  the next milestone's rite-aware discovery has a stable root to scan; no diocese files
  are added in this milestone.

## The Functional Diff (constant layer)

All source-data paths resolve through `src/Enum/JsonDataConstants.php` (values) mirrored
by `src/Enum/JsonData.php` (enum cases). Constants compose from a few base folders, so
re-basing those bases relocates everything downstream with no edit to derived constants.

Introduce two rite-base constants and re-base the four Roman folders plus the Ambrosian
folders:

```php
public const ROMAN_RITE_FOLDER     = self::SOURCEDATA_FOLDER . '/rite/roman';
public const AMBROSIAN_RITE_FOLDER = self::SOURCEDATA_FOLDER . '/rite/ambrosian';

// Roman bases (were self::SOURCEDATA_FOLDER . '/...'):
public const CALENDARS_FOLDER  = self::ROMAN_RITE_FOLDER . '/calendars';
public const DECREES_FOLDER    = self::ROMAN_RITE_FOLDER . '/decrees';
public const LECTIONARY_FOLDER = self::ROMAN_RITE_FOLDER . '/lectionary';
public const MISSALS_FOLDER    = self::ROMAN_RITE_FOLDER . '/missals';

// Ambrosian bases (were self::MISSALS_FOLDER . '/ambrosian/...'), now re-based
// so they no longer depend on the Roman MISSALS_FOLDER and drop '/ambrosian':
public const AMBROSIAN_TEMPORALE_FOLDER  = self::AMBROSIAN_RITE_FOLDER . '/missals/propriumdetempore';
public const AMBROSIAN_SANCTORALE_FOLDER = self::AMBROSIAN_RITE_FOLDER . '/missals/propriumdesanctis_2024';
```

Everything derived — `DIOCESAN_CALENDARS_FOLDER`, `NATIONAL_CALENDAR_FILE`,
`WIDER_REGIONS_FOLDER`, all `LECTIONARY_*`, `AMBROSIAN_*_FILE`/`_I18N_*` — recomposes
automatically and needs no change.

**Critical detail:** the Ambrosian constants currently compose off `MISSALS_FOLDER`
(`MISSALS_FOLDER . '/ambrosian/...'`). Once `MISSALS_FOLDER` moves under `rite/roman/`,
that composition would wrongly resolve to `rite/roman/missals/ambrosian`. The Ambrosian
bases MUST be re-based onto `AMBROSIAN_RITE_FOLDER` as shown, not left composing off
`MISSALS_FOLDER`.

## Discovery Invariant (why the metadata index is unaffected)

The user flagged discovery as the key risk. Verified: every directory scan that builds
the calendars metadata index is glob-rooted on a **derived folder constant**, never on
`SOURCEDATA_FOLDER/*`. Because the constants recompose, each scan follows to the new
location and rebuilds the index identically. Confirmed scan roots:

Scan roots (all in `src/Services/CalendarMetadataProvider.php` unless noted):

- `buildNationalCalendarData` → `NATIONAL_CALENDARS_FOLDER->path() . '/*'`
- `buildDiocesanCalendarData` → `DIOCESAN_CALENDARS_FOLDER->path() . '/*'` (then `basename` per nation/diocese)
- `buildWiderRegionData` → `WIDER_REGIONS_FOLDER->path() . '/*'`
- `buildAmbrosianCalendarData` → `AMBROSIAN_TEMPORALE_I18N_FOLDER->path() . '/*.json'`
- `MissalMetadataMap` (`src/Models/MissalsPath/`) → `MISSALS_FOLDER->path() . '/propriumdesanctis*'`
- `ResourceExistenceChecker` (diocesan) → `DIOCESAN_CALENDARS_FOLDER->path() . '/*/{id}'`
- `DecreesHandler` / `TemporaleHandler` / `TestsHandler` / `SchemasHandler` → respective `JsonData::*_FOLDER->path()`

`WiderRegionMembershipSeeder::computeTuples($nationsDir)` takes its root as a parameter
(the caller passes the constant), so it follows too. **No scanner globs
`SOURCEDATA_FOLDER/*` directly**, so the new `rite/` child of `sourcedata/` is invisible
to discovery. The plan will re-grep to assert this invariant still holds at
implementation time.

## Scope

### In scope

- Introduce `ROMAN_RITE_FOLDER` / `AMBROSIAN_RITE_FOLDER`; re-base the four Roman folder
  constants and the two Ambrosian base-folder constants (`JsonDataConstants.php`).
- `git mv` the physical folders into the new tree (history-preserving).
- Create `rite/ambrosian/calendars/dioceses/.gitkeep`.
- Update the "Evaluates to …" docblocks in `JsonData.php` / `JsonDataConstants.php` so
  they remain truthful.
- Fix literal `sourcedata/…` path references in `phpunit_tests/` (~20 occurrences).
- Verify the Dockerfile COPYs `jsondata/` wholesale (per project memory it does) so the
  image picks up the new tree with no Dockerfile edit; adjust only if it enumerates
  subfolders.

### Out of scope (explicitly deferred to later milestones)

- Rite-aware **discovery** (indexing Ambrosian dioceses under a rite dimension).
- `DiocesanCalendar` schema `rite`/`supported_rites` field and data-driven whitelist.
- The diocese→nation decoupling ("skip national tier when Ambrosian").
- The `applyAmbrosianDiocesanCalendar` overlay step in the orchestrator.
- Any diocesan **data** files (Milano/Bergamo/Novara/Lugano).
- The two-tier città-di-Milano church-dedication tier.
- Rite-parameterized constant **helpers** (e.g. `JsonData::forRite($rite)->…`). This
  milestone keeps the constant API stable — only the string values move. A parameterized
  helper, if wanted, belongs in the diocesan milestone where rite-branched loading is
  actually exercised.

## Verification

- **Primary gate: golden-master 9/9 byte-identical** (`CalendarGoldenMasterTest`, the
  Plan-1 harness; requires `.env.local`). A pure move must not perturb a single byte of
  Roman output. Ambrosian output likewise unchanged.
- `composer test` green (including the fixed test path references).
- `composer analyse` (PHPStan level 10) clean.
- `SchemaValidationTest` still resolves and validates every source file at its new path.
- Live smoke test in the frontend docker stack: `GET /calendar/2025`,
  `/calendar/ambrosian/2025?year_type=CIVIL`, `/calendars`, `/events`, `/events/ambrosian`,
  `/missals`, `/decrees` — diff responses against pre-migration captures; expect no diff.
- `composer lint` / `parallel-lint` clean; markdown lint on this spec.

## Risks & Mitigations

- **An Ambrosian constant still composes off `MISSALS_FOLDER`** and resolves under
  `rite/roman/`. → Re-base both Ambrosian bases onto `AMBROSIAN_RITE_FOLDER`; assert file
  existence in a test; golden-master + Ambrosian smoke test catch it.
- **A scan globs `SOURCEDATA_FOLDER/*` and now sees `rite/`.** → Verified none do; the
  plan re-greps `glob(` / `scandir(` / `DirectoryIterator` roots before merge.
- **A hardcoded literal path outside the constants (docblock or test) rots.** → Grep
  `sourcedata/(calendars|decrees|lectionary|missals)` across `src/` + `phpunit_tests/`;
  fix; docblocks updated.
- **Docker image misses the moved tree.** → Confirm `COPY jsondata/` is wholesale;
  smoke-test the container.
- **`git mv` loses history or a stray file is left behind.** → Move whole folders;
  `git status` must show only renames + the constant/test edits; no untracked or deleted
  data.

## Downstream Roadmap (context, not built here)

This milestone unblocks, as separate spec→plan cycles:

1. **Diocesan-overlay infrastructure + four diocese-level Ambrosian calendars** —
   rite-aware discovery keyed off `rite/ambrosian/calendars/dioceses/`; the "skip
   national tier when Ambrosian" decoupling (removes the `loadDiocesanCalendarData`
   diocese→nation coupling that would throw for `lugano_ch` and wrongly load Roman IT
   for the three Italian dioceses); the `applyAmbrosianDiocesanCalendar` step slotted
   **before** precedence resolution so diocesan events get season-stamped and participate
   in the add-all-then-resolve-to-fixpoint; shared "Nell'arcidiocesi di Milano e nella
   diocesi di X" rows duplicated into both the Milan and the X calendar. Milan is
   modeled at archdiocese level here.

2. **Two-tier città-di-Milano church-dedication tier** — the 47 basilica-specific rows
   (`A Milano, nella basilica di S. Ambrogio: …`) as a sub-diocesan layer distinct from
   the archdiocese calendar, matching the Missal's own three-way split (comune ambrosiano
   / proprio dell'Arcidiocesi di Milano / proprio della città di Milano).
