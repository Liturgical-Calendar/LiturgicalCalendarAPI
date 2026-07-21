# Ambrosian Comune Sanctorale + Missal Resolution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for
> tracking.

**Goal:** Author the **comune ambrosiano sanctorale** (2024 edition, fixed-date saints) as source data, add
`AmbrosianMissal` + a rite-governed `MissalResolver`, extend the `PropriumDeSanctis` schema, and — the payoff
— assemble a real Ambrosian year (temporale + comune sanctorale) so `AmbrosianPrecedenceResolver` is finally
exercised against **real coincidences**, hardening it (iterative re-resolution) and closing the deferred #727
transfer items. The `/calendar/ambrosian` endpoint **stays 501** (request-path wiring is Plan 6).

**Architecture:** Milestone 1 of 4 for un-501-ing the Ambrosian calendar (this plan = data + resolution +
resolver hardening; Plan 6 = handler wiring/un-501; Plan 7 = diocesan overlays; Plan 8 = ordo-validation +
1976 backfill). The sanctorale mirrors the Roman `PropriumDeSanctis` shape
(`month`/`day`/`event_key`/`grade`/`common`/`calendar`/`color` + separate i18n), nested under
`missals/ambrosian/propriumdesanctis_2024/` exactly as Plan 3's temporale sits under
`missals/ambrosian/propriumdetempore/`. `is_dominical` (Plan 4) is source-carried on Lord-feasts and threaded
through `PropriumDeSanctisEvent` → `LiturgicalEvent::fromObject()` (the same additive pattern Plan 4 used for
the temporale event). A new `AmbrosianSanctoraleLoader` builds a `PropriumDeSanctisMap` from the resolved
edition; a new `MissalResolver` seam decides the edition per year. None of this is wired into
`CalendarHandler`; it is exercised by unit + integration tests only.

**Tech Stack:** PHP 8.4+, PHPUnit, PHPStan level 10, phpcs, JSON Schema (draft-07, Health-wired via `SchemaValidationTest`), gettext `_()`.

## Background — where this sits

Plans 1–4 (all merged) built: the `Rite` seam + routing (Ambrosian → 501), `AmbrosianTemporale` (the temporal
anchor block), and `AmbrosianPrecedenceResolver` + `AmbrosianLiturgicalDayRank` (the 13-rank *Tabella*,
unit-tested in isolation against **constructed** coincidences). Issue #727 tracks precedence items that were
deferred because they need **real sanctorale data** to resolve: the Lenten-ferie winner-transfer, and an
iterative re-resolution pass (a transfer can create a new coincidence at its destination). This plan supplies
that data and closes those items. The comune ambrosiano *is* the Ambrosian missals' proprium (like the General
Roman Calendar is the Roman missals' proprium) — there is **no** Ambrosian nation file.

**Source data:** the *Calendario Ambrosiano* in `scratchpad/ambrosian.txt`, lines **5084–5850** (GENNAIO …
DICEMBRE). Each entry gives a grade marker, day, and saint name/title; entries prefixed with a location (e.g.
*"A Milano, nella basilica di S. Ambrogio: S. Savina"*) are **diocesan** and are **skipped here** (Plan 7).
Two mobile "Festa dS" entries (*Battesimo del Signore*, *Santa Famiglia* — last Sunday of January) are
**temporale**, not fixed sanctorale, and are out of scope (Battesimo already exists in Plan 3; Santa Famiglia
belongs to the deferred after-Epiphany temporale fill).

## Global Constraints

- **Endpoint stays 501.** Do not wire the sanctorale loader / missal resolver / precedence resolver into
  `CalendarHandler`'s request path; do not relax the `Rite::AMBROSIAN` 501. Everything here is validated by
  unit/integration tests. Handler wiring is Plan 6.
- **Roman output byte-identical.** All model/schema/enum changes strictly additive; `PropriumDeSanctisEvent` is
  SHARED with Roman missals — a missing `is_dominical` → null → not serialized → Roman unchanged. The
  golden-master gate (`phpunit_tests/Handlers/CalendarGoldenMasterTest.php`) must stay green after every task.
