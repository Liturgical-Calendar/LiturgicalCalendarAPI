# Ambrosian Un-501 Wiring Implementation Plan (Plan 7)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps
> use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `/calendar/ambrosian/{year}` return a real, complete comune-ambrosiano calendar (replacing the current HTTP 501), with full-parity post-processing (year cycles,
first-vespers vigils, psalter week, holy-days-of-obligation, seasons — readings as an empty placeholder), and make `/calendars` and `/events` rite-aware.

**Architecture:** The handler is monolithic and ~98% Roman-hardcoded, and the Ambrosian assembly model ("add every event, then resolve to a fixpoint via
`AmbrosianPrecedenceResolver`") is incompatible with the Roman "check-before-add" pipeline. So we add a **separate Ambrosian generation path** — a new
`calculateAmbrosianCalendar()` orchestrator + an Ambrosian branch in `CalendarHandler::handle()` that replaces the 501 — rather than threading rite-`if`s through the Roman
`calculate*` methods. All Roman code paths stay byte-identical.

**Tech Stack:** PHP 8.4, PHPUnit 12, PHPStan level 10, PHP_CodeSniffer. The Ambrosian temporale engine, sanctorale loader, missal resolver, and precedence resolver already exist
(Plans 3–6, merged); this plan wires them into the handler and adds the post-processing + discovery.

## Global Constraints

- **Roman output byte-identical.** `phpunit_tests/Handlers/CalendarGoldenMasterTest.php` (9 tests / 36 assertions) is the gate. The Ambrosian path is **separate** — do NOT modify
  any Roman `calculate*` method, `setSeasonsAndHolyDaysOfObligation()`, `setYearCyclesAndVigils()`, `calculateVigilMass()`, or the Roman branch of `handle()`. New Ambrosian methods
  live alongside; shared helpers are only *added to*, never behaviourally changed for Roman.
- **The 501 stays until the final wiring task.** Build and unit/integration-test the whole Ambrosian generation path with the 501 still in place (mirroring Plan 6's discipline —
  never ship a half-built calendar). The **last** task flips the 501 to route to `calculateAmbrosianCalendar()` and adds the end-to-end gate. Every task before it keeps
  `/calendar/ambrosian` returning 501.
- **Ambrosian response must validate against `jsondata/schemas/LitCal.json`.** A new schema-validation test is the second gate. The schema needs `AFTER_EPIPHANY`/`AFTER_PENTECOST`
  added to the two `liturgical_season` enums and `is_dominical`/`is_aliturgical` declared in the two event definitions (both have `additionalProperties: false`).
- **Readings = empty placeholder.** No Ambrosian lectionary exists; `readings` is `required` in both event defs. Every Ambrosian event gets a structurally-valid **empty** readings
  object (see Task 2). No change to the `readings` schema. A real Ambrosian lectionary is a separate future plan.
- **Season stamping:** the Ambrosian temporale self-stamps `liturgical_season` on temporale events; **sanctorale events do not** — the Ambrosian generation path must stamp season
  on sanctorale events before running the precedence resolver, or its season-gated transfer rules stay inert.
- **LitGrade ladder & existing enums unchanged.** All additions are additive. PHPStan L10 + phpcs clean; modern `@phpstan-ignore <identifier>` only where unavoidable.
- **Work in the worktree** `…/scratchpad/wt-ambrosian-p7` (branch `feature/ambrosian-un501`, off merged `84bcceab`). Verify `git rev-parse --show-toplevel` ends in
  `wt-ambrosian-p7` before editing. Signed commits (unlock GPG if a commit times out; never `--no-verify`).

## Key file:line references (from the integration study; verify before editing — the handler is being modified)

- **501 gate:** `CalendarHandler.php:5019-5023` (`if Rite::AMBROSIAN throw ImplementationException`). Sits after `validateRiteCompatibility()` (5015), before
  `loadDiocesanCalendarData()` (5025) and the Roman generation `else` (5065-5144).
- **Roman generation:** `handle()` `CalendarHandler.php:4921`; generation body 5065-5144; per-run 5070-5093 (main year) + 5104-5137 (prev year for `YearType::LITURGICAL`).
- **Core pipeline:** `calculateUniversalCalendar()` 3866-3981; the one rite-abstracted seam 3886-3894
  (`RiteProfileFactory::forRite(...)->temporaleEngine()->buildTemporale($temporaleContext)`); `loadPropriumDeTemporeData()` at 3868 (Roman-hardcoded, runs before the rite dispatch
  — the wiring bug Task 3 fixes).
- **Post-processors:** `LiturgicalEventCollection::setSeasonsAndHolyDaysOfObligation()` 1054-1099; `setYearCyclesAndVigils()` 1111-1329; `calculatePsalterWeek()` 1824-1871;
  `createVigilMassFor()` 1378-1408; `liturgicalEventCanHaveVigil()` 1341-1365; `calculateVigilMass()` 1516-1600; `addLiturgicalEvent()` 236-298 (psalter/season-map auto-assign
  258-296).
- **Ambrosian strategies (exist, unwired):** `AmbrosianSanctoraleLoader::load(string $missal, string $locale): PropriumDeSanctisMap`
  (`src/Models/Calendar/Sanctorale/AmbrosianSanctoraleLoader.php:29`); `AmbrosianMissalResolver::resolve(int $year): array` → `['EDITIO_2024']`;
  `AmbrosianPrecedenceResolver::resolve(PrecedenceContext $ctx): void` (`.../Precedence/AmbrosianPrecedenceResolver.php:106`); `PrecedenceContext(cal, params, localeDateFormatter,
  &messages)` (`.../Precedence/PrecedenceContext.php`); `AmbrosianTemporale::stampSeason()` (self-stamps season, `AmbrosianTemporale.php:73-83`); `RiteProfileFactory::forRite()`;
  `AmbrosianRiteProfile` (all 4 seams impl; `SUPPORTED_DIOCESES` @31).
- **HDoO source:** `CalendarParams::$HolyDaysOfObligation` default (Roman 10-key) `CalendarParams.php:80-91`.
- **Discovery:** `/calendars` → `src/Services/CalendarMetadataProvider.php` (`create()` 58-66; `MetadataCalendars` shape `src/Models/Metadata/MetadataCalendars.php:74-98`);
  `/events` → `EventsHandler` (`processTemporaleEvents()` 344, `processSanctoraleEvents()` 304; Roman-hardcoded; Router builds it WITHOUT a rite `Router.php:324`; `EventsParams`
  has no `Rite`).
- **Response schema:** `LitCal.json` `liturgical_season` enums at 570-581 + 760-771; `additionalProperties:false` at 436 (`LiturgicalEventVigil`) + 636 (`LiturgicalEvent`);
  `readings` `$ref ./CommonDef.json#/definitions/Readings`.
- **Data:** `jsondata/sourcedata/missals/ambrosian/{propriumdetempore/propriumdetempore.json, propriumdesanctis_2024/propriumdesanctis.json}` + i18n;
  `JsonData::AMBROSIAN_TEMPORALE_FILE`/`_I18N_FILE`/`AMBROSIAN_SANCTORALE_FILE`/`_I18N_FILE` (`src/Enum/JsonData.php:131/144/156/169`).

---

## File Structure

- `jsondata/schemas/LitCal.json` — additive schema changes (Task 1).
- `src/Models/Calendar/LiturgicalEvent.php` or a small factory — empty-readings placeholder (Task 2).
- `src/Handlers/CalendarHandler.php` — rite-aware tempore load (Task 3); the new `calculateAmbrosianCalendar()` orchestrator + Ambrosian post-processing methods (Tasks 4–9); the
  501→branch flip (Task 10).
- `src/Models/Calendar/LiturgicalEventCollection.php` — a new Ambrosian season+HDoO pass and Ambrosian year-cycle/vigil methods **added alongside** the Roman ones (Tasks 6–8);
  optionally a small guard in `addLiturgicalEvent` for the Ambrosian psalter pre-stamp (Task 8).
- `src/Enum/AmbrosianHolyDaysOfObligation.php` (or a const) — the Ambrosian HDoO default set (Task 6).
- `src/Services/CalendarMetadataProvider.php` + `src/Models/Metadata/*` — `/calendars` comune announcement (Task 11).
- `src/Handlers/EventsHandler.php`, `src/Params/EventsParams.php`, `src/Router.php` — `/events` rite dimension (Task 12).
- `phpunit_tests/` — per-task unit/handler tests + a schema-validation test + a final Routes integration test.

**Architecture note (the orchestrator).** `calculateAmbrosianCalendar()` mirrors the shape of the Roman `calculateUniversalCalendar()` + the `handle()` post-processing block, but
as a self-contained Ambrosian pipeline:

```text
calculateAmbrosianCalendar():
  1. loadAmbrosianPropriumDeTempore()                    (Task 3)
  2. RiteProfileFactory::forRite(AMBROSIAN)->temporaleEngine()->buildTemporale(TemporaleContext)   (existing engine; self-stamps season)
  3. loadAmbrosianSanctorale() -> add rows to $this->Cal, de-dup vs temporale keys, empty-readings   (Task 4)
  4. stampAmbrosianSeasonOnSanctorale()                  (Task 6, season half)
  5. new AmbrosianPrecedenceResolver()->resolve(PrecedenceContext(...))   (Task 5)
  6. setAmbrosianHolyDaysOfObligation()                  (Task 6, HDoO half)
  7. setAmbrosianYearCyclesAndVigils()                   (Task 7)
  8. calculatePsalterWeek()   (reuse Roman as-is; Task 8 guards the pre-stamp)
  9. sortLiturgicalEvents()   (reuse)
```

The `handle()` Ambrosian branch (Task 10) calls this in place of the Roman block, honouring `YearType::LITURGICAL` (two runs + splice) exactly as the Roman branch does at
5065-5144.

---

### Task 1: Response-schema support for Ambrosian events

**Files:**

- Modify: `jsondata/schemas/LitCal.json` (enums at 570-581 & 760-771; property blocks in `LiturgicalEventVigil` def opening ~434 and `LiturgicalEvent` def opening ~634)
- Test: `phpunit_tests/Schemas/` (add an Ambrosian-event schema fixture test)

**Interfaces:**

- Produces: a `LitCal.json` that accepts `liturgical_season ∈ {…, AFTER_EPIPHANY, AFTER_PENTECOST}` and optional `is_dominical`/`is_aliturgical` booleans, in BOTH event
  definitions.

- [ ] **Step 1: Add the two season enum values** in BOTH `liturgical_season` enum arrays (the `LiturgicalEventVigil` block ~570-581 and the `LiturgicalEvent` block ~760-771).
  Append `"AFTER_EPIPHANY"` and `"AFTER_PENTECOST"` to each `enum` array, and extend each `description` string's "one of:" list. Keep the two blocks byte-identical to each other
  (they already are).

- [ ] **Step 2: Declare `is_dominical` and `is_aliturgical`** in BOTH event definitions' `properties` (since both have `additionalProperties:false`), as optional booleans (do NOT
  add to `required`):

