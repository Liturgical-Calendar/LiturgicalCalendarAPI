# Lectionary Source Links Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan
> task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give each liturgical event in `/calendar` a `readings_source_url` linking to its readings on the USCCB (US calendars) or CEI (Italian calendars)
website, as designed in issue #1074 (steps 1–5 of its "Order of work").

**Architecture:** One source-data sidecar, `jsondata/sourcedata/rite/roman/lectionary_sources.json`, holds each source's URL patterns, published date range,
calendars, and a `pages` map of the leftovers that cannot be computed (USCCB page slugs, CEI section ids). A pure resolver class turns (calendar, event key,
date, vigil flag) into a URL or null; `CalendarHandler` stamps the result on each event before serialization. A probe script keeps the date range current
through reviewed PRs.

**Tech Stack:** PHP 8.4, PHPUnit 12, JSON Schema draft-07 (`swaggest/json-schema` via `LitSchema`), the existing engine cache digest.

**Spec:** issue #1074 body (<https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/1074>), with one decision changed by the maintainer on
2026-11-23: the leftovers live in the sidecar's `pages` map, **not** in a `source_pages` key inside each lectionary entry. Reason:
`ReadingsMap::addFromArray()` (`src/Models/Lectionary/ReadingsMap.php:211`) picks an entry's readings type from its exact key set, so an extra key throws
"unknown schema", and per-locale files would each have to carry or omit the other conference's ids.

## Global Constraints

- Work only in the worktree `/home/johnrdorazio/development/LiturgicalCalendar/wt-1074-sources` (branch `feat/1074-lectionary-source-links`, from
  `origin/development`). Never commit in the shared main checkout.