- **2024 edition only.** Author `propriumdesanctis_2024` only; the 1976 edition + `since_year`/`until_year`
  historical gating are deferred to Plan 8. `AmbrosianMissal` gets `EDITIO_2024` now (add `EDITIO_1976` in Plan
  8).
- **Fixed-date sanctorale only.** Only `month`/`day` entries. Mobile feasts of the Lord (Santa Famiglia etc.) are temporale, deferred.
- **Rite-wide entries only.** Location-prefixed ("A Milano…", "Nell'arcidiocesi di Milano e nella diocesi di
  Lugano…") entries are diocesan → Plan 7. When an entry is BOTH a rite-wide memory and a location-specific
  higher grade (e.g. Jan 20 S. Sebastiano: comune Memoria + Milano Solennità), author only the comune Memoria
  here; the diocesan elevation is Plan 7.
- **`is_dominical` is the only source-carried Plan-4 flag added here.** Set `"is_dominical": true` on comune
  **feasts/solemnities of the Lord** (the "dS" = *del Signore* marker in the source). `is_proper` is NOT
  source-carried (it is set programmatically by wiring in Plan 6/7); do not add it to sanctorale data.
- **The `LitGrade` ladder is not forked** (HIGHER_SOLEMNITY=7, SOLEMNITY=6, FEAST_LORD=5, FEAST=4, MEMORIAL=3,
  MEMORIAL_OPT=2, WEEKDAY=0). Grades/commons/colours are **provisional**, proofed against the Missal in Plan 8's
  ordo pass — author per the mapping rules below and flag uncertainty rather than guessing silently.
- **Deferred (do NOT do here):** handler wiring / un-501 (Plan 6), diocesan overlays + the `lugano_ch`→CH
  national-tier branch + `DiocesanCalendar` rite fields + own-church-dedication modelling (Plan 7), `/calendars`
  & `/events` rite-aware discovery (Plan 6), 1976 edition + historical gating + ordo-validation (Plan 8).

---

## Grade / common / colour mapping rules (source → data)

Applied when transcribing `ambrosian.txt`:

```text
GRADE MARKER (source)            -> grade (LitGrade int) + notes
  "Solennità"                    -> 6 (SOLEMNITY)        [7 HIGHER_SOLEMNITY only for the very top universal ones]
  "Solennità dS" / "Festa dS"    -> add "is_dominical": true (dS = del Signore, of the Lord)
  "Festa"                        -> 4 (FEAST)   ("Festa dS" of the Lord -> 5 FEAST_LORD + is_dominical)
  "Memoria"                      -> 3 (MEMORIAL)
  (no marker, name present)      -> 2 (MEMORIAL_OPT)     [optional memorial]
  (bare day number, no name)     -> omit (ferial day, produced by temporale/weekday-fill, not sanctorale)

COMMON (from the saint's title, -> "common": [ "<LitCommon value>" ] )
  vescovo / papa                 -> "Pastors:For a Bishop"      (papa: "Pastors:For a Pope")
  presbitero / sacerdote         -> "Pastors:For One Pastor"
  dottore della Chiesa           -> append "Doctors" as applicable (see LitCommon)
  martire / martiri              -> "Martyrs:For One Martyr" (plural: "For Several Martyrs")
  vergine                        -> "Virgins:For One Virgin"
  abate / monaco                 -> "Holy Men and Women:For a Monk/Religious"
  (no title / proper text)       -> "Proper"
  Combine when a saint has two titles (e.g. vescovo e martire -> ["Martyrs:For One Martyr","Pastors:For a Bishop"]).
  Validate every value against src/Enum/LitCommon.php — the schema enforces LitCommon.

COLOUR (default by grade/common)
  martyrs                        -> ["red"]        (or ["white","red"] where the Missal shows both)
  of the Lord (dS)               -> ["white"]
  BVM / non-martyr saints        -> ["white"]
  (confirm against the Missal in Plan 8; default sensibly here)

calendar:  "AMBROSIAN"           (all comune rows; mirrors Roman's "GENERAL ROMAN")
event_key: PascalCase, stable, e.g. "StAmbrose", "StCharlesBorromeo", "DedicationDuomoMilan".
           Prefix Ambrosian-specific keys that could collide with Roman keys distinctly if needed.
```

> **`common` exactness:** the exact `LitCommon` string values (e.g. `"Pastors:For a Bishop"`) must match
> `src/Enum/LitCommon.php` / `CommonDef.json` verbatim — the schema validates them. Read `LitCommon` and a Roman
> `propriumdesanctis_*` sample before authoring; use `["Proper"]` when no common fits.

---

## File Structure

**New source:**

- `src/Enum/AmbrosianMissal.php` — `EDITIO_2024` (static-table shape mirroring `RomanMissal`: folder, i18n path, year limits).
- `src/Models/Calendar/Missal/MissalResolver.php` — interface `resolve(int $year): array` (returns the applicable `AmbrosianMissal` edition(s) for a year).
- `src/Models/Calendar/Missal/AmbrosianMissalResolver.php` — `implements MissalResolver` (2024 edition for the supported range).
- `src/Models/Calendar/Sanctorale/AmbrosianSanctoraleLoader.php` — loads the resolved edition's `propriumdesanctis.json` + i18n into a `PropriumDeSanctisMap`.
- `jsondata/sourcedata/missals/ambrosian/propriumdesanctis_2024/propriumdesanctis.json` + `i18n/{it,la}.json`.

**Modified source:**

- `src/Enum/JsonDataConstants.php` + `JsonData.php` — Ambrosian sanctorale path constants (mirror the Plan-3 `AMBROSIAN_TEMPORALE_*` additions).
- `src/Models/PropriumDeSanctisEvent.php` — additive `?bool $is_dominical = null` (parsed from the row).
- `src/Models/Calendar/LiturgicalEvent.php` — `fromObject()` carries `is_dominical` from a
  `PropriumDeSanctisEvent` (the passthrough already exists for `PropriumDeTemporeEvent` — extend/confirm it
  covers `PropriumDeSanctisEvent`).
- `src/Models/Calendar/Rite/RiteProfile.php` (+`RomanRiteProfile`, `AmbrosianRiteProfile`) — add
  `missalResolver(): MissalResolver` (Roman deferred-throws; Ambrosian returns `AmbrosianMissalResolver`).
- `src/Models/Calendar/Precedence/AmbrosianPrecedenceResolver.php` — add the **iterative re-resolution pass**
  and close the #727 transfer items now that real coincidences exist (Task 8).
- `jsondata/schemas/PropriumDeSanctis.json` (+ `CommonDef.json` Calendar enum if needed) — add optional `is_dominical`; permit `"AMBROSIAN"` as a `calendar` value.

**New tests:** per-task, plus `phpunit_tests/Models/Calendar/Sanctorale/AmbrosianRealYearPrecedenceTest.php` (`@group slow`, Task 8 — the real-coincidence integration).

**Reference (read-only):** `.superpowers/sdd/sanctorale-map.md` (the study), `src/Enum/RomanMissal.php`,
`src/Models/PropriumDeSanctisMap.php`, `CalendarHandler::loadPropriumDeSanctisData()`, a Roman
`propriumdesanctis_2002` sample, `src/Enum/LitCommon.php`, and Plan 4's
`AmbrosianTemporale`/`AmbrosianPrecedenceResolver`/`AmbrosianLiturgicalDayRank`.

---

## Task 1: `AmbrosianMissal` enum + Ambrosian sanctorale path constants

**Files:** Create `src/Enum/AmbrosianMissal.php`; Modify `src/Enum/JsonDataConstants.php`, `JsonData.php`;
Test `phpunit_tests/Enum/AmbrosianMissalTest.php`,
`phpunit_tests/Enum/JsonDataAmbrosianSanctoralePathTest.php`.

**Interfaces:** Produces `AmbrosianMissal::EDITIO_2024` and its metadata accessors (mirror `RomanMissal`'s
static-table API — read `src/Enum/RomanMissal.php` and match its shape: the folder name
`ambrosian/propriumdesanctis_2024`, i18n path, and `getYearLimits()`/equivalent). Produces
`JsonData::AMBROSIAN_SANCTORALE_FILE` → `…/missals/ambrosian/propriumdesanctis_2024/propriumdesanctis.json`
and `AMBROSIAN_SANCTORALE_I18N_FILE` → `…/i18n/{locale}.json` (mirror the Plan-3 `AMBROSIAN_TEMPORALE_*`
constants exactly).

- [ ] **Step 1: failing tests** — `AmbrosianMissal::EDITIO_2024` exists with the same metadata shape as a
  `RomanMissal` case; the two `JsonData` paths resolve to the exact strings above (`assertStringEndsWith`,
  `{locale}` left literal — mirror `JsonDataAmbrosianPathTest` from Plan 3, incl. its `Router::getApiPaths()`
  `setUpBeforeClass`).
- [ ] **Step 2: RED → Step 3: implement** the enum (match `RomanMissal`'s exact accessor names/structure — do
  NOT invent a different API) + the 4 constants + 2 `JsonData` cases. → **Step 4: GREEN** + `composer analyse &&
  vendor/bin/phpcs` + golden-master.
- [ ] **Step 5: Commit** `feat(ambrosian): add AmbrosianMissal enum + sanctorale path constants`.

---

## Task 2: `PropriumDeSanctis` schema — optional `is_dominical` + `AMBROSIAN` calendar

**Files:** Modify `jsondata/schemas/PropriumDeSanctis.json` (and `CommonDef.json` if the `Calendar` enum lives
there); Test `phpunit_tests/Schemas/AmbrosianSanctoraleSchemaTest.php` (or extend the schema corpus test).

**Interfaces:** The schema permits an optional `is_dominical` boolean on a `PropriumDeSanctis` item, and
accepts `"AMBROSIAN"` as a `calendar` value — so the Task-4 data validates. Must remain `additionalProperties:
false`-clean for existing Roman data (adding an OPTIONAL property doesn't break Roman rows that omit it).

- [ ] **Step 1: failing test** — a minimal Ambrosian sanctorale row
  (`{"month":12,"day":7,"event_key":"StAmbrose","grade":6,"common":["Pastors:For a
  Bishop"],"calendar":"AMBROSIAN","color":["white"],"is_dominical":false}`) validates against the schema; a
  Roman row without `is_dominical` still validates; an unknown property still fails (additivity preserved). Use
  the repo's JSON-schema validator (see `SchemaValidationTest` for the harness).
- [ ] **Step 2: RED → Step 3: implement** — add `"is_dominical": {"type":"boolean"}` to the `PropriumDeSanctis`
  properties (NOT in `required`); add `"AMBROSIAN"` to the `Calendar` enum (`#/definitions/Calendar` — locate
  it; confirm whether it's an enum list or a pattern). → **Step 4: GREEN** + `composer lint:openapi` is
  unrelated; run the schema test + golden-master (unchanged Roman data still validates).
- [ ] **Step 5: Commit** `feat(ambrosian): permit is_dominical + AMBROSIAN calendar in PropriumDeSanctis schema`.

---

## Task 3: `PropriumDeSanctisEvent.is_dominical` + `fromObject` passthrough

**Files:** Modify `src/Models/PropriumDeSanctisEvent.php`, `src/Models/Calendar/LiturgicalEvent.php`; Test `phpunit_tests/Models/PropriumDeSanctisEventIsDominicalTest.php`.

**Interfaces:** `PropriumDeSanctisEvent` gains an optional `is_dominical` (parsed from the row, default null
when absent). `LiturgicalEvent::fromObject()` copies `is_dominical` from a `PropriumDeSanctisEvent` onto the
built event (only when non-null). This mirrors EXACTLY what Plan 4 did for `PropriumDeTemporeEvent` (read that
diff / the current `fromObject` `property_exists($obj,'is_dominical')` handling — it may already cover any
object with the property; confirm it fires for `PropriumDeSanctisEvent` and add the field to the model so it's
populated from JSON).

- [ ] **Step 1: failing test** — build a `PropriumDeSanctisEvent` from an object row with `is_dominical: true`
  (via the model's constructor/factory — confirm the signature); assert the field is set; then
  `LiturgicalEvent::fromObject($it)->is_dominical === true`; and a row without the key → null (not serialized).
- [ ] **Step 2: RED → Step 3: implement** — add `public ?bool $is_dominical = null;` to
  `PropriumDeSanctisEvent`, parsed from the row where the sibling optional fields are parsed (mirror
  `since_year`/`decree` optional handling); ensure `fromObject()`'s `is_dominical` copy fires for this type.
  Additive → Roman rows lacking the key stay null. → **Step 4: GREEN** + `composer analyse && vendor/bin/phpcs`
  plus **golden-master (Roman sanctorale byte-identical)**.
- [ ] **Step 5: Commit** `feat(ambrosian): carry is_dominical from PropriumDeSanctisEvent to LiturgicalEvent`.

---

## Task 4: Comune ambrosiano sanctorale data — JANUARY (the template)

**Files:** Create `jsondata/sourcedata/missals/ambrosian/propriumdesanctis_2024/propriumdesanctis.json`
(January rows) + `i18n/it.json` + `i18n/la.json` (January names); Test
`phpunit_tests/Models/Calendar/Sanctorale/AmbrosianSanctoraleDataTest.php`.

**Interfaces:** Establishes the file + the transcription pattern that Tasks 5a/5b extend. January is authored
in full here as the worked template; the data test (schema-load + i18n-coverage) grows with each month.

**Source:** `ambrosian.txt` lines **5084–5145** (GENNAIO). Apply the mapping rules above. Skip
location-prefixed (diocesan) and the two mobile "Festa dS" temporale entries. Example rows (author the rest of
January the same way; grades/commons provisional):

```json
[
    { "month": 1, "day": 1, "event_key": "Circoncisione", "grade": 6, "type": "fixed", "common": [ "Proper" ], "calendar": "AMBROSIAN", "color": [ "white" ], "is_dominical": true },
    { "month": 1, "day": 2, "event_key": "StsBasilGregoryNazianzen", "grade": 3, "type": "fixed", "common": [ "Pastors:For a Bishop", "Doctors" ], "calendar": "AMBROSIAN", "color": [ "white" ] },
    { "month": 1, "day": 6, "event_key": "Epiphany", "grade": 6, "type": "fixed", "common": [ "Proper" ], "calendar": "AMBROSIAN", "color": [ "white" ], "is_dominical": true },
    { "month": 1, "day": 13, "event_key": "StHilary", "grade": 2, "type": "fixed", "common": [ "Pastors:For a Bishop", "Doctors" ], "calendar": "AMBROSIAN", "color": [ "white" ] },
    { "month": 1, "day": 17, "event_key": "StAnthonyAbbot", "grade": 3, "type": "fixed", "common": [ "Holy Men and Women:For a Monk" ], "calendar": "AMBROSIAN", "color": [ "white" ] },
    { "month": 1, "day": 18, "event_key": "ChairStPeter", "grade": 4, "type": "fixed", "common": [ "Proper" ], "calendar": "AMBROSIAN", "color": [ "white" ] },
    { "month": 1, "day": 25, "event_key": "ConversionStPaul", "grade": 4, "type": "fixed", "common": [ "Proper" ], "calendar": "AMBROSIAN", "color": [ "white" ] }
]
```

> `Circoncisione`/`Epiphany` already exist as **temporale** keys (Plan 3). Where the comune sanctorale and the
> temporale name the same celebration, the sanctorale row is the SANCTORALE representation; the eventual
> pipeline (Plan 6) must not double-create. For THIS plan, author the sanctorale entry as the Missal lists it
> and note the overlap in your report — the Plan-6 wiring resolves de-duplication. If you judge a fixed
> sanctorale row redundant with a temporale key, still include it (the Missal's calendar lists it) and flag it.

- [ ] **Step 1: failing data test** — load `propriumdesanctis_2024/propriumdesanctis.json` via
  `Utilities::jsonFileToObjectArray` (or `PropriumDeSanctisMap::fromObject` — confirm), assert January sentinel
  keys present with expected grade/is_dominical; assert every data key has an it AND la name (both directions);
  validate every row against `PropriumDeSanctis.json` (Task 2).
- [ ] **Step 2: RED → Step 3: author** the full January rows + it/la names. Every `common` value legal per
  `LitCommon`; every colour legal per `LitColor` (incl. `morello`/`black` from Plan 3). JSON-lint. → **Step 4:
  GREEN** + `composer analyse` (test only) + `vendor/bin/phpcs` (test file).
- [ ] **Step 5: Commit** `feat(ambrosian): comune sanctorale data — January (template) + loader test`.

---

## Task 5a: Comune sanctorale — February–June

**Files:** extend `propriumdesanctis_2024/propriumdesanctis.json` + `i18n/{it,la}.json`; extend the data test.

**Source:** `ambrosian.txt` FEBBRAIO (5144) … GIUGNO (…before LUGLIO 5405). Same rules/template as Task 4.
Notable rite-wide entries to expect (verify against the source; grades provisional): S. Ambrogio's
translation, S. Marcellina, the Ambrosian bishops (S. Carlo Borromeo is November), etc. Skip all
location-prefixed entries (Plan 7).

- [ ] **Step 1: extend the data test** with Feb–Jun sentinel keys + the both-directions i18n coverage over the growing file.
- [ ] **Step 2: RED → Step 3: author** Feb–Jun rows + names, schema-valid. → **Step 4: GREEN** (whole data test)
  plus JSON-lint + phpcs. Commit `feat(ambrosian): comune sanctorale data — February–June`.

---

## Task 5b: Comune sanctorale — July–December

**Files:** extend the same three files + the data test.

**Source:** `ambrosian.txt` LUGLIO (5405) … DICEMBRE (5702–5850). Same rules. Notable: **7 Dec S. Ambrogio**
(patron, top-tier — `grade` 6/7, confirm) and the Dedication-related entries; **4 Nov S. Carlo Borromeo**.
Skip location-prefixed entries.

- [ ] **Step 1: extend the data test** with Jul–Dec sentinels (incl. `StAmbrose` Dec 7) + full both-directions i18n coverage.
- [ ] **Step 2: RED → Step 3: author** Jul–Dec rows + names, schema-valid. → **Step 4: GREEN** (full data test —
  every month) + JSON-lint + phpcs. Commit `feat(ambrosian): comune sanctorale data — July–December`.

> After 5b, run the `@group slow` `SchemaValidationTest` corpus (Task 6 Health-wires it) if already present, else defer that assertion to Task 6.

---

## Task 6: `MissalResolver` seam + `AmbrosianSanctoraleLoader` + Health-wire schema validation

**Files:** Create `src/Models/Calendar/Missal/{MissalResolver.php, AmbrosianMissalResolver.php}`,
`src/Models/Calendar/Sanctorale/AmbrosianSanctoraleLoader.php`; Modify `RiteProfile.php` (+Roman/Ambrosian),
and the Health/`SchemaValidationTest` corpus; Tests for each.

**Interfaces:**

- `interface MissalResolver { /** @return list<AmbrosianMissal> */ public function resolve(int $year): array; }` — the editions applicable to a civil year.
- `AmbrosianMissalResolver::resolve($year)` → `[AmbrosianMissal::EDITIO_2024]` for the supported range (year ≥
  the 2024-edition floor; the 1976 split is Plan 8 — for now every in-range year resolves to 2024; a year below
  the rite floor never reaches here because `CalendarParams::validateRiteCompatibility()` 400s < 1976).
- `RiteProfile::missalResolver(): MissalResolver` — `RomanRiteProfile` deferred-throws (`\LogicException`, Roman
  resolves missals inline in the handler); `AmbrosianRiteProfile` returns `new AmbrosianMissalResolver()`.
- `AmbrosianSanctoraleLoader::load(AmbrosianMissal $missal, string $locale): PropriumDeSanctisMap` — reads the
  edition's `propriumdesanctis.json` + `i18n/{locale}.json` (via the Task-1 `JsonData` constants) into a
  `PropriumDeSanctisMap` with names applied. Mirror `CalendarHandler::loadPropriumDeSanctisData()` (read it) but
  rite-scoped and returning the map (no handler state).

- [ ] **Step 1: failing tests** — `AmbrosianMissalResolver::resolve(2025)` returns `[EDITIO_2024]`;
  `AmbrosianRiteProfile::missalResolver()` returns an `AmbrosianMissalResolver`;
  `RomanRiteProfile::missalResolver()` throws `\LogicException`;
  `AmbrosianSanctoraleLoader::load(EDITIO_2024,'it')` returns a `PropriumDeSanctisMap` containing `StAmbrose`
  (Dec 7) with its Italian name. Add the Ambrosian sanctorale file to the `SchemaValidationTest` corpus and
  assert it validates.
- [ ] **Step 2: RED → Step 3: implement** the interface + resolver + loader + the `missalResolver()` seam method
  (mirror the Plan-4 `precedenceResolver()` wiring exactly), and register the Ambrosian sanctorale in the
  schema-validation corpus. → **Step 4: GREEN** + `composer analyse && vendor/bin/phpcs` + golden-master + the
  `@group slow` schema corpus.
- [ ] **Step 5: Commit** `feat(ambrosian): MissalResolver seam + AmbrosianSanctoraleLoader + schema Health-wiring`.

---

## Task 7: Assemble a real Ambrosian year (temporale + comune sanctorale) — the integration harness

**Files:** Create `phpunit_tests/Models/Calendar/Sanctorale/AmbrosianRealYearHarnessTrait.php`; Test `phpunit_tests/Models/Calendar/Sanctorale/AmbrosianRealYearAssemblyTest.php`.

**Interfaces:** A test harness that, for a civil year, builds a `LiturgicalEventCollection` populated with (a)
the Ambrosian temporale (run `AmbrosianTemporale::buildTemporale()` — reuse Plan 3's harness) AND (b) the
comune sanctorale (via `AmbrosianSanctoraleLoader` + `LiturgicalEvent::fromObject` on each dated
`PropriumDeSanctisEvent` — a sanctorale event's `date` comes from its `month`/`day` + the year). Produces
`assembleAmbrosianYear(int $year): LiturgicalEventCollection` for Task 8. This is the FIRST time real
temporale+sanctorale coexist, so it must correctly date fixed sanctorale events and add them to the collection
alongside temporale.

- [ ] **Step 1: failing test** — `assembleAmbrosianYear(2025)` yields a collection where a known temporale key
  (e.g. `Advent1` 2025-11-16) AND a known comune sanctorale key (e.g. `StAmbrose` 2025-12-07) are both present
  with correct dates. Assert a plausible total count (sanctorale rows + temporale anchors).
- [ ] **Step 2: RED → Step 3: implement** the trait: run temporale into the collection, then for each sanctorale
  row set its date (`DateTime` from month/day/year) and `addLiturgicalEvent(LiturgicalEvent::fromObject($row))`.
  Confirm collection-add semantics against `LiturgicalEventCollection`. → **Step 4: GREEN** + `composer analyse
  && vendor/bin/phpcs`. (Endpoint still 501 — this is a test harness, not handler code.)
- [ ] **Step 5: Commit** `test(ambrosian): real-year assembly harness (temporale + comune sanctorale)`.

---

## Task 8: Harden `AmbrosianPrecedenceResolver` on real coincidences — iterative pass + close #727

**Files:** Modify `src/Models/Calendar/Precedence/AmbrosianPrecedenceResolver.php`; Test
`phpunit_tests/Models/Calendar/Sanctorale/AmbrosianRealYearPrecedenceTest.php` (`@group slow`) + extend the
isolated `AmbrosianPrecedenceResolverTest`.

**Interfaces:** With real assembled years available (Task 7), run `AmbrosianPrecedenceResolver::resolve()`
over `assembleAmbrosianYear($year)` and (a) add the **iterative re-resolution pass** — after applying
transfers, re-scan for coincidences that a transfer created (a solemnity moved onto an occupied day) and
resolve until stable (bounded); and (b) close the #727 Lenten-ferie item — when a Lenten ferie is impeded by a
non-Annunciation/St-Joseph solemnity, **transfer the impeding solemnity** (via the existing generic n.56 walk)
so only the ferie remains, instead of the current no-op. See issue #727 for the full list.

- [ ] **Step 1: failing tests** — using real 2025 (and 2026) assembled years: after `resolve()`, assert (i) no
  date holds two mutually-impeding celebrations that the *Tabella* says cannot coexist (the invariant the
  iterative pass guarantees); (ii) a concrete real coincidence resolves correctly (find one in the assembled
  2025 data — e.g. a comune saint memorial coinciding with a Sunday is suppressed/kept per rank; a solemnity
  impeded by a Sunday transfers and its destination is itself re-resolved if occupied). Also extend the isolated
  resolver test with a constructed cascade (transfer lands on an occupied day → second-pass resolves it) and the
  Lenten-ferie-transfers-the-solemnity case.
- [ ] **Step 2: RED → Step 3: implement** the iterative pass in `resolve()` (loop: build date groups → resolve
  losers → if any transfer changed a date, repeat; cap iterations, guard) and the Lenten-ferie winner-transfer
  branch. Preserve all existing isolated-resolver tests (Plan 4) — they must still pass. → **Step 4: GREEN**
  (`@group slow` real-year test + isolated resolver + rank tests) + `composer analyse && vendor/bin/phpcs` +
  golden-master (resolver still unwired — Roman untouched).
- [ ] **Step 5: Commit** `feat(ambrosian): iterative re-resolution + close #727 transfer items on real
  coincidences`. Update issue #727 status in the report (items closed vs. still-deferred to Plan 6 wiring).

> **Scope guard:** do NOT wire `resolve()` into `CalendarHandler` here (endpoint stays 501 — Plan 6). If a real
> coincidence exposes a rank-boundary the classifier gets wrong, fix `AmbrosianLiturgicalDayRank` minimally and
> note it; large rank redesigns are ordo-validation (Plan 8).

---

## Self-Review (completed by plan author)

**Spec coverage (spec §6 data model + missal resolution §3, milestone-sliced):** comune ambrosiano sanctorale
data 2024 (Tasks 4/5a/5b) · `PropriumDeSanctis` schema + `is_dominical` threading (Tasks 2/3) ·
`AmbrosianMissal` + `MissalResolver` seam (Tasks 1/6) · sanctorale loader (Task 6) · resolver hardening on
real data closing #727 (Tasks 7/8). Diocesan overlays, handler wiring/un-501, discovery, and
1976/ordo-validation are explicitly the next three plans. ✓

**Deferred (documented in Global Constraints):** endpoint un-501 + `/calendars`/`/events` discovery (Plan 6);
diocesan overlays + `lugano_ch`→CH branch + `DiocesanCalendar` rite fields + own-church dedication (Plan 7);
1976 edition + historical gating + ordo-validation (Plan 8). ✓

**Placeholder scan:** the data tasks give the source line-ranges, the mapping rules, a worked January
template, and schema validation as the correctness gate — the realistic shape for a transcription task (the
planner cannot pre-author 200+ Missal entries; the implementer transcribes against rules + gate).
Grades/commons/colours are flagged provisional (Plan-8 proofing), not left blank. ✓

**Type/name consistency:** `is_dominical` (Tasks 2/3) is consumed by Task 8's ranking via
`AmbrosianLiturgicalDayRank` (Plan 4); `AmbrosianMissal::EDITIO_2024` (Task 1) is consumed by
`MissalResolver`/`AmbrosianSanctoraleLoader` (Task 6); the Task-1 `JsonData::AMBROSIAN_SANCTORALE_*` constants
are consumed by the loader (Task 6); `assembleAmbrosianYear()` (Task 7) feeds Task 8. The
`missalResolver()`/`MissalResolver` names match the `precedenceResolver()`/`PrecedenceResolver` seam
precedent. ✓

**Risk note:** Task 8's iterative pass + Lenten-ferie transfer are the highest-judgment work — they finally
run on real data, so a genuine ordo cross-check still waits for Plan 8, but the internal invariant ("no two
mutually-impeding celebrations survive on one date") is testable now and is the acceptance gate.
