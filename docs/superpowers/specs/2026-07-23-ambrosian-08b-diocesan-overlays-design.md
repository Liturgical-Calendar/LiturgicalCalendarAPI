# Ambrosian Diocesan Overlays (8b) — Design

**Date:** 2026-07-23
**Status:** Approved (pending written-spec review)
**Part of:** Ambrosian rite integration arc — milestone 8b (diocesan overlays)
**Builds on:** 8a rite-partitioned sourcedata (merged, `origin/development` d9116637) — Ambrosian data lives under
`jsondata/sourcedata/rite/ambrosian/…`, and `rite/ambrosian/calendars/dioceses/` exists as the scan root.
**Followed by:** 8c (two-tier città-di-Milano church-dedication tier).

## Summary

Add DIOCESAN overlays for the Ambrosian rite: four diocese-level calendars —
`milano_it`, `bergam_it`, `novara_it` (Italy) and `lugano_ch` (Switzerland). In the
Ambrosian rite there is NO national layer, so a diocesan overlay sits directly on the
comune ambrosiano base (already live via `/calendar/ambrosian/{year}`). This milestone
delivers the diocesan-overlay INFRASTRUCTURE plus the four diocese calendars at
archdiocese/diocese level. Church-specific "città di Milano" rows are deferred to 8c.

Full parity is delivered: `/calendar/ambrosian/diocese/{id}` serves the overlay,
`/calendars` announces the four dioceses, and `/events/ambrosian/diocese/{id}` lists the
diocesan event catalog.

## Motivation

The comune ambrosiano is Milan-centric and shared by every Ambrosian diocese. Each of the
four dioceses adds a small set of proper saints, and some override a comune event's grade.
These are attributed by the Milan 2024 Missal's `CALENDARIO AMBROSIANO` section via
location tags (`Nell'arcidiocesi di Milano:`, `Nella diocesi di X:`,
`Nell'arcidiocesi di Milano e nella diocesi di X:`). 8a's rite partition made the
storage tree ready (`rite/ambrosian/calendars/dioceses/`); 8b wires the overlay path.

## Locked decisions (from brainstorming)

1. **Override semantics = diocesan-wins by event_key.** A diocesan row that re-declares a
   comune event_key overwrites it (grade/name/color) for that diocese, then participates in
   precedence. The `Al di fuori dell'arcidiocesi di Milano` negative rule is emitted as an
   override row in each of the three non-Milan dioceses.
2. **API surface = full parity:** `/calendar` + `/calendars` + `/events`.
3. **Data scope = Milan-missal-attributed rows only.** Archdiocese-wide + shared + diocese-
   exclusive rows; EXCLUDE church-specific `A Milano, nella basilica…` rows (→ 8c); NO
   synthetic per-diocese cathedral dedications (not in source).
4. **Schema = single optional `rite` (default ROMAN).**

## Architecture

### 1. Data & files

Four diocese files, mirroring the Roman diocesan format
(`rite/roman/calendars/dioceses/IT/agrige_it/…` is the reference):

```text
jsondata/sourcedata/rite/ambrosian/calendars/dioceses/
├── IT/
│   ├── milano_it/{Arcidiocesi di Milano.json, i18n/{it_IT,la_VA}.json}
│   ├── bergam_it/{Diocesi di Bergamo.json, i18n/…}
│   └── novara_it/{Diocesi di Novara.json, i18n/…}
└── CH/
    └── lugano_ch/{Lugano.json, i18n/…}
```

- File shape: `{ "litcal": [ { "liturgical_event": {event_key, color, grade, common, day,
  month}, "metadata": {since_year, form_rownum} }, … ], "metadata": {nation, diocese_id,
  diocese_name, locales, timezone, rite:"ambrosian"} }`. Names via `i18n/{locale}.json`
  (event_key → name), locales `it_IT` + `la_VA`.
- Diocese names from `world_dioceses.json`: "Arcidiocesi di Milano", "Diocesi di Bergamo",
  "Diocesi di Novara", "Lugano".
- **Shared rows** (`Milano e X`) are written into BOTH the Milan file and the X file.
- **Override rows** reuse the comune's event_key so the overlay overwrites the comune event
  for that diocese (e.g. `lugano_ch` re-declares the S. Francesco d'Assisi key at grade
  MEMORIAL; `bergam_it`/`novara_it`/`lugano_ch` re-declare SS. Protaso e Gervaso at MEMORIAL).
- **Excluded:** church-specific rows (`A Milano, nella basilica di…`) and any per-diocese
  cathedral dedication not present in the Milan missal.
- **No diocesan lectionary files.** Readings are empty placeholders emitted programmatically
  (see §5, festive readings).

