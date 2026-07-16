# Issue #709 — Reconcile inline OpenAPI response schemas with their schema files

**Date:** 2026-07-16
**Issue:** [Liturgical-Calendar/LiturgicalCalendarAPI#709](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/709)

## Problem

`jsondata/schemas/openapi.json` inlines several endpoint response schemas instead of `$ref`-ing the
authoritative standalone schema files in `jsondata/schemas/`. Inline copies drift. PR #708 fixed this
class of drift for the decrees/events endpoints; this issue covers the remaining cases.

## Findings (exploration, 2026-07-16)

All five standalone files are actively used by Health checks (`src/Health.php` maps each route to its
file via `LitSchema`) and every one of them **validates the current live response** (verified with
swaggest against a running API). The files are the authoritative side everywhere; the inline OpenAPI
copies are the drifted side. The issue's "near-empty stub files" concern is outdated — the files were
fleshed out after the issue was filed.

| Route                        | File (validates live ✔)                                     | Inline OpenAPI state                                                                                                                                                                        |
|------------------------------|-------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `GET`/`POST /calendars`      | `LitCalMetadata.json`                                       | `#/components/schemas/LitCalMetadata` drifted: missing `locales`/`timezone` on diocesan calendars, `wider_region` vs `wider_regions` on national calendars, missing top-level `api_path`, … |
| `GET /easter`                | `LitCalEasterPath.json`                                     | Inline property is `EasterDates`; the API returns `litcal_easter`. Wrong.                                                                                                                   |
| `GET /tests`                 | `LitCalTestsPath.json`                                      | Inline `#/components/schemas/UnitTestArray` is a bare array; the real response is a `{litcal_tests: […]}` wrapper.                                                                          |
| `GET /schemas`               | `LitCalSchemasPath.json`                                    | The `/schemas` path is not documented in openapi.json at all.                                                                                                                               |
| `GET /data/{category}/{key}` | `LitCalDataPath.json` (oneOf of the three calendar schemas) | openapi already `$ref`s the three calendar schema files per category — more precise than the file. No drift.                                                                                |

`ExactCorrespondence*UnitTest` components have references beyond `UnitTestArray` and must be kept.

## Decisions

1. **Scope: all four routes** — `/calendars`, `/easter`, `/tests` (same drift class, not in the issue's
   table), and adding the missing `/schemas` path documentation.
2. **The standalone files are authoritative as-is** — no content changes to any schema file; only
   openapi.json is reconciled.
3. **CI protection: yes, for all four** — new in-process response-validation tests (the
   `DecreesHandlerResponseSchemaTest` pattern from #314), since Health checks only run via the external
   WebSocket interface.
4. **`LitCalDataPath.json` stays untouched** — it is Health's `/data` wrapper; openapi's per-category
   refs are more precise and both remain. The issue's "flesh out or delete" item resolves as "already
   fleshed out".

## Design

### 1. `jsondata/schemas/openapi.json`

- `GET /calendars` and `POST /calendars` 200 response schema → `{"$ref": "./LitCalMetadata.json"}`
  (replaces both refs to `#/components/schemas/LitCalMetadata`).
- `GET /easter` 200 response schema → `{"$ref": "./LitCalEasterPath.json"}`. The response-level
  `description` (including the "8417 items" note) is untouched.
- `GET /tests` 200 response schema → `{"$ref": "./LitCalTestsPath.json"}`.
- New `/schemas` path entry: GET operation with summary/description/tags consistent with neighboring
  read-only paths, 200 response schema → `{"$ref": "./LitCalSchemasPath.json"}`. Index route only; the
  `/schemas/{name}` subpath stays undocumented (out of scope).

### 2. Orphaned component cleanup

After repointing, delete `components/schemas` entries with zero remaining references: `LitCalMetadata`
and `UnitTestArray` for certain, plus any sub-components referenced only by them (enumerated by a
scripted ref-count during implementation). `ExactCorrespondence*UnitTest` components are kept (still
referenced). Guard: the scripted ref-count plus `composer lint:openapi`.

### 3. New test class: `phpunit_tests/Handlers/ReadonlyPathsResponseSchemaTest.php`

Extends `AbstractHandlerTestCase`. Pins `Router::$apiPath = 'http://localhost:8000'` in
`setUpBeforeClass()` (after `parent::setUpBeforeClass()`), because the URL patterns in
`LitCalMetadata.json` and `LitCalSchemasPath.json` require absolute URLs; the parent's
`tearDownAfterClass()` restores the saved value. Four tests, each invoking the real handler in-process
and validating the decoded JSON body with swaggest against the file:

| Test               | Handler           | Request          | Schema (via `LitSchema`)      |
|--------------------|-------------------|------------------|-------------------------------|
| calendars response | `MetadataHandler` | `GET /calendars` | `LitSchema::METADATA->path()` |
| easter response    | `EasterHandler`   | `GET /easter`    | `LitSchema::EASTER->path()`   |
| tests response     | `TestsHandler`    | `GET /tests`     | `LitSchema::TESTS->path()`    |
| schemas response   | `SchemasHandler`  | `GET /schemas`   | `LitSchema::SCHEMAS->path()`  |

A separate consolidated class (rather than adding methods to the four existing handler test classes)
because the `apiPath` pin is class-level and the existing classes run with `apiPath = ''`. The easter
test is marked `@group slow` only if its measured runtime warrants it (repo rule: the group is reserved
for measurable cost).

### 4. Deliberately unchanged

The five standalone schema files; `LitCalDataPath.json` and everything about `/data`;
`ExactCorrespondence*UnitTest` components; the `/schemas/{name}` subpath.

## Acceptance

- `/calendars` (GET+POST), `/easter`, `/tests` 200 responses `$ref` their schema files; `/schemas` path
  documented with its file ref.
- No orphaned `components/schemas` entries remain from the swap.
- `composer lint:openapi` clean; `SchemaValidationTest` green; new response tests green; full
  `composer test` green.
- Issue #709 closeable, noting the stub-files item resolved as already-fleshed-out.

## Out of scope

- Documenting `/schemas/{name}` in openapi.json.
- Any change to `/data` documentation or `LitCalDataPath.json`.
- The pre-existing single-decree `allOf` concern (resolved separately on 2026-07-16 via
  `SingleDecreeResponse`).