```json
                "is_dominical": {
                    "type": "boolean",
                    "description": "True if the event is 'of the Lord' (dominical). Present only for rites that classify dominical events (Ambrosian)."
                },
                "is_aliturgical": {
                    "type": "boolean",
                    "description": "True if the event is aliturgical (no Mass celebrated), e.g. the aliturgical Fridays of Ambrosian Lent."
                },
```

- [ ] **Step 3: Write a schema-validation test.** In `phpunit_tests/Schemas/`, add a test that loads `LitCal.json` (resolving `$ref`s the same way the existing schema tests do —
  follow the sibling `SchemaValidationTest` pattern) and validates a hand-built minimal Ambrosian event object carrying `liturgical_season: "AFTER_PENTECOST"`, `is_dominical:
  true`, `is_aliturgical: true`, and an empty readings object (Task 2 shape) — asserting it PASSES; and a second object with `liturgical_season: "NONSENSE"` asserting it FAILS.
  This pins both the new enum values and the new properties.

- [ ] **Step 4: Validate.** `composer lint:openapi` (if it covers LitCal.json) or the JSON is well-formed; run the new test → green; run the existing `phpunit_tests/Schemas/` suite
  → still green (Roman events unaffected — the additions are optional/additive). Commit.

```bash
git add jsondata/schemas/LitCal.json phpunit_tests/Schemas/
git commit -m "feat(ambrosian): allow AFTER_* seasons + is_dominical/is_aliturgical in LitCal response schema"
```