### 2. Schema — `DiocesanCalendar.json`

Add optional `metadata.rite` (enum `roman`|`ambrosian`) to the
`DiocesanCalendarMetadata` definition (which is `additionalProperties:false`, so the new
key MUST be declared). Leave it out of `required` → absent means ROMAN, keeping every
existing Roman diocese file valid. Thread the field through
`src/Models/RegionalData/DiocesanData/DiocesanMetadata.php` (constructor, `fromArray`,
`fromObject`, phpstan typedefs) with a ROMAN default.

### 3. Discovery (rite-aware, data-driven) — `CalendarMetadataProvider`

- New `JsonDataConstants::AMBROSIAN_DIOCESAN_CALENDARS_FOLDER` =
  `AMBROSIAN_RITE_FOLDER . '/calendars/dioceses'`, mirrored in `JsonData`.
- `buildDiocesanCalendarData()` scans BOTH the Roman and the Ambrosian dioceses trees,
  tagging each `MetadataDiocesanCalendarItem` with a new `rite` field (default `roman`).
- Ambrosian dioceses are pushed into `diocesan_calendars_keys` (so the existence check in
  validation passes) but carry `rite=ambrosian`.
- The nation-parent attach loop (`MetadataCalendars.php:332`) SKIPS Ambrosian dioceses (they
  have no national parent).
- `AmbrosianRiteProfile::SUPPORTED_DIOCESES` becomes DATA-DRIVEN: derived from the discovered
  dioceses whose `rite=ambrosian`, replacing the hardcoded constant.

### 4. Validation (rite-scoped) — `CalendarParams`

`validateDiocesanCalendarParam()` becomes rite-aware: a diocese is valid only when its
discovered `rite` matches the request's rite. Consequences:

- `/calendar/ambrosian/diocese/milano_it` → OK.
- `/calendar/diocese/milano_it` (Roman) → 400 (milano_it is an Ambrosian diocese).
- `/calendar/ambrosian/diocese/agrige_it` → 400 (agrige_it is Roman).

This unifies the existence check with the rite check and removes the separate
`SUPPORTED_DIOCESES` whitelist enforcement at `CalendarParams.php:674`.

### 5. Loading + overlay — `CalendarHandler`

- **Decoupling:** rite-guard `loadDiocesanCalendarData()` (`:428-462`). For Ambrosian: build
  the file path from the Ambrosian dioceses folder (`rite/ambrosian/calendars/dioceses/…`)
  and SKIP the `$this->CalendarParams->NationalCalendar = strtoupper($nation)` coupling at
  `:449`. This fixes lugano_ch (no `nations/CH`) and prevents the wrong Roman-IT national
  pull for the three Italian dioceses. (`validateRiteCompatibility` already throws if
  `NationalCalendar !== null` for Ambrosian, so leaving it null is required.)
- **New `applyAmbrosianDiocesanCalendar()`**, inserted in `calculateAmbrosianCalendar()`
  **immediately after `addAmbrosianSanctoraleToCalendar()` (`:1061`) and before
  `backfillAmbrosianReadingsPlaceholder()` (`:1063`)**. It:
  - Iterates `DiocesanData->litcal`, applies `since_year`/`until_year` gating, resolves the
    date (fixed month/day; strtotime if used), sets festive readings (§ below), and adds each
    event into `$this->Cal`.
  - **Override rows** (event_key already present from the comune) OVERWRITE the existing
    event (diocesan-wins); **net-new rows** are added under their diocesan event_key.
  - Because events are added before season-stamp (`:1065`) and precedence
    (`:1067`), diocesan events are season-stamped and participate in the
    add-all-then-resolve-to-fixpoint (no bespoke Roman-style coincidence handling — the
    Ambrosian precedence resolver settles collisions).
  - Runs in BOTH the current-year and the prior-year `YearType::LITURGICAL` passes
    (`handle()` `:5347-5374`).
- **Festive readings (constraint):** diocesan saints are FESTIVE events. `Readings` is a
  `oneOf` of `ReadingsFerial` (4 fields) / `ReadingsFestive` (5 fields incl `second_reading`);
  the comune's `AmbrosianReadings::empty()` returns the ferial shape. Add
  `AmbrosianReadings::emptyFestive(): ReadingsFestive` (5 empty strings) and set it on each
  diocesan event at add time. Since `backfillAmbrosianReadingsPlaceholder()` only stamps
  events LACKING readings, diocesan events keep their festive readings and the ferial backfill
  touches only ferial temporale events.

### 6. API surface (full parity)