- The sidecar MUST stay under `jsondata/sourcedata/` with extension `.json`, so `CalendarHandler::computeEngineCacheDataVersion()` covers it (#1074 §4).
- Never widen `CommonDef.json#/definitions/Readings`; the new output field is a sibling of `readings`, not inside it.
- New schema has role `SchemaRole::SOURCE`.
- Links are emitted only for `Rite::ROMAN`, only when the requested calendar's nation is in the source's `calendars`, and only when the event date is within
  `[first_date, last_date]` inclusive. A General Roman Calendar request gets no link.
- Initial values (from #1074, probed 2026-11-19): USCCB `first_date` `2013-01-01`, `last_date` `2027-12-01`, calendars `["US"]`; CEI `first_date`
  `2016-01-01`, `last_date` `2027-02-03`, calendars `["IT"]`.
- URL patterns, verbatim: USCCB day `https://bible.usccb.org/bible/readings/{MM}{DD}{YY}.cfm`, linked `https://bible.usccb.org/bible/readings/{page}`;
  CEI day `https://www.chiesacattolica.it/liturgia-del-giorno/?data-liturgia={YYYY}{MM}{DD}`, linked same plus `#{page}`.
- The probe script never retries past a 403 or 429, sends no spoofed headers, and is a dry run unless `--apply` is given.
- Handler tests need `.env.local` with `JWT_SECRET` (copied from the main checkout); a run where they all SKIP is not a pass.
- Golden-master fixtures are regenerated only through the `golden-master-generate` group, and the fixture diff must contain only `readings_source_url` lines.
- Commits end with the `Co-Authored-By` and `Claude-Session` trailers; never `--no-verify`.

## Review Focus

- **Vigil Masses.** An anticipated Sunday or solemnity vigil (`…_vigil`, dated the evening before) has no USCCB page of its own unless `pages` lists one;
  linking its own date's day page would show the previous day's readings. Expected: no link unless `pages` has the vigil key, and `{day}` in a vigil page
  is the day **after** the vigil's date (the USCCB files `081526-Vigil.cfm` under the Assumption's own date). Pinned in Task 2.
- **December dates.** The weekday and Sunday cycle of a date after the First Sunday of Advent belong to the next liturgical year; a CEI id keyed `I`/`II`
  picked by civil year would be wrong for every Advent memorial. Pinned in Task 2 (StAmbrose, 7 December).
- **Diocesan and Ambrosian calendars.** A diocese of the US gets USCCB links (its nation is US); the Ambrosian dioceses in Italy (e.g. `milano_it`) must get
  none, since the CEI page follows the Roman rite. Pinned in Task 3.
- **Range edges.** `first_date` and `last_date` are inclusive; the day after `last_date` gets no link. Pinned in Task 2.
- **A cycle-keyed value missing the requested cycle** (e.g. `{"II": "119"}` in a year-I year) falls back to the day page without a fragment, never to
  another cycle's id. Pinned in Task 2.

---

### Task 1: The sidecar, its schema, and its data

**Files:**

- Create: `jsondata/schemas/LectionarySources.json`
- Create: `jsondata/sourcedata/rite/roman/lectionary_sources.json`
- Create: `scripts/build-lectionary-sources-pages.php` (one-off converter, kept for re-runs from the #1074 map comment)
- Modify: `src/Enum/LitSchema.php` (new case `LECTIONARY_SOURCES = '/LectionarySources.json'`, error message, role `SOURCE`, path map)
- Modify: `src/Enum/JsonDataConstants.php`, `src/Enum/JsonData.php` (new `LECTIONARY_SOURCES_FILE = ROMAN_RITE_FOLDER . '/lectionary_sources.json'`)
- Modify: `src/Models/ValidationsPath/CheckableInventory.php` (one static file item `lectionary:roman:sources`, schema `LitSchema::LECTIONARY_SOURCES`)
- Test: `phpunit_tests/Schemas/LectionarySourcesSchemaTest.php`

**Interfaces:**

- Produces: `JsonData::LECTIONARY_SOURCES_FILE->path()`; `LitSchema::LECTIONARY_SOURCES`; the file shape below, which Task 2 reads.

File shape (schema enforces it; `additionalProperties: false` at every level):

```json
{
  "usccb": {
    "day_page": "…{MM}{DD}{YY}.cfm",
    "linked_page": "…{page}",
    "first_date": "2013-01-01",
    "last_date": "2027-12-01",
    "calendars": ["US"],
    "pages": { "StGregoryGreat": "0903-memorial-gregory.cfm", "OurLadyOfMountCarmel": "0716-memorial-our-lady-mount-carmel.cfm" }
  },
  "cei": { "…": "…", "pages": { "StBlase": { "I": "607", "II": "119" }, "OrdSunday13": { "A": "847" } } }
}
```

- Source keys match `^[a-z]+$`. `first_date`/`last_date`: `format: date`. `calendars`: non-empty array of `^[A-Z]{2}$`. `pages` keys:
  `CommonDef.json#/definitions/EventKey`; values: a non-empty string, or an object with `propertyNames` enum `["I","II","A","B","C"]`, `minProperties: 1`,
  string values.

- [ ] **Step 1: Write the failing tests** in `LectionarySourcesSchemaTest`:
  `testSourceFileValidatesAgainstItsSchema` (validate the real file with `LitSchema::LECTIONARY_SOURCES` the way `SchemaValidationTest` does);
  `testSchemaRoleIsSource` (`LitSchema::LECTIONARY_SOURCES->role() === SchemaRole::SOURCE`);
  `testRejectsUnknownCycleKey` (a pages value `{"III": "1"}` fails); `testRejectsEmptyCalendars`;
  `testEveryPagesKeyIsAKnownEventKey` (every `pages` key of every source appears as a key in some Roman lectionary file under `JsonData::LECTIONARY_FOLDER`
  or a missal `lectionary/` folder, or as `X_vigil` with `X` such a key).
- [ ] **Step 2: Run** `vendor/bin/phpunit phpunit_tests/Schemas/LectionarySourcesSchemaTest.php` — expected FAIL (undefined case / missing file).
- [ ] **Step 3: Add the schema, enum cases, inventory item, and an empty-`pages` data file** with the Global Constraints values.
- [ ] **Step 4: Write `scripts/build-lectionary-sources-pages.php`.** Input: a JSON file mapping `section/event_key` to `{"usccb"?: v, "cei"?: v}` (the
  #1074 map comment's format; saved at `docs/superpowers/plans/2026-11-23-source-pages-map.json`, 320 entries, 86 USCCB, 287 CEI). Conversion:
  `feriale_per_annum_I/X` → `pages[X]["I"]`, `feriale_per_annum_II/X` → `["II"]`, `dominicale_et_festivum_{A,B,C}/X` → `[A|B|C]`; every other section
  (`sanctorum`, `feriale_tempus_*`, `propriumdesanctis_US_2011`, …) → the value as given (string or already-cycle-keyed object). Two sections writing the
  same key with different plain strings is an error naming both. Writes `pages` with keys sorted; `--apply` writes, otherwise prints a summary.
- [ ] **Step 5: Run it with `--apply`** and check the summary: 86 USCCB keys, the CEI count after `I`/`II` merging, zero errors.
- [ ] **Step 6: Run** the Task 1 test, `composer lint:jsondata`, `composer analyse` — expected PASS. Also `vendor/bin/phpunit --filter CheckableInventory`.
- [ ] **Step 7: Commit** `feat(data): lectionary_sources.json with the USCCB and CEI page leftovers (#1074)`.

### Task 2: The resolver

**Files:**

- Create: `src/Models/Lectionary/LectionarySources.php`
- Test: `phpunit_tests/Models/Lectionary/LectionarySourcesTest.php`

**Interfaces:**

- Consumes: the file from Task 1.
- Produces:
  - `LectionarySources::fromFile(string $path): self` (throws `\RuntimeException` on unreadable or undecodable file);
  - `LectionarySources::fromArray(array $data): self`;
  - `public function urlFor(string $nation, Rite $rite, string $eventKey, \DateTimeInterface $date, bool $isVigilMass): ?string`;
  - `public static function liturgicalYearOf(\DateTimeInterface $date): int` (year whose First Sunday of Advent precedes the date: civil year + 1 from that
    Sunday on).

Rules for `urlFor`: pick the first source whose `calendars` contains `$nation`; return null if none, if `$rite !== Rite::ROMAN`, or if `$date` is outside
the range. Look up `pages[$eventKey]`: a string is used as is; an object picks the weekday cycle (`I` if `liturgicalYearOf` is odd, else `II`) or the Sunday
cycle (`['C','A','B'][liturgicalYearOf % 3]`), trying the weekday key first then the Sunday key; a missing cycle means "no page". With a page, fill
`linked_page` (`{page}`, and `{day}` inside the page value as `MMDDYY` of the date, or of the next day when `$isVigilMass`); without a page, return null if
`$isVigilMass`, else fill `day_page` from the date. Placeholders: `{YYYY}`, `{YY}`, `{MM}`, `{DD}` (zero-padded).

- [ ] **Step 1: Write the failing tests** (`fromArray` with the Global Constraints URL patterns and a small `pages` map):
  - `testUsDayPage`: (`US`, ROMAN, `OrdWeekday27Monday`, 2025-10-06, false) → `https://bible.usccb.org/bible/readings/100625.cfm`.
  - `testUsMemorialPage`: `pages.StGregoryGreat = "0903-memorial-gregory.cfm"`, date 2025-09-03 → `…/readings/0903-memorial-gregory.cfm`.
  - `testItSectionFragmentByWeekdayCycle`: `pages.StBlase = {"I":"607","II":"119"}`; 2025-02-03 → `…?data-liturgia=20250203#607`; 2024-02-03 → `…#119`.
  - `testAdventDateUsesNextLiturgicalYear`: `pages.StAmbrose = {"II": "29"}`; 2025-12-07 (liturgical year 2026, II) → `…?data-liturgia=20251207#29`;
    `liturgicalYearOf(2025-12-07) === 2026`, `liturgicalYearOf(2025-11-29) === 2025`, `liturgicalYearOf(2022-11-27) === 2023` (Advent begins 27 Nov 2022).
  - `testSundayCycle`: `pages.OrdSunday13 = {"A":"847"}`; 2023-07-02 (year A) → `#847`; 2024-06-30 (year B) → day page without fragment.
  - `testMissingCycleFallsBackToDayPage`: `{"II":"119"}` on a year-I date → day page, no `#`.
  - `testVigilWithoutPageHasNoLink`: (`US`, `Assumption_vigil`, 2026-08-14, true) with no pages entry → null.
  - `testVigilPageUsesNextDay`: `pages.Assumption_vigil = "{day}-Vigil.cfm"`, 2026-08-14 → `…/readings/081526-Vigil.cfm`.
  - `testRangeIsInclusive`: `last_date` 2027-12-01 → link on 2027-12-01, null on 2027-12-02; `first_date` likewise.
  - `testOtherNationOrRiteHasNoLink`: `CA` → null; (`IT`, `Rite::AMBROSIAN`) → null.
- [ ] **Step 2: Run** `vendor/bin/phpunit phpunit_tests/Models/Lectionary/LectionarySourcesTest.php` — expected FAIL (class not found).
- [ ] **Step 3: Implement `LectionarySources`** per the rules above (First Sunday of Advent: the fourth Sunday before 25 December, i.e. 25 Dec minus its
  ISO weekday modulo 7 — a Sunday Christmas counts back a full week — minus 21 days).
- [ ] **Step 4: Run** the test and `composer analyse` — expected PASS.
- [ ] **Step 5: Commit** `feat: resolve an event's readings page on the USCCB or CEI site (#1074)`.

### Task 3: The output field

**Files:**

- Modify: `src/Models/Calendar/LiturgicalEvent.php` (`public ?string $readings_source_url = null;` serialized right after `readings` when non-null; add to
  the array-shape docblock)
- Modify: `src/Handlers/CalendarHandler.php` (in `prepareResponseBody()`, before `->litcal` is assigned: when `CalendarParams->NationalCalendar !== null`,
  load `LectionarySources::fromFile(JsonData::LECTIONARY_SOURCES_FILE->path())` once per process and set each event's `readings_source_url` from
  `urlFor(NationalCalendar, Rite, event_key, date, is_vigil_mass === true)`)
- Modify: `jsondata/schemas/LitCal.json` (`LiturgicalEvent.properties.readings_source_url`: `type: string`, `format: uri`, title "Readings source URL",
  description naming the USCCB and CEI and the range rule); also `LiturgicalEventVigil` if it lists properties separately
- Modify: `jsondata/schemas/LiturgicalCalendar.xsd` (`<xs:element name="ReadingsSourceUrl" minOccurs="0" type="xs:anyURI" />` after `Readings`)
- Modify: `jsondata/schemas/openapi.json` only if `grep -n readings jsondata/schemas/openapi.json` shows an inline copy of the event properties (it refs
  `./LitCal.json` today); then run `composer lint:openapi` and `composer lint:jsondata`
- Modify: `phpunit_tests/fixtures/golden-master/nation-US-2023.json`, `nation-IT-2023.json`, `diocese-romamo-2023.json` (regenerated)
- Test: `phpunit_tests/Handlers/CalendarReadingsSourceUrlTest.php` (extends `AbstractHandlerTestCase`)

**Interfaces:**

- Consumes: `LectionarySources::fromFile()`, `::urlFor()` (Task 2); `JsonData::LECTIONARY_SOURCES_FILE` (Task 1).
- Produces: the `readings_source_url` property on `/calendar` events.

- [ ] **Step 1: Write the failing tests**, each calling the handler in-process for one calendar and year and indexing `litcal` by `event_key`:
  - `testUsCalendarLinksMemorialPage`: `/calendar/nation/US/2025` → `StGregoryGreat` has `…/readings/0903-memorial-gregory.cfm`; `OrdWeekday27Monday` has
    `…/readings/100625.cfm`.
  - `testItalianCalendarLinksCei`: `/calendar/nation/IT/2025` → `StBlase` has `…?data-liturgia=20250203#607`.
  - `testUsDioceseInheritsUsccb`: a US diocese id from `GET /calendars` (pick one present in `jsondata/sourcedata/rite/roman/calendars/dioceses/US/`) → an
    event has a `bible.usccb.org` link.
  - `testGeneralCalendarHasNoLinks`: `/calendar/2025` → no event has `readings_source_url`.
  - `testAmbrosianDioceseHasNoLinks`: `/calendar/diocese/milano_it/2025` → none.
  - `testOutOfRangeYearHasNoLinks`: `/calendar/nation/US/2010` → none.
  - `testResponseValidatesAgainstLitCalSchema`: the US 2025 response validates with `LitSchema::LITCAL` (reuse the pattern of
    `CalendarHandlerAmbrosianResponseSchemaTest`).
- [ ] **Step 2: Run** `vendor/bin/phpunit phpunit_tests/Handlers/CalendarReadingsSourceUrlTest.php` — expected FAIL (property absent), and confirm none
  SKIP.
- [ ] **Step 3: Implement** the property, handler stamping, LitCal.json, XSD (and openapi only if needed).
- [ ] **Step 4: Run** the new test, `vendor/bin/phpunit phpunit_tests/Handlers/CalendarHandlerXmlSchemaTest.php`, `composer analyse`, `composer lint`,
  `composer lint:jsondata` — expected PASS.
- [ ] **Step 5: Regenerate golden masters:** `vendor/bin/phpunit --list-tests --group golden-master-generate` (must list tests), then
  `vendor/bin/phpunit --group golden-master-generate`; `git diff --stat phpunit_tests/fixtures` touches only the three US/IT/romamo files, and
  `git diff phpunit_tests/fixtures | grep '^[+-] ' | grep -vc readings_source_url` prints `0`. Then `vendor/bin/phpunit phpunit_tests/Handlers/CalendarGoldenMasterTest.php` passes.
- [ ] **Step 6: Commit** `feat: readings_source_url on /calendar events for US and Italian calendars (#1074)`.

### Task 4: Keeping the range current — probe script, spec, cache pin

**Files:**

- Create: `src/Services/Lectionary/LectionarySourceProbe.php`
- Create: `scripts/probe-lectionary-sources.php`, `scripts/LECTIONARY_SOURCES_PROMPT.md`
- Modify: `composer.json` (script `probe:lectionary-sources`)
- Test: `phpunit_tests/Services/Lectionary/LectionarySourceProbeTest.php`, `phpunit_tests/Handlers/CalendarHandlerCacheVersionTest.php`

**Interfaces:**

- Consumes: the file shape (Task 1); `LectionarySources` placeholder filling — expose `public static function fillDate(string $pattern, \DateTimeInterface
  $date): string` on `LectionarySources` in this task if Task 2 kept it private.
- Produces: `LectionarySourceProbe::__construct(\Closure $fetch, int $pauseMs = 3000)` where `$fetch(string $url): array{status:int, body:string}`;
  `public function probe(string $sourceKey, array $source, int $maxDays = 62): ProbeResult` with readonly `?string $firstDate`, `?string $lastDate`,
  `?string $stoppedAt` (URL and status of a refusal), `list<string> $log`.

Missing-day rule: USCCB → status 404; CEI → status 200 whose body lacks `Prima Lettura`. A 403 or 429 stops the probe, sets `stoppedAt`, and leaves the
date unchanged. Forward probe: from `last_date + 1` while days exist (up to `$maxDays`); the new `last_date` is the last existing day. Backward: check
`first_date` and `first_date - 1`; report a move only when `first_date` itself is now missing (search forward up to `$maxDays`) or the day before now exists
(search back up to `$maxDays`).

- [ ] **Step 1: Write the failing tests** with a fake `$fetch` and `pauseMs: 0`:
  `testForwardProbeAdvancesLastDate` (days through 2027-12-05 exist → `lastDate` 2027-12-05);
  `testStopsAtFirstRefusal` (403 on the second request → `stoppedAt` set, dates null, exactly two requests made);
  `testCeiEmptyPageIsMissing` (200 without "Prima Lettura" after 2027-02-03 → `lastDate` null, meaning unchanged);
  `testFirstDateMovesBack` (2015-12-31 now exists on CEI → `firstDate` earlier).
  In `CalendarHandlerCacheVersionTest`, add `testLectionarySourcesFileIsCoveredByTheDigest`: assert
  `str_starts_with(JsonData::LECTIONARY_SOURCES_FILE->path(), Router::$apiFilePath . 'jsondata/sourcedata/')` and that it ends in `.json`, then, in a temp
  tree built the way the class's other tests build theirs, show the digest changes when a copy of the file at that relative path changes.
- [ ] **Step 2: Run** both test files — expected FAIL.
- [ ] **Step 3: Implement** the probe class; the script reads the file, probes each source, prints a unified summary, writes (re-encoded with the
  repository's `JsonFormatter`) only with `--apply`, exits 2 when a refusal stopped any source. Uses Guzzle with a plain user agent naming the project, no
  browser headers. Write `LECTIONARY_SOURCES_PROMPT.md`: the monthly task's authoritative spec (run the script dry, review, `--apply`, open one PR into
  `development` with the probe log as evidence, otherwise stop; never retry past a refusal; may add `pages` leftovers for new celebrations with the page
  each was read from).
- [ ] **Step 4: Run** the tests, `composer analyse`, `composer lint`, `composer lint:md` — expected PASS. Run `php scripts/probe-lectionary-sources.php`
  once against CEI only (`--source cei`) and record the output in the PR; expect USCCB to refuse a script, which is why the monthly task may use a browser.
- [ ] **Step 5: Commit** `feat: probe script and monthly-task spec for the lectionary source ranges (#1074)`.

### Task 5: Wrap-up

- [ ] `composer test:quick` (note pre-existing failures separately); `composer lint:locales`, `composer lint:missals`.
- [ ] Update #1074's design text in the PR body: the sidecar replaces `source_pages`, and the map comment is superseded by `lectionary_sources.json`.
- [ ] Push and open the PR into `development`, `Closes #1074` except its step 6 (the scheduled task, after merge).
