# Rite-scoped liturgical tests

Design for [issue #767](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/767).

## Problem

`jsondata/tests/*.json` cannot be scoped to a rite. Two consequences:

1. **Rite-level calendars are inexpressible.** `AppliesToOrExcludes` in `jsondata/schemas/LitCalTest.json`
   sets `additionalProperties: false` and permits only `national_calendar(s)` / `diocesan_calendar(s)`.
   `GET /calendar/ambrosian` is neither national nor diocesan, so no test can name it.
   `TestScopeResolver::mapAppliesTo()` silently defaults an unscoped test to the General Roman Calendar,
   so an Ambrosian test written without a scope runs against the GRC and fails every assertion.

2. **Ambrosian dioceses are expressible but unrunnable.** `Health::buildCalendarRequestPath()` emits
   `/diocese/{id}/{year}`, with no rite segment. The router requires one for the four Ambrosian dioceses
   (`milano_it`, `bergam_it`, `novara_it`, `lugano_ch`), so such a test requests a URL that 400s.

Verified against a running instance:

| Path                                                         | Status |
|--------------------------------------------------------------|--------|
| `/calendar/roman/2026?year_type=CIVIL`                       | 200    |
| `/calendar/roman/nation/US/2026?year_type=CIVIL`             | 200    |
| `/calendar/roman/diocese/rotter_nl/2026?year_type=CIVIL`     | 200    |
| `/calendar/ambrosian/2026?year_type=CIVIL`                   | 200    |
| `/calendar/ambrosian/diocese/lugano_ch/2026?year_type=CIVIL` | 200    |
| `/calendar/diocese/lugano_ch/2026?year_type=CIVIL`           | 400    |
| `/calendar/ambrosian/nation/IT/2026?year_type=CIVIL`         | 400    |

The last row matters: the Ambrosian rite has a rite-level calendar and diocesan calendars, but no
national calendars.

## Decisions

The issue posed three design questions; the maintainer answered all three in the issue thread.

1. **The rite is an independent axis, declared explicitly.** It is *not* inferred from the calendar id.
   `/calendars` metadata does currently carry a `rite` per diocesan calendar, but that is incidental:
   `lugano_ch` happens to have only an Ambrosian calendar today and could later have a Roman one too.

2. **The rite-level calendar gets its own FGA object type**, `rite_calendar_test`, keyed by rite id
   (`roman`, `ambrosian`). This generalises the existing `general_roman_calendar_test`, whose single
   fixed id `general_roman_calendar` is exactly "the Roman rite-level calendar".

3. **An unscoped test is no longer valid.** `applies_to` becomes required, and `rite` becomes required
   inside it. Ten test files exist; scoping them all to `roman` is cheap and removes a whole class of
   silent misconfiguration.

## Scope shape

`applies_to` gains a required `rite` and drops the "at least one calendar key" requirement:

```json
{ "applies_to": { "rite": "roman" } }
{ "applies_to": { "rite": "roman", "national_calendar": "US" } }
{ "applies_to": { "rite": "ambrosian" } }
{ "applies_to": { "rite": "ambrosian", "diocesan_calendar": "lugano_ch" } }
```

The Ambrosian rite is proper to the Archdiocese of Milan and a handful of neighbouring dioceses rather
than to any nation, and `/calendar/ambrosian/nation/{id}` is a 400 — so `rite: ambrosian` combined with
`national_calendar` / `national_calendars` is rejected, by a conditional in the schema and by the same
rule in `TestItem`. Without that, a test could pass corpus validation and fail only when the runner
issued its request: the same expressible-but-unrunnable trap as gap 2.

`excludes` keeps its current shape and does **not** accept `rite`. `excludes` narrows the set of
calendars a test applies to; the rite is already pinned by `applies_to`, so excluding a rite is
meaningless. The two therefore stop sharing one schema definition: `AppliesToScope` (rite required) and
`ExcludesScope` (the current definition, unchanged).

## Authorization

`TestScopeResolver::mapAppliesTo()` becomes:

| `applies_to`                           | FGA scope                     |
|----------------------------------------|-------------------------------|
| `{"diocesan_calendar": "<id>"}`        | `diocesan_calendar_test:<id>` |
| `{"national_calendar": "<id>"}`        | `national_calendar_test:<id>` |
| `{"rite": "<rite>"}` (no calendar key) | `rite_calendar_test:<rite>`   |
| absent / unrecognised                  | `rite_calendar_test:roman`    |

The diocesan and national branches keep precedence and stay keyed by calendar id alone. Diocesan and
national calendar ids are globally unique across rites today, and the *data* resource types
(`diocesan_calendar`, `national_calendar`) are keyed the same way — rite-qualifying only the test scope
would make the two halves of the model disagree. If a calendar id ever needs to be rite-qualified, that
is a change to the whole resource model, not to tests, and is out of scope here.

`general_roman_calendar_test` is **not removed**. It stays in the FGA model and in every PHP allow-list
so existing tuples keep authorizing, following the additive-model pattern already used in this repo
(`scripts/openfga-model.additive.json`, `docs/ops/test-scope-migration-runbook.md`). A migration script
copies each `general_roman_calendar_test:general_roman_calendar` tuple onto `rite_calendar_test:roman`.
Dropping the old type is a follow-up, once every deployment runs merged code.

Touched surfaces:

- `scripts/openfga-model.additive.json` — new `rite_calendar_test` type
- `authz/openfga-expectations.json` — `required_types`
- `src/Services/TestScopeResolver.php` — the mapping
- `src/Services/ResourceExistenceChecker.php` — resource type; always exists
- `src/Repositories/AccessRequestRepository.php` — `OBJECT_TYPES`, `ROLE_OBJECT_TYPES`, id validation
  (a `rite_calendar_test` id must be a valid `Rite` value)
- `src/Services/ResourceAdminService.php` — `TEST_OBJECT_TYPES`, `VIEWER_OBJECT_TYPES`
- `scripts/migrate-rite-test-tuples.php` + runbook section

## Runner

`Health::buildCalendarRequestPath()` takes a `Rite` and always emits the canonical explicit segment
(`/calendar/roman/...`), the form the `Rite` enum documents as canonical:

| category           | path                                                |
|--------------------|-----------------------------------------------------|
| `ritecalendar`     | `/{rite}/{year}?year_type=CIVIL`                    |
| `nationalcalendar` | `/{rite}/nation/{calendar}/{year}?year_type=CIVIL`  |
| `diocesancalendar` | `/{rite}/diocese/{calendar}/{year}?year_type=CIVIL` |

`ritecalendar` is a new category for the rite-level calendar. The existing `calendar === 'VA'` special
case (which already means "the universal calendar", not `/nation/VA`) keeps working and resolves the
same way as `ritecalendar`.

The rite reaching that method is resolved by `Health::resolveRite()`:

1. an explicit `rite` property on the WebSocket message, when present and a valid `Rite`;
2. for `diocesancalendar`, the rite announced for that diocese in `/calendars` metadata
   (`diocesan_calendars[].rite`);
3. for `ritecalendar`, the calendar id itself read as a `Rite`;
4. otherwise `Rite::default()`.

National calendars are Roman-only — `/calendars` announces no `rite` for them and
`/calendar/ambrosian/nation/IT` 400s — so `nationalcalendar` falls through to step 4.

Step 2 keeps the existing UnitTestInterface client working unchanged — it fixes gap 2 without a client
release — while step 1 is the forward path once clients are rite-aware, and is the authority when both
are present. `rite` is optional on the message, so `ACTION_PROPERTIES` (which lists *required* props)
is unchanged.

## Failing loudly

`/calendar` responses already echo `settings.rite` (issue #760). `LitTestRunner` uses it: if the
response's rite differs from the test's declared `applies_to.rite`, it reports one clear error
("test is scoped to rite X, calendar returned rite Y") instead of running assertions that would all
fail for the wrong reason. This is the direct antidote to the failure mode that motivated the issue.

`LitTestRunner::getCalendarName()` also learns to name the rite-level calendar
("the General Roman Calendar" / "the Ambrosian Calendar") rather than hard-coding
"the Universal Roman Calendar".

## Data

All ten existing test files get `"rite": "roman"`; the four with no `applies_to` at all gain
`"applies_to": {"rite": "roman"}`.

`StIgnatiusOfLoyolaTest.json` is restored, scoped `{"rite": "ambrosian"}`. The copy found on disk was
itself wrong: it asserts the memorial on 31 July for every year 1999–2030, but in the Ambrosian
calendar the memorial is suppressed when 31 July falls on a Sunday. Verified against a running
instance — 2005, 2011, 2016 and 2022 return no `StIgnatiusOfLoyola` event at all. Those four
assertions become `eventNotExists` / `expected_value: null`, matching the pattern already used in
`StCamillusDeLellisTest.json`.

## Out of scope

- **Frontend `admin-tests.php` scope picker** (`LiturgicalCalendarFrontend`). Requiring `applies_to.rite`
  is a breaking change for that editor's PUT/PATCH payloads, so it needs a companion change in that
  repo. Tracked separately.
- **Removing `general_roman_calendar_test`.** Additive now, removal once all deployments run merged code.
- **Rite-qualifying diocesan/national calendar ids** in the resource model.

## Testing

- `phpunit_tests/Test/TestItemTest.php` — rite required, invalid rite rejected, Ambrosian + national
  calendar rejected, `excludes` still rejects `rite`, typed `Rite` exposed.
- `phpunit_tests/Services/TestScopeResolverTest.php` — each mapping branch, including the legacy
  no-`applies_to` fallback to `rite_calendar_test:roman`.
- New `phpunit_tests/Health/BuildCalendarRequestPathTest.php` — path construction per category × rite,
  via reflection on the private method (the class needs no server).
- `phpunit_tests/Test/LitTestRunnerTest.php` — rite-mismatch guard.
- `phpunit_tests/Schemas/SchemaValidationTest.php::testRealTestSourceValidation` validates only
  `glob(...)[0]` — a single file, whichever sorts first. Widen it to a data provider over the whole
  `jsondata/tests/` corpus, so a test file that never gained a rite fails loudly instead of hiding
  behind its neighbours.
- Live probe of every path form the runner can now emit.