---

### Task 2: Empty-readings placeholder for Ambrosian events

**Files:**

- Inspect: `src/Models/Calendar/Readings/` (the `ReadingsAbstract` hierarchy) + `jsondata/schemas/CommonDef.json#/definitions/Readings`
- Create/Modify: a small factory or static helper that yields a schema-valid empty readings object; wire it where Ambrosian events are finalized.
- Test: unit test asserting the placeholder serializes to a `Readings`-schema-valid shape.

**Interfaces:**

- Produces: `AmbrosianReadings::empty(): ReadingsAbstract` (or equivalent) — a readings object whose `jsonSerialize()` satisfies `CommonDef.json#/definitions/Readings`.

- [ ] **Step 1: Determine the minimal valid `Readings` shape.** Read `CommonDef.json#/definitions/Readings` and the `ReadingsAbstract`/`ReadingsCommons` classes to find which
  fields are `required` and their minimal valid values (likely `first_reading`, `responsorial_psalm`, `gospel`, etc. as strings — empty strings `""` are the placeholder). Write the
  failing test first: build the placeholder, `json_encode` it, and validate against the `Readings` schema definition — expect PASS.

- [ ] **Step 2: Implement the placeholder** as the least-effort schema-valid object (reuse the existing readings class whose required fields you can set to empty strings; do NOT
  invent a new schema shape). Ensure `LiturgicalEvent::jsonSerialize()` (line 267 guards `isset($this->readings)`; 304 emits `->readings->jsonSerialize()`) emits it when set.

- [ ] **Step 3:** In the Ambrosian generation path this will be applied to every event (Task 4 for sanctorale, and after the temporale engine runs for temporale events) via
  `setReadings(AmbrosianReadings::empty())`. For THIS task, just build + unit-test the placeholder in isolation. Validate + commit.

