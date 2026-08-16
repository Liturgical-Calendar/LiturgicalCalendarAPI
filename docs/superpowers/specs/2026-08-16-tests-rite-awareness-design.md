# Rite-aware `/tests`

Design for [issue #787](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/787).

Follows [#785](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/pull/785), which made the rite a
required property *inside* each test and rite-qualified the test authorization scopes, and
[#786](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/786), which settled the path-segment
convention on `/data`. #787 deferred its own segment decision to whatever #786 chose.

## Problem

Three gaps, in descending order of cost.

**1. The rite is not part of a test's identity.** Test names are globally unique file stems, so two tests that
differ only by rite must be disambiguated by hand in the name. That is already live: `StIgnatiusOfLoyolaTest`
is the *Ambrosian* memorial of St Ignatius of Loyola, and a Roman-rite test for the same saint would need some
other name, chosen by convention rather than enforced by structure.

**2. The collection cannot be filtered by rite.** `GET /tests` returns the full contents of every test file.
A client that wants "the Ambrosian tests" — UnitTestInterface when the user selects the Ambrosian calendar, or
the `admin-tests` editor — must fetch all of them and filter client-side on `applies_to.rite`.

**3. The resolved scope is not exposed.** Consumers re-derive the FGA scope from `applies_to`. `admin-tests`
does this in `deriveScope()`, a hand-copy of `TestScopeResolver` that has drifted twice in one release: the GRC
type was replaced, and the ids became rite-qualified.

This is **not** an authorization hole. `TestScopeResolver` reads `applies_to.rite` from the file, so
`/tests/StIgnatiusOfLoyolaTest` already authorizes against `rite_calendar_test:ambrosian`.

## Decisions

| Question                    | Decision                                                                       |
| --------------------------- | ------------------------------------------------------------------------------ |
| Rite in the test's identity | Path segment **and** rite-partitioned directory — full symmetry with `/data`   |
| Segment-less URLs           | Hard break: `/tests/{name}` is a 400, no deprecation window                    |
| Bare `GET /tests`           | Retained as the corpus-wide, all-rites index                                   |
| Expose the resolved scope   | Yes, inline on every test, in both the collection and the single-test response |

The first decision is the expensive one and was taken deliberately. A naming convention would have cost
nothing — the existing `name` pattern already accepts a rite prefix, because `[a-z_]+?_` matches a whole
lowercase-and-underscore run, so `ambrosian_StIgnatiusOfLoyolaTest` and even `ambrosian_rotter_nl_HLaurentiusTest`
validate against the current schema. A convention is unenforced, though, and the corpus is small enough that
restructuring it now is cheaper than restructuring it later.

## 1. File layout and identity

```text
jsondata/tests/{rite}/{name}.json
```

The 11 committed tests move: 10 into `roman/`, `StIgnatiusOfLoyolaTest.json` into `ambrosian/`. Content is
unchanged — every file already records the correct `applies_to.rite`.

Test names become unique **within a rite** rather than globally. That is the point of the change: a Roman
`StIgnatiusOfLoyolaTest` can coexist with the Ambrosian one, with no naming convention to remember and no way
to confuse them.

The `name` pattern in `LitCalTest.json` is **unchanged**. The optional lowercase prefix stays available for the
diocese scoping that `rotter_nl_HLaurentiusdiakenenmartelaarpatroonvanhetbisdomTest` already uses; the rite
never needs to appear in the name, because the directory carries it.

`TestScopeResolver::isSafeName()` continues to govern the name (`[A-Za-z0-9_-]+`), which is what keeps the
rite segment and the name unambiguous — see below.

## 2. Routing

`tests` deliberately does **not** join `Router::extractRiteSegment()`. That helper resolves an absent segment to
the default rite (Roman), and bare `GET /tests` must mean *all rites*. `/tests` therefore resolves its own
tri-state `?Rite`, where `null` means "every rite".

| Path                   | Parts after rite | Rite | Result                          |
| ---------------------- | ---------------- | ---- | ------------------------------- |
| `/tests`               | 0                | null | collection, all rites           |
| `/tests/{rite}`        | 0                | set  | collection for that rite        |
| `/tests/{rite}/{name}` | 1                | set  | single test                     |
| `/tests/{name}`        | 1                | null | **400** — rite segment required |
| 3 or more parts        | —                | —    | 400                             |

Row 4 is the hard break, and it is cleanly detectable: `Rite::tryFrom()` fails on the leading segment. No test
name can be mistaken for a rite, because the schema requires a `Test` suffix and the collection globs
`*Test.json`, so neither `roman` nor `ambrosian` can name a test.

The count-based method wiring in `Router::route()` is untouched. After the segment is stripped, the remaining
0/1-part shapes are exactly the ones the `case 'tests':` switch already configures.

`canonicalRiteUrl()` does **not** gain `tests`, for the same reason `/data` was excluded in #786: it advertises
the explicit form for cacheable read routes, and there is no bare form left to canonicalise anyway.

### Two sources of truth

`applies_to.rite` stays required and must agree with the path segment. Disagreement is a **422**, mirroring
`/data/roman/diocese/lugano_ch`. The directory is the address; `applies_to.rite` is the content; the handler
refuses to let them diverge. This applies to `PUT` and `PATCH`, where the payload could otherwise write a file
into a partition that contradicts it.

### Authorization

Unaffected. #785 already rite-qualified the test scopes (`diocesan_calendar_test:ambrosian/lugano_ch`), and
`TestScopeResolver` reads the rite from the file rather than the path. **No OpenFGA model change and no tuple
migration** — unlike #786, this change carries none of that weight.

## 3. Response shape: the resolved scope

Each test gains a server-computed `scope`:

```json
{
  "object_type": "rite_calendar_test",
  "object_id": "ambrosian"
}
```

### Why this needs a schema change rather than a bare field injection

All three test types in `LitCalTest.json` (`ExactCorrespondenceType`, `ExactCorrespondenceSinceType`,
`ExactCorrespondenceUntilType`) are `additionalProperties: false`, and `LitCalTestsPath.json` `$ref`s
`LitCalTest.json` directly for its array items. Injecting `scope` without a schema change would fail validation
on the response *and* inside `LitTestRunner`, which validates each file against the same schema.

Composing a separate response schema with `allOf` does not rescue it: `additionalProperties: false` in the base
schema cannot see sibling `allOf` properties, so that route would force relaxing the base and weaken corpus
validation.

So `scope` becomes an **optional, documented property on the three types in `LitCalTest.json`**, server-computed
and never present in a source file.

`PUT` and `PATCH` accept a payload whose `scope` **matches** what the server resolves, and reject with a 422 only
when the supplied `scope` **disagrees**. A bare-presence rejection was the first design here, on the reasoning that a
field which looks writable but is silently ignored is precisely how `deriveScope()` drifted. Both client analyses
(UnitTestInterface #39, LiturgicalCalendarFrontend #459) independently pushed back, and they are right: after this
change no legitimate client ever *originates* a `scope` value, so the only realistic way one appears in a write body
is a benign unedited echo of a `GET` response. Rejecting that punishes the ordinary load-edit-save cycle — UTI's
`admin.js` demonstrably round-trips the whole object — while catching nothing a mismatch check does not.

Matching-is-accepted keeps the drift signal that motivated the field: a client that hand-derives a scope and gets it
wrong still gets a loud 422. It is the silent divergence that must be impossible, not the echo.

## 4. Consumers

| Surface                                                   | Change                                                                                                      |
| --------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| `src/Enum/JsonDataConstants.php`, `src/Enum/JsonData.php` | `testsFolderFor(Rite)` resolver, mirroring #786's `diocesanCalendarFileFor()`                               |
| `src/Handlers/TestsHandler.php`                           | glob across partitions (bare) or one partition; read/write/delete take the rite; inject `scope`; 422 checks |
| `src/Services/TestScopeResolver.php`                      | `resolve(Rite $rite, string $testName)` — it currently builds `{testsDir}/{name}.json`                      |
| `src/Http/Middleware/OpenFgaAuthorizationMiddleware.php`  | `forTestScopes()` reads a new `test_rite` attribute alongside `test_id`                                     |
| `src/Router.php`                                          | tri-state rite resolution; sets `test_rite` (`:838` already sets `test_id`, which stays correct post-strip) |
| `src/Test/LitTestRunner.php`                              | resolves by bare `basename($Test)` today; needs the rite                                                    |
| `src/Health.php`                                          | already carries `rite` on `executeUnitTest` from #785; passes it through to `LitTestRunner`                 |
| `jsondata/schemas/LitCalTest.json`                        | optional `scope` property on the three types                                                                |
| `jsondata/schemas/openapi.json`                           | new path shapes; `scope` in the documented responses                                                        |

## 5. Migration

`git mv` of the 11 test files into `roman/` and `ambrosian/`. Content-identical, since every file already
records the right `applies_to.rite`. There is no data transformation and nothing to roll back beyond the move
itself — **in this repository.**

A deployed instance is not this repository, though. Any test created since #785 through `PUT /tests/{name}`
(the pre-#787 bare-name endpoint) lives only on that host's `jsondata/tests/` volume, flat, outside git
entirely — `git mv` cannot move what it never tracked, so such a file survives this deploy unmoved. Because
`collectTests()` now globs `{rite}/*Test.json`, a flat file one level up becomes invisible to `GET /tests` and
`GET /tests/{rite}`, unaddressable by `GET /tests/{rite}/{name}`, and undeletable through the API, while any
OpenFGA tuple scoping it stays live and orphaned. This is a real gap in an otherwise trivial migration, not a
hypothetical: nothing prevents a `PUT` from having landed on a deployed host between the #785 and #787
deploys. See the ops runbook's "Deployed-instance hazard — flat test files predating #787" section for the
detection command and manual remedy (move each such file into the partition matching its own
`applies_to.rite`). No migration script is provided for this — it is expected to touch at most a handful of
files per environment, and a script would need write access to a production volume for a one-time, easily
hand-verified move.

`phpunit_tests/Schemas/SchemaValidationTest.php:659` globs `jsondata/tests/*.json` and becomes recursive.

## Testing

- Router shape tests covering all five rows of the routing table, especially the 400 on bare `/tests/{name}`.
- A 422 test for path-segment vs `applies_to.rite` disagreement, on both `PUT` and `PATCH`.
- A `TestScopeResolver` test that the same name under two rites resolves to two different scopes — the
  collision this change exists to make possible.
- `scope` assertions on both the collection and the single-test response, plus a 422 when a write payload
  carries it.
- `LitTestRunner` resolution under both partitions.

## Out of scope

- **UnitTestInterface [#39](https://github.com/Liturgical-Calendar/UnitTestInterface/issues/39)** and
  **LiturgicalCalendarFrontend [#459](https://github.com/Liturgical-Calendar/LiturgicalCalendarFrontend/issues/459)**.
  Because this is a hard break, both must ship alongside it or `admin-tests` and the test runner break the day
  it lands. Whether the API change waits on them is a release-sequencing decision, not a design one.
- Pruning the superseded #785 and #786 tuples, which waits on every deployment running merged code.
- **A calendar filter on the collection** (`GET /tests?national_calendar=US`, or "tests applicable to calendar X").
  Both clients re-implement this selection logic client-side today, which is the same duplication argument that
  motivated exposing `scope`. Raised by the UnitTestInterface #39 analysis; deliberately not bundled here, because the
  rite dimension is what #787 exists to settle and a calendar filter is an independent ergonomics change.
- The `applies_to` plural forms (`national_calendars`, `diocesan_calendars`). They are schema-legal, unused by
  the corpus, and ignored by `TestScopeResolver::mapAppliesTo()`, which resolves such a test to the rite-level
  scope. That pre-existing gap is untouched here.
