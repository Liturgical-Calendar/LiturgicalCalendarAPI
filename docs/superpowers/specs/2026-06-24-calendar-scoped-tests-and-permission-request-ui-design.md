# Calendar-scoped tests + permission-request UI — Design

**Date:** 2026-06-24
**Status:** Approved (design); pending spec review
**Repos:** LiturgicalCalendarAPI (authorization model, API, migration) · LiturgicalCalendarFrontend (UI)

## Goal

Two related changes to the fine-grained permissions system:

1. **Authorization-model change:** replace the single `test_definition` OpenFGA object type
   with three **calendar-scoped** test types, so a test grant is scoped to the calendar a
   test targets (General Roman, a nation, or a diocese) instead of to an individual test id.
2. **Permission-request UI** (`permission-requests.php`): make the per-permission row clearer
   — Relation first, an auto-populated Object ID dropdown, and project-tailored labels.

## Background (current state)

- OpenFGA model (`scripts/openfga-model.json`) is **flat**: types `user`, `wider_region`,
  `national_calendar`, `diocesan_calendar`, `general_roman_calendar`, `test_definition`; each
  resource type has direct `admin/viewer/editor/deleter` relations (no hierarchy/rewrites).
- `test_definition` grants are per **test id** today. `OpenFgaAuthorizationMiddleware`
  maps `/tests/{id}` → `test_definition:{test_id}`.
- Tests live in `jsondata/tests/*.json` and **already record their scope** via an `applies_to`
  field: `{ "diocesan_calendar": "<id>" }`, `{ "national_calendar": "<code>" }`, or **absent**
  (= General Roman Calendar; GRC tests carry no sub-scope).
- The request UI builds permission rows in **vanilla JS** (`assets/js/permission-requests.js`,
  inline-loaded, no ES modules/importmap). Object Type is a `<select>`; Object ID is free text
  except for GRC (already a `<select>` from the hardcoded `GRC_OBJECT_IDS`); Relation is a
  `<select>` (`admin/viewer/editor/deleter`). Field order today: Type → ID → Relation. The same
  row UI and `GRC_OBJECT_IDS` copy also live in `assets/js/admin-permissions.js`.
- Valid object types are centralized in `AccessRequestRepository::VALID_OBJECT_TYPES` and the
  per-role map; `AccessRequestHandler` validates them; `openapi.json` enumerates them.

## Part 1 — Authorization model

### New types (replace `test_definition`)

Three flat types, each with direct `admin/viewer/editor/deleter` (identical shape to the
calendar types):

| Type                          | object_id                                           | Meaning                                         |
| ----------------------------- | --------------------------------------------------- | ----------------------------------------------- |
| `national_calendar_test`      | nation code (e.g. `IT`)                             | edit/admin all tests for that national calendar |
| `diocesan_calendar_test`      | diocese id (e.g. `rotter_nl`)                       | …for that diocesan calendar                     |
| `general_roman_calendar_test` | **single fixed** object_id `general_roman_calendar` | …for the GRC tests (no sub-scope)               |

Decision: GRC tests are a **single object** (not sub-scoped by `GRC_OBJECT_IDS`), because
`applies_to` carries no GRC sub-scope and authz could not enforce one.

### `/tests` authz resolution

Replace the fixed `forTestDefinition()` (`test_definition:{test_id}`) with a resolver that
reads the target test's `applies_to`:

- `applies_to.diocesan_calendar = X` → check `diocesan_calendar_test:X`
- `applies_to.national_calendar = X` → check `national_calendar_test:X`
- absent/empty → check `general_roman_calendar_test:general_roman_calendar`

The middleware must load the test (by id) to read `applies_to`. If a test id is unknown,
fail closed (deny / 404 as today).

### Model deployment ordering (no downtime)

OpenFGA models are versioned; write a new model version that **adds** the three new types
(keep `test_definition` during transition). Sequence: (1) apply new model version →
(2) migrate tuples → (3) deploy API → (4) deploy frontend → (5) later model version may drop
`test_definition` once no tuples/usages remain.

## Part 2 — Tuple migration (auto-remap, idempotent)

One-off, re-runnable migration:

1. Enumerate existing `test_definition` tuples (all relations) from the store.
2. For each, resolve the test id's `applies_to` → target scoped type+object_id.
3. Write the equivalent scoped tuple (`{scoped_type}:{id}#{relation}@user:{uid}`); idempotent
   (no-op if present).
4. Delete the old `test_definition` tuple.

Log a summary (counts, any unresolved test ids — those are skipped and reported, not dropped
silently). Expected volume is low (RBAC is new), but the script must not assume zero.

## Part 3 — API changes

- `AccessRequestRepository::VALID_OBJECT_TYPES` and the per-role map: replace `test_definition`
  with the three new types for `test_editor` and `developer`.
- `AccessRequestHandler` validation messages updated accordingly.
- `OpenFgaAuthorizationMiddleware`: the `/tests` mapping (above) + any `OBJECT_TYPE_MAP` entries.
- `openapi.json`: object_type enums in the access-request request/response schemas.
- Grant/approve + cascade/revoke paths (`RoleCascadeService`, outbox) handle the new types like
  any other resource type (they are structurally identical — flat, 4 relations).

## Part 4 — Frontend changes

Files: `permission-requests.php` (+ inline JS / `assets/js/permission-requests.js`) and the
shared row UI in `assets/js/admin-permissions.js`.

1. **Field order:** Relation → Calendar scope → Calendar ID. (Object ID still depends on Object
   Type, so scope precedes id; Relation is independent and moves first per request.)
2. **Object ID = metadata-driven dropdown** for every type. Fetch the public `/calendars`
   metadata once (`credentials: 'omit'`), then per object type build native `<select>` options:
   - `national_calendar` → nations; `diocesan_calendar` → dioceses (grouped by nation via
     `<optgroup>`); `wider_region` → the five regions; `general_roman_calendar` → `GRC_OBJECT_IDS`.
   - The three test types **reuse the same lists** (national/diocesan), and
     `general_roman_calendar_test` → the single fixed value.
   - Keep an empty placeholder forcing an explicit choice (as GRC does today).
3. **Labels / terminology:** "Object Type" → **"Calendar scope"**, "Object ID" → **"Calendar ID"**
   (i18n strings). The object-type dropdown lists, per role: the calendar/wider-region types plus
   **"General Roman Calendar Tests" / "National Calendar Tests" / "Diocesan Calendar Tests"**.
4. Keep `GRC_OBJECT_IDS` in sync across `permission-requests.js`, `admin-permissions.js`, and the
   API constant (existing convention).

## Object_id semantics (summary)

| Object type (frontend "Calendar scope")     | Calendar ID source                                     |
| ------------------------------------------- | ------------------------------------------------------ |
| National Calendar / National Calendar Tests | `/calendars` national list (nation codes)              |
| Diocesan Calendar / Diocesan Calendar Tests | `/calendars` diocesan list (grouped by nation)         |
| Wider Region                                | Americas/Europe/Asia/Africa/Oceania                    |
| General Roman Calendar                      | `GRC_OBJECT_IDS` (temporale, typica editions, decrees) |
| General Roman Calendar Tests                | single fixed `general_roman_calendar`                  |

## Testing

- API: update `OpenFgaModelTest`, `AccessRequestRepository`/`AccessRequestHandler` tests, and
  `OpenFgaAuthorizationMiddleware` tests for the new types + `applies_to` resolution; add tests
  for the resolver (each `applies_to` shape + unknown id → deny). Migration: unit-test the
  remap mapping; dry-run on a seeded store.
- Frontend: extend the RBAC e2e where it touches test scopes; manual check of the new dropdowns
  per role.
- The deployed dev stack (`/api/dev`) is the integration target; apply the model + migration
  there first.

## Risks / notes

- **Model + migration must precede API** that validates against the new types, else valid
  requests 422 / authz checks miss. Follow the ordering above.
- The `/tests` resolver adds a test-lookup per authz check; cache/load tests efficiently
  (the handler already reads test data).
- `test_definition` removal from the model is deferred to a later version to keep migration safe.
- Frontend `/calendars` fetch must use `credentials: 'omit'` (wildcard-CORS public endpoint).