> **Note:** if the `Readings` schema's required fields cannot all be satisfied by empty strings (e.g. an enum or pattern), use the smallest legal values and document them. The goal
> is schema-validity, not liturgical content.

---

### Task 3: Rite-aware Proprium de Tempore loading

**Files:**

- Modify: `src/Handlers/CalendarHandler.php` (`loadPropriumDeTemporeData()`, called at 3868)
- Test: `phpunit_tests/Handlers/` (assert an Ambrosian-configured handler loads the Ambrosian tempore keys)

**Background:** `loadPropriumDeTemporeData()` is Roman-hardcoded and runs at 3868 *before* the rite dispatch at 3886, so `$this->PropriumDeTempore` would hold the Roman proprium
even for an Ambrosian request. The Ambrosian temporale reads `$ctx->propriumDeTempore`, so it must be the Ambrosian file.

- [ ] **Step 1:** Write a failing handler test: configure `CalendarParams` with `Rite::AMBROSIAN`, invoke the tempore-load step, assert `$this->PropriumDeTempore` contains an
  Ambrosian-only key (e.g. `Circoncisione` or `DedicationDuomo`) and NOT a Roman-only one (e.g. `AshWednesday`).

- [ ] **Step 2:** Make `loadPropriumDeTemporeData()` rite-aware: when `$this->CalendarParams->Rite === Rite::AMBROSIAN`, load `JsonData::AMBROSIAN_TEMPORALE_FILE` +
  `AMBROSIAN_TEMPORALE_I18N_FILE` into `$this->PropriumDeTempore` (mirroring the existing Roman load but with the Ambrosian paths; the harness in
  `AmbrosianTemporaleHarnessTrait::buildContext` shows the exact `PropriumDeTemporeMap::fromObject` + `setNames` calls). Keep the Roman branch untouched (default).

- [ ] **Step 3:** Golden master 9/9 (Roman branch unchanged); new test green; analyse + lint clean. Commit.

---

### Task 4: Ambrosian sanctorale assembly + temporale/sanctorale de-dup + empty readings

**Files:**

- Modify: `src/Handlers/CalendarHandler.php` (new private `loadAmbrosianSanctorale()` / `addAmbrosianSanctoraleToCalendar()`)
- Test: `phpunit_tests/Handlers/`

**Background:** `AmbrosianSanctoraleLoader::load($edition, $locale)` returns a `PropriumDeSanctisMap` (254 comune rows). These must be added to `$this->Cal`. The Ambrosian assembly
model is "add everything, then resolve", so — unlike Roman's check-before-add — add ALL sanctorale rows, then let Task 5's resolver sort coincidences. BUT: **key collisions** (same
`event_key` in both temporale and sanctorale, e.g. `Christmas`/`Circoncisione`/`Epiphany`) silently overwrite via `addLiturgicalEvent` (keyed by `event_key`). Every Ambrosian event
also needs the empty-readings placeholder (Task 2).

- [ ] **Step 1: Audit the overlap.** Read the 254 `propriumdesanctis_2024` event_keys and the temporale keys; determine which keys appear in BOTH. Write a test asserting the actual
  overlap set (likely `Christmas`/`Circoncisione`/`Epiphany` are ONLY in tempore, per the Roman curation convention — verify). Document the finding.

- [ ] **Step 2: Implement `addAmbrosianSanctoraleToCalendar()`**: `$edition = (new AmbrosianMissalResolver())->resolve($year)[...]`; `$map = (new
  AmbrosianSanctoraleLoader())->load($edition, $locale)`; for each event, build a `LiturgicalEvent` (via `LiturgicalEvent::fromObject` on the `PropriumDeSanctisEvent`, carrying
  grade/color/is_dominical), set the empty readings placeholder, and `$this->Cal->addLiturgicalEvent($key, $event)`. If any key already exists from the temporale (collision), SKIP
  it with a `$this->Messages[]` note (the temporale definition wins) OR fail loudly if the collision is unexpected — decide per the Step-1 audit.

- [ ] **Step 3:** Unit-test: after temporale + this step for 2025, assert (a) a known comune saint (e.g. `StAmbrose`) is present with its date/grade, (b) no duplicate event_key,
  (c) every event has a (placeholder) readings object. Golden master untouched (Roman path not involved). Commit.

---

### Task 5: Wire the Ambrosian precedence resolver

**Files:**

- Modify: `src/Handlers/CalendarHandler.php` (call the resolver in the orchestrator)
- Test: `phpunit_tests/Handlers/` — the end-to-end precedence effect on the assembled year.

**Interfaces:**