- **`/calendar/ambrosian/diocese/{id}`** — served by the loading+overlay path above. The
  `handle()` Ambrosian branch (`:5337`) gains the diocesan load (rite-guarded) before
  `calculateAmbrosianCalendar()`.
- **`/calendars`** — announces the four Ambrosian dioceses via the rite-tagged diocesan
  items (§3). They appear as diocesan calendars carrying `rite=ambrosian`.
- **`/events/ambrosian/diocese/{id}`** — diocesan event catalog. Extend the Router,
  `EventsParams`, and the events processing to accept a rite + diocese dimension (as Plan 7
  did for the comune `/events/ambrosian`), loading the Ambrosian diocesan sanctorale.

### 7. Post-processing

Diocesan events flow through the existing Ambrosian season / HDoO / cycles+vigils / psalter
steps unchanged, because they are in `$this->Cal` before those steps run
(`calculateAmbrosianCalendar()` `:1065-1073`).

## Scope boundaries (explicitly deferred)

- **8c:** church-specific `A Milano, nella basilica…` rows (two-tier città di Milano).
- Per-diocese cathedral dedications not in the Milan missal.
- Real Ambrosian readings (empty placeholders only — a dedicated lectionary plan remains
  deferred).
- The Roman diocesan path is UNTOUCHED (Ambrosian-only additions; shared methods rite-guarded).

## Verification

- **Roman golden-master 9/9 byte-identical** — diocesan overlay is Ambrosian-only; Roman
  path and shared methods rite-guarded.
- **Ambrosian comune unchanged** — `/calendar/ambrosian/{year}` with no diocese requested is
  byte-identical to pre-8b.
- **New behavior:**
  - `/calendar/ambrosian/diocese/milano_it` shows the Milan-proper saints.
  - `lugano_ch` shows S. Francesco d'Assisi at MEMORIAL (override of the comune Festa).
  - A shared saint (e.g. S. Giovanni XXIII, Oct 11) appears in BOTH `milano_it` and
    `bergam_it`.
  - `bergam_it`/`novara_it`/`lugano_ch` show SS. Protaso e Gervaso (Jun 19) at MEMORIAL
    (the `Al di fuori di Milano` override); `milano_it` keeps the comune FESTA.
  - `lugano_ch` loads with NO CH-national error (decoupling).
  - Festive diocesan events carry `ReadingsFestive`-shaped (5-field) readings, not ferial.
- **Discovery/validation:** `/calendars` lists the four Ambrosian dioceses; Roman requests for
  them 400; Ambrosian requests for Roman dioceses 400.
- **Schema:** the four Ambrosian diocesan files validate against `DiocesanCalendar.json`
  (Health-wired `SchemaValidationTest`).
- **End-to-end:** frontend docker stack + UnitTestInterface (the UTI now reads
  `rite/ambrosian/…` after 8a/UTI#38 — confirm any diocesan validation it adds).

## Key integration points (reference for the plan)

All `src/Handlers/CalendarHandler.php` unless noted, against d9116637:

- `calculateAmbrosianCalendar()` `:1047-1076`; sanctorale add `:1061`; readings backfill
  `:1063`/`:1106`; season stamp `:1065`; precedence resolve `:1067`. Insert
  `applyAmbrosianDiocesanCalendar()` between `:1061` and `:1063`.
- `handle()` rite branch `:5337`; Ambrosian LITURGICAL two-run `:5347-5374`; stale
  "dioceses are Plan 8" comment `:5338`.
- `loadDiocesanCalendarData()` `:428-462`; diocese→nation coupling `:449`.
- `applyDiocesanCalendar()` (Roman reference) `:4397-4494`.
- `CalendarParams::validateDiocesanCalendarParam()` `:450-461` (dispatch `:222-224`);
  `validateRiteCompatibility()` `:662-693` (nation guard `:668`, whitelist `:674`).
- `CalendarMetadataProvider::buildDiocesanCalendarData()` `:175-208`;
  `buildAmbrosianCalendarData()` `:296-311`.
- `MetadataDiocesanCalendarItem` (add `rite`); `MetadataCalendars.php` nation-attach loop
  `:332-337`, `diocesan_calendars_keys` push `:320`.
- `AmbrosianRiteProfile::SUPPORTED_DIOCESES` `:31`.
- `DiocesanMetadata.php` (add `rite`); `DiocesanCalendar.json` metadata def `:108-143`.
- `world_dioceses.json` diocese_id→name→nation lookup (all four present).
- `AmbrosianReadings` `src/Models/Lectionary/AmbrosianReadings.php` (add `emptyFestive()`);
  `ReadingsFestive` `src/Models/Lectionary/ReadingsFestive.php`.
