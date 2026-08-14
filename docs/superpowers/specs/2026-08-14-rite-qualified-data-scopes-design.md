# Rite-qualified data resource scopes and a rite-aware `/data`

Design for [issue #786](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/786).

Follows [#785](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/pull/785), which did the same
for the *test* scopes and deferred the data resource types to this change.

## Problem

Three related defects, one of them live.

**1. Ambrosian dioceses read as deleted.** `ResourceExistenceChecker::exists()` resolves a diocesan calendar
by globbing `JsonData::DIOCESAN_CALENDARS_FOLDER`, which derives from `ROMAN_RITE_FOLDER`. The Ambrosian
partition has its own constant, `AMBROSIAN_DIOCESAN_CALENDARS_FOLDER`, that the checker never consults:

```text
ResourceExistenceChecker::exists('diocesan_calendar', ...)
  rotter_nl  (roman)     => true
  lugano_ch  (ambrosian) => false
  milano_it  (ambrosian) => false
  bergam_it  (ambrosian) => false
  novara_it  (ambrosian) => false
```

`ResourceTuplePurgeReconciler` purges on exactly that predicate, so a legitimate
`diocesan_calendar:milano_it` grant looks orphaned and is revoked on any sweep. This is silent permission
loss, live today.

**2. `/data` cannot address Ambrosian calendars.** `/data/diocese/lugano_ch` is a 404 (`/data/diocese/rotter_nl`
reaches the handler and 422s on validation). `RegionalDataHandler` resolves every path through Roman-only
`JsonData` constants, in ~20 call sites across read/create/update/delete.

**3. Data resource object ids are ambiguous.** `diocesan_calendar:lugano_ch` does not identify a calendar.
The source tree is partitioned by rite, so nothing prevents the same diocese existing under both; a grant on
the bare id would silently widen to cover a Roman `lugano_ch` the moment one was added.

## Sequencing

The issue proposes, and this follows, four ordered commits:

1. The existence-checker fix — self-contained, stops live permission loss, depends on nothing else.
2. `/data` rite-awareness — there is little point authorizing `diocesan_calendar:ambrosian/lugano_ch` while
   no route can address that calendar.
3. The object-id change.
4. Tuple migration + runbook.

## 1. Existence checker

`diocesan_calendar` globs both rite partitions. Once ids are rite-qualified (step 3) the rite could select a
single partition, but the checker must keep accepting unqualified legacy ids for the whole migration window —
and this method decides what gets *purged*, so it stays deliberately permissive: it answers "does a calendar
of this id exist under any rite", and only returns false when none does.

`national_calendar` and `wider_region` remain Roman-only lookups (no Ambrosian tier exists), tolerating an
optional `roman/` prefix once step 3 lands.

## 2. `/data` rite-awareness

`Router::extractRiteSegment()` already strips a leading rite segment for `calendar`, `events` and the root
route. `data` joins that list, so `/data/ambrosian/diocese/lugano_ch` parses to the same 0/1/2-part shape the
handler already expects and every downstream count-based branch is untouched.

`canonicalRiteUrl()` deliberately does **not** gain `data`. It advertises the explicit form for cacheable
read routes; `/data` is an admin write surface where a `Link: rel="canonical"` header on a `PUT` is noise.

Path resolution follows the pattern the Ambrosian rollout established in `CalendarHandler` and
`EventsHandler`: an explicit branch on `Rite::AMBROSIAN` selecting the `AMBROSIAN_*` constant, paired with a
`validateRiteCompatibility()` on the params object. `RegionalDataParams` gains the rite and rejects, mirroring
`EventsParams::validateRiteCompatibility()`:

- `nation` or `widerregion` under a non-Roman rite — the Ambrosian rite has neither tier, and there are no
  `AMBROSIAN_NATIONAL_*` / `AMBROSIAN_WIDER_REGION_*` constants because the data does not exist;
- a diocese whose metadata rite differs from the requested rite.

To avoid an `if (rite)` at each of ~20 call sites, the rite-partitioned constants are selected once through
small static resolvers on `JsonData` (`diocesanCalendarFileFor(Rite)`, `diocesanCalendarI18nFolderFor(Rite)`,
…), so a call site becomes `JsonData::diocesanCalendarFileFor($rite)->path()`.

## 3. Rite-qualified object ids

| Object type              | Id before   | Id after              |
|--------------------------|-------------|-----------------------|
| `diocesan_calendar`      | `lugano_ch` | `ambrosian/lugano_ch` |
| `national_calendar`      | `US`        | `roman/US`            |
| `wider_region`           | `Europe`    | `roman/Europe`        |
| `general_roman_calendar` | `temporale` | *(unchanged)*         |

Same `<rite>/<id>` format as the test scopes, same separator, same rationale.

`wider_region` **is** qualified. The issue left this open (design question 2) and the instruction that
prompted the change named only diocesan and national calendars. Qualifying it anyway is the choice here
because wider regions live in the same rite-partitioned tree (`rite/roman/calendars/wider_regions`) as the
other two, so exempting them would mean the model qualifies two of the three calendar-data types that sit
side by side in the same directory — a carve-out that has to be remembered rather than a rule that can be
stated. **Flagged for review**: it costs extra migration surface for no present benefit, and reverting it is
a one-line change to the mapping plus its migration branch.

`general_roman_calendar` stays bare. Its ids are not calendars — they are `temporale`, `decrees` and missal
editions — and they are Roman by construction.

`TestScopeResolver::qualify()` / `::parseQualifiedId()` move to a neutral home, since the data types now use
them too and a repository depending on a *test* service for its id format would be backwards. New home:
`src/Services/RiteScopedObjectId.php` — a tiny final class holding the separator, `qualify()` and `parse()`.
`TestScopeResolver` keeps thin delegating wrappers so #785's public surface does not break.

Touched surfaces:

- `src/Http/Middleware/OpenFgaAuthorizationMiddleware.php` — `forCalendarData()` and `forMissals()` compose
  qualified ids; `OBJECT_TYPE_MAP` unchanged
- `src/Handlers/RegionalDataHandler.php` — `fgaObjectTypeForCategory()` and the delete-path outbox rows
- `src/Repositories/AccessRequestRepository.php` — `isValidObjectIdForType()`, `validIdsLabelForType()`
- `src/Services/ResourceExistenceChecker.php` — parse the prefix
- `scripts/reconcile-resource-tuples.php` — unchanged, but its behaviour changes with the checker
- `jsondata/schemas/openapi.json`, `docs/ops/`

No OpenFGA **model** change: the object types are unchanged and only their ids move, so unlike #785 there is
no new type to add and no model version to ship.

## 4. Migration

`scripts/migrate-rite-data-tuples.php`, mirroring `migrate-rite-test-tuples.php`: copy-only by default,
`--prune` gated behind full rollout, idempotent, already-qualified ids recognised and skipped.

Each calendar's rite is inferred from the rite-partitioned source tree, which is the authority. An id defined
under two rites, or under none, is reported and skipped — never guessed — and the run exits `2`.

These are **production calendar-editing grants**, not test-authoring scopes, so the rollback story matters
more than it did in #785: the unqualified ids stay valid in every allow-list until the prune step.

## Out of scope

- `/tests` rite-awareness — [#787](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/787),
  which defers its path-segment decision to whatever this change settles.
- Frontend admin calendar pages, which construct these object ids —
  [LiturgicalCalendarFrontend#459](https://github.com/Liturgical-Calendar/LiturgicalCalendarFrontend/issues/459)
  covers the test editor; the calendar pages need the same treatment.
- Pruning the legacy unqualified tuples, and the #785 test-scope ones, once every deployment runs merged code.

## Testing

- `ResourceExistenceCheckerTest` — all four Ambrosian dioceses exist; unknown ids still do not; qualified and
  unqualified ids both resolve.
- A reconciler test proving an Ambrosian diocesan tuple is no longer purged — the regression that motivated
  the issue.
- `RouterRiteSegmentTest` — `/data` strips the rite segment; `/data/ambrosian/nation/IT` is rejected.
- `RegionalDataParams` rite-compatibility tests mirroring the `EventsParams` ones.
- Middleware tests for the qualified ids on `forCalendarData()` / `forMissals()`.
- Live probes of every `/data` path shape the router can now emit.