- Consumes: `PrecedenceContext(LiturgicalEventCollection $cal, CalendarParams $params, LocaleDateFormatter $localeDateFormatter, array &$messages)`;
  `AmbrosianPrecedenceResolver::resolve($ctx)`.

- [ ] **Step 1:** Write a failing test that assembles the 2025 Ambrosian year (temporale + sanctorale, Tasks 3–4) and asserts that BEFORE `resolve()`, `StAmbrose` and `Advent4`
  both sit on 2025-12-07; then after building a `PrecedenceContext` and calling `resolve()`, `StAmbrose` has anticipated to **2025-12-06** (the norm-correct behaviour validated in
  Plan 6, confirmed against the chiesadimilano.it ordo — see [[ambrosian-ordo-validation-source]]). This is the end-to-end proof that season-stamping (Task 6 must run first — see
  ordering) + the resolver fire on real handler-assembled data.

- [ ] **Step 2:** In the orchestrator, construct the `PrecedenceContext` from `$this->Cal`, `$this->CalendarParams`, `$this->localeDateFormatter`, and `$this->Messages` (by-ref),
  and call `(new AmbrosianPrecedenceResolver())->resolve($ctx)`. **Ordering:** this MUST run AFTER Task 6's sanctorale season-stamping (the resolver's season-gated branches read
  `liturgical_season`). Wire the orchestrator step order per the Architecture note.

- [ ] **Step 3:** Test green; golden master untouched; analyse + lint. Commit.

---

### Task 6: Ambrosian season-on-sanctorale + Holy-Days-of-Obligation pass

**Files:**

- Create: `src/Enum/AmbrosianHolyDaysOfObligation.php` (or a const list) — the Ambrosian HDoO default set.
- Modify: `src/Models/Calendar/LiturgicalEventCollection.php` — a new `setAmbrosianSeasonsAndHolyDaysOfObligation()` (or two methods) ADDED alongside the Roman one (do NOT change
  the Roman method).
- Test: `phpunit_tests/Models/Calendar/` + `phpunit_tests/Handlers/`.

**Background (from the study):** the Roman `setSeasonsAndHolyDaysOfObligation()` needs `AshWednesday` + the 6 Roman seasons and can't run for Ambrosian. Its **season half is
redundant for temporale** (self-stamped) but **sanctorale events have no season**. Its **HDoO half** (`array_keys(array_filter($params->HolyDaysOfObligation))` +
`in_array($event_key, …)`) is rite-agnostic and reusable, but needs an Ambrosian HDoO key set.

- [ ] **Step 1: Season on sanctorale.** Write a failing test: a comune saint (e.g. one falling in Lent) has `liturgical_season === null` after assembly. Implement
  `stampAmbrosianSeasonOnSanctorale()` on the collection: for every event whose `liturgical_season` is null, assign the season of that DATE — computed from the Ambrosian temporale
  anchors already in the collection (Advent1, Christmas/Circoncisione, Epiphany/BaptismLord, Lent1, HolyThurs, Easter, Pentecost, and the after-* boundaries), OR by copying the
  season of the temporale event already occupying that date if one exists (`getCalEventsFromDate`). Use the Ambrosian season set (`LitSeason::AFTER_EPIPHANY`/`AFTER_PENTECOST`
  etc.), NOT the Roman date-range logic. Assert the Lenten saint now has `LitSeason::LENT`. **This step must run before Task 5's `resolve()`.**

- [ ] **Step 2: Ambrosian HDoO set.** Define the Ambrosian holy-days-of-obligation default (event_key ⇒ true). Base it on the Italian/Swiss conference set over Ambrosian keys —
  e.g. `Christmas, Epiphany, Ascension, Pentecost, Circoncisione (Jan 1), Assumption, AllSaints, ImmaculateConception, StAmbrose (Milan patron), DedicationDuomo`, plus every
  Sunday. **Flag the exact set for ordo-validation (Plan 9)** — this is provisional; the mechanism must be correct even if the membership is refined later. Do NOT reuse the Roman
  `CalendarParams::$HolyDaysOfObligation` default (Roman keys like `StJoseph`/`StsPeterPaulAp`/`CorpusChristi` and Roman `MaryMotherOfGod` don't match Ambrosian keys).

- [ ] **Step 3: HDoO stamping.** Implement the HDoO half (mirror the Roman loop at 1095-1097): for each event whose `event_key` is in the Ambrosian HDoO set, set
  `holy_day_of_obligation = true`; additionally mark every Sunday (`(int)$date->format('N') === 7`) as a holy day of obligation (Ambrosian, like Roman, obliges all Sundays). Write
  a test asserting `Christmas` and a Sunday are HDoO and an ordinary ferial weekday is not.

- [ ] **Step 4:** Golden master untouched (Roman method not modified); new tests green; analyse + lint. Commit.

---

### Task 7: Ambrosian year cycles + first-vespers vigils

**Files:**

- Modify: `src/Models/Calendar/LiturgicalEventCollection.php` — new `setAmbrosianYearCyclesAndVigils()` alongside the Roman one; new `calculateAmbrosianVigilMass()` +
  `ambrosianEventCanHaveVigil()` alongside the Roman vigil methods.
- Test: `phpunit_tests/Models/Calendar/`.

**Background:** the A/B/C Sunday cycle (off `Advent1` date + `$Year%3`) and I/II weekday cycle (`($Year-1)%2`) arithmetic are rite-agnostic (Roman `setYearCyclesAndVigils`
1142-1156, consts `SUNDAY_CYCLE`/`WEEKDAY_CYCLE`) — REUSE the arithmetic. But `inOrdinaryTime()` (1016-1032) needs `AshWednesday` → replace the I/II guard with an Ambrosian
"ordinary" test (season ∈ {AFTER_EPIPHANY, AFTER_PENTECOST}). The ~150 lines of Roman **lectionary readings** retrieval are OMITTED (empty-readings placeholder already set in Task
4). The vigil mechanism (`_vigil` key, `is_vigil_mass`/`is_vigil_for`/`has_vesper_i`/`has_vesper_ii`, `createVigilMassFor` 1378-1408) is reusable; the eligibility
(`liturgicalEventCanHaveVigil` anchors `PalmSun`/`Easter`/`Easter2`/`AllSouls`/`AshWednesday`, the `SOLEMNITIES_LORD_BVM` list, the `Year===2022` decree) is Roman → adapt.

- [ ] **Step 1: Year-cycle test + impl.** Failing test: a 2025 Ambrosian Sunday has `liturgical_year` set to the correct A/B/C string; an after-Pentecost ferial weekday has the
  correct I/II string. Implement `setAmbrosianYearCyclesAndVigils()` reusing the A/B/C math (keyed off `Advent1`) and the I/II math, with the I/II guard = `liturgical_season ∈
  {AFTER_EPIPHANY, AFTER_PENTECOST}` (the Ambrosian "ordinary" analogue) instead of `inOrdinaryTime()`. **Do NOT retrieve readings** (they're placeholders). Suppress the cycle
  string for the fixed-date events where Roman does (adapt the 1208 list to Ambrosian: `Christmas`, `Circoncisione`, `Epiphany`).

- [ ] **Step 2: Vigil test + impl.** Failing test: a 2025 Ambrosian Solemnity (e.g. `DedicationDuomo` or `Assumption`) gets a `{key}_vigil` event on the prior day with
  `is_vigil_mass=true`/`is_vigil_for={key}`, and the parent gets `has_vesper_i/ii=true`. Implement `ambrosianEventCanHaveVigil()` + `calculateAmbrosianVigilMass()` reusing
  `createVigilMassFor`'s field-stamping, but with: Ambrosian eligibility (dominical Sundays + grade ≥ SOLEMNITY, minus the Triduum window using the Ambrosian anchors
  `PalmSun`/`Easter`/`Easter2` which DO exist in the Ambrosian temporale); NO `AshWednesday`/`AllSouls` Roman exclusions; NO `SOLEMNITIES_LORD_BVM`/`Year===2022` Roman special
  cases (use `is_dominical` for the of-the-Lord distinction). Keep it day-granularity (a `_vigil` Mass event + the vesper flags), matching the Roman model the response schema
  expects. **Flag the exact Ambrosian vigil-eligibility rules for ordo-validation (Plan 9).**

- [ ] **Step 3:** Golden master untouched; tests green; analyse + lint. Commit (may be two commits: cycles, then vigils).

---

### Task 8: Psalter week for the Ambrosian collection

**Files:**

- Modify: `src/Models/Calendar/LiturgicalEventCollection.php` (`addLiturgicalEvent` psalter pre-stamp guard, and/or reuse `calculatePsalterWeek()`)
- Test: `phpunit_tests/Models/Calendar/`.

**Background:** `calculatePsalterWeek()` (1824-1871) is a rite-agnostic gap-filler and works as-is. BUT `addLiturgicalEvent`'s regex `/(Advent|Lent|Easter)([1-7])/` (258)
auto-stamps Ambrosian `Advent1..6`/`Lent1..5` with ROMAN psalter numbering (`psalterWeek(6)=2`, etc.) — potentially wrong for the Ambrosian psalter (Ambrosian Advent has 6 weeks).
The after-* Sundays get `null` → 0 from `calculatePsalterWeek`.

- [ ] **Step 1:** Decide the Ambrosian psalter-week policy. Simplest correct-enough policy for this plan: let `calculatePsalterWeek()` run as-is (fills gaps with 0), and either (a)
  accept the Roman-regex pre-stamp on Advent/Lent Sundays as a provisional value flagged for Plan 9 ordo-validation, or (b) if the Ambrosian psalter genuinely differs, add a rite
  guard so the `/(Advent|Lent|Easter)([1-7])/` pre-stamp is skipped for Ambrosian (leaving those to `calculatePsalterWeek`). Prefer (a) unless ordo evidence says otherwise — keep
  the change minimal.

- [ ] **Step 2:** Test that after the full Ambrosian pipeline every event has a non-null `psalter_week` (0 is valid). Wire `calculatePsalterWeek()` into the orchestrator. Golden
  master untouched (the guard, if added, is behind a rite check). Commit.

> **Note:** `calculatePsalterWeek()` is shared and used by Roman — if you touch it or `addLiturgicalEvent`, gate every change behind `Rite::AMBROSIAN` so Roman psalter numbering is
> byte-identical. Verify with the golden master.

---

### Task 9: The `calculateAmbrosianCalendar()` orchestrator (still behind the 501)

**Files:**

- Modify: `src/Handlers/CalendarHandler.php` — assemble Tasks 3–8 into one private method.
- Test: `phpunit_tests/Handlers/` — a full-pipeline handler test that invokes the orchestrator directly (NOT through the 501-gated `handle()` yet).

- [ ] **Step 1:** Implement `calculateAmbrosianCalendar()` calling, in order: rite-aware tempore load (T3) →
  `RiteProfileFactory::forRite(AMBROSIAN)->temporaleEngine()->buildTemporale(...)` → `addAmbrosianSanctoraleToCalendar()` (T4) → `stampAmbrosianSeasonOnSanctorale()` (T6 season) →
  `resolve()` (T5) → `setAmbrosianHolyDaysOfObligation()` (T6 HDoO) → `setAmbrosianYearCyclesAndVigils()` (T7) → `calculatePsalterWeek()` (T8) → `sortLiturgicalEvents()`.

- [ ] **Step 2:** Handler test: directly call `calculateAmbrosianCalendar()` for 2025 (via a test seam or reflection, as the existing handler tests do) and assert a complete,
  resolved, seasoned, HDoO-marked, cycle-stamped calendar — including the end-to-end `StAmbrose → 2025-12-06` anticipation and every day covered (reuse the Plan-6 completeness idea
  at the handler level). The 501 is STILL in place; this test bypasses it. Golden master untouched. Commit.

---

### Task 10: Flip the 501 → route to the Ambrosian orchestrator (endpoint goes live)

**Files:**

- Modify: `src/Handlers/CalendarHandler.php` (`handle()`, the 501 block at 5019-5023 and the generation branch).
- Test: `phpunit_tests/Routes/` — a live integration test hitting `/calendar/ambrosian/{year}` (skipped when `localhost:8000` is down, per `ApiTestCase`), plus the
  schema-validation gate on the real response.

- [ ] **Step 1:** Replace the 501 block: when `Rite::AMBROSIAN`, route the generation to `calculateAmbrosianCalendar()` instead of throwing — honouring `YearType::LITURGICAL` (two
  runs + splice) exactly as the Roman branch does (5065-5144). Keep the Roman branch byte-identical. The comune case = no national/diocesan layer (both null), so skip
  `applyNationalCalendar`/`applyDiocesanCalendar` for Ambrosian (dioceses are Plan 8).

- [ ] **Step 2: Schema gate.** Add a test that generates the Ambrosian 2025 calendar response and validates the FULL response against `LitCal.json` (with the Task-1 additions) —
  this is the acceptance gate that the response is well-formed (AFTER_* seasons, is_dominical/is_aliturgical, empty readings all satisfy the schema).

- [ ] **Step 3: Integration test** (`Routes/`, `ApiTestCase`): `GET /calendar/ambrosian/2025` returns 200 (not 501) with a `litcal` array; spot-check `DedicationDuomo`,
  `ChristKing`, `StAmbrose` (2025-12-06), and that `settings.rite`/metadata reflect Ambrosian. Mark `@group slow` if it does a full-year generation.

- [ ] **Step 4:** Golden master 9/9 (Roman untouched); full suite green; analyse + lint. Commit. **The endpoint is now live.**

---

### Task 11: `/calendars` discovery announces the comune ambrosiano

**Files:**

- Modify: `src/Services/CalendarMetadataProvider.php`, `src/Models/Metadata/MetadataCalendars.php` (+ a new item type if needed)
- Test: `phpunit_tests/Handlers/` or `Services/`.

**Background:** the comune `/calendar/ambrosian` has NO representation in the metadata today (it's neither nation, diocese, nor wider region), and there's no `rite` dimension.

- [ ] **Step 1:** Decide the surface. Minimal: add a top-level `ambrosian` (or `rites`) entry to `MetadataCalendars` announcing the comune calendar path (`/calendar/ambrosian`),
  its supported locales (`it`, `la` — the Ambrosian i18n locales), and its rite. Write a failing test asserting `/calendars` output includes the Ambrosian comune with its locales.

- [ ] **Step 2:** Implement the new builder in `CalendarMetadataProvider::create()` (add e.g. `self::buildAmbrosianCalendarData($metadata)`), and extend `MetadataCalendars` with
  the field + its serialization. Keep the existing national/diocesan/wider-region output byte-identical. Update the metadata schema (`jsondata/schemas/`) if `/calendars` has one.

- [ ] **Step 3:** Test green; existing `/calendars` (Roman) output unchanged; analyse + lint. Commit.

---

### Task 12: `/events` rite-aware (Ambrosian event catalog)

**Files:**

- Modify: `src/Router.php` (extract the rite segment for the `events` route), `src/Params/EventsParams.php` (add a `Rite` field), `src/Handlers/EventsHandler.php`
  (`processTemporaleEvents()` 344, `processSanctoraleEvents()` 304 — rite-branch to the Ambrosian files)
- Test: `phpunit_tests/Handlers/EventsHandlerTest.php` or `Routes/`.

**Background:** `/events` has NO rite awareness — the Router builds `EventsHandler` without a rite (`Router.php:324`), `EventsParams` has no `Rite`, and the temporale/sanctorale
processors are Roman-hardcoded.

- [ ] **Step 1:** Add rite extraction for the `events` route in `Router::route()` (mirror the `calendar` route's `extractRiteSegment` usage — support `/events/ambrosian`), thread
  the `Rite` into `new EventsHandler(...)` and into `EventsParams`. Write a failing test: `/events/ambrosian` yields a catalog containing an Ambrosian-only key (e.g.
  `DedicationDuomo`, `Circoncisione`) and NOT a Roman-only one.

- [ ] **Step 2:** Rite-branch `processTemporaleEvents()` (read `JsonData::AMBROSIAN_TEMPORALE_FILE`/`_I18N_FILE` when Ambrosian) and `processSanctoraleEvents()` (read
  `AMBROSIAN_SANCTORALE_FILE`/`_I18N_FILE` via `AmbrosianMissalResolver`, instead of `RomanMissal::getLatinMissalIds()`). Skip the Roman national/decrees processors for Ambrosian
  (comune only).

- [ ] **Step 3:** Test green; Roman `/events` byte-identical; analyse + lint. Commit.

---

### Task 13: Whole-branch acceptance + docs

- [ ] **Step 1:** Full suite: `composer test` (incl. `@group slow`), `composer analyse`, `composer lint`, `composer lint:openapi`, golden master 9/9. All green.
- [ ] **Step 2:** Manual spot-check (if `localhost:8000` available): `GET /calendar/ambrosian/2025` and `/2026`, eyeball against the chiesadimilano.it ordo for a few dates (Advent
  I, Dedication, Christ the King, St Ambrose Dec 6). Record findings in this plan's "Ordo-validation findings" section.
- [ ] **Step 3:** Update `README`/handler docs to note `/calendar/ambrosian`, `/events/ambrosian`, and `/calendars` Ambrosian discovery are live (comune only; dioceses = Plan 8).
  Commit.

---

## Post-Plan Notes

- **Deferred to Plan 8 (diocesan overlays):** the 4 Ambrosian dioceses (milano_it, bergam_it, novara_it, lugano_ch), the `lugano_ch` no-Swiss-national-tier fix
  (`loadDiocesanCalendarData` forces `NationalCalendar=CH` at `:444` → `loadNationalCalendarData` 503s on missing `CH/CH.json`), `DiocesanCalendar` schema `+supported_rites/rite`,
  own-church-dedication modelling.
- **Deferred to Plan 9 (ordo-validation + 1976 backfill):** the Ambrosian HDoO membership set (Task 6), the vigil-eligibility rules (Task 7), the Ambrosian psalter-week numbering
  (Task 8), exact ordo names/numbering, the pre-2008/1976 edition branch.
- **Deferred to a dedicated future plan (Ambrosian lectionary):** real readings (this plan ships the empty-readings placeholder). When authored, `setAmbrosianYearCyclesAndVigils`
  gains the readings-retrieval half and the placeholder is replaced.
- **Whole-branch final review:** dispatch on the most capable model. Focus lenses: (1) Roman byte-identity — every shared method (`addLiturgicalEvent`, `calculatePsalterWeek`,
  `LitSeason::forEventKey`) change gated behind a rite check; (2) the Ambrosian orchestrator step ORDER (season-on-sanctorale before `resolve()`; seasons/HDoO before cycles/vigils;
  psalter last); (3) the `YearType::LITURGICAL` two-run splice for Ambrosian; (4) response-schema validity of the live output; (5) no un-guarded null-deref on the new anchor
  lookups.
