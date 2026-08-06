# Ambrosian Pentecost-anchored celebrations — design

**Date:** 2026-08-06
**Status:** approved (design), pending implementation plan
**Scope:** Add the five Pentecost-anchored celebrations missing from the Ambrosian temporale; add optional
year-gating to the Proprium de Tempore schema; transcribe the Missal's annual table as a regression oracle.

## Context

`/calendar/ambrosian` has been live since Plan 7 (PR #735) and diocesan overlays landed in Plan 8b (PR #738).
The temporale is calculated by `src/Models/Calendar/Temporale/AmbrosianTemporale.php` from
`jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore/propriumdetempore.json`.

That file holds **38 event keys**, and the post-Pentecost stretch runs straight from `Pentecost` to
`DedicationDuomo` to `ChristKing`. A case-insensitive search for `corpus`, `corpo e sangue`, `trinit`,
`cuore` and `madre della chiesa` across the entire `jsondata/sourcedata/rite/ambrosian/` tree returns
**zero hits**.

The Ambrosian calendar therefore currently omits five celebrations, including two solemnities of the
Lord. This is not a documented deferral: the rite-integration spec
(`2026-07-20-ambrosian-rite-integration-design.md` §3, line 161) assumed Corpus Christi already existed
("Ambrosian has its own rite-fixed placement") when it ruled the `corpus_christi` request parameter inert.
The data was simply never transcribed.

The intended outcome is that `/calendar/ambrosian` (and every Ambrosian diocesan calendar layered on it)
emits all five celebrations on the dates the Missal fixes, verified against the Missal's own published
table for 2025–2056.

## Sources

- **Messale Ambrosiano, II ed. 2024 — Premesse/Praenotanda** (`Premesse_praenotanda_portale.pdf`).
  - *Calendario ambrosiano*, pp. LXXV and LXXVII — the five celebrations, their anchoring rules and their grades.
  - *Tabella annuale delle principali celebrazioni dell'anno liturgico*, pp. LXXXVIII–LXXXIX — Easter,
    Ascension, Pentecost, Corpus Domini, the after-Pentecost and after-Martyrdom Sunday counts, the
    Dedication of the Duomo, and both lectionary cycles, for 2025–2056.

Grades in the *calendario* are printed in a separate left-hand column; the PDF must be read
layout-aware (word coordinates) or the grade will bind to the wrong row.

## The five celebrations

`dS` in the Missal marks a celebration *del Signore*. The Proprium de Tempore schema already models this
as `is_dominical`, described as "Whether the event is 'of the Lord' (dominical)" — which is why the
existing `Ascension` entry carries it despite falling on a Thursday.

| Missal rule                                        | Celebration                             | Grade            | `event_key`        | Easter offset | `is_dominical` |
|----------------------------------------------------|-----------------------------------------|------------------|--------------------|---------------|----------------|
| Lunedì dopo Pentecoste                             | B. Vergine Maria, Madre della Chiesa    | Memoria (3)      | `MaryMotherChurch` | +50           | no             |
| I domenica dopo Pentecoste                         | SS. TRINITÀ                             | Solennità dS (6) | `Trinity`          | +56           | yes            |
| Giovedì successivo alla I domenica dopo Pentecoste | SS. CORPO E SANGUE DI CRISTO            | Solennità dS (6) | `CorpusChristi`    | +60           | yes            |
| Venerdì dopo la II domenica dopo Pentecoste        | SACRATISSIMO CUORE DI GESÙ              | Solennità dS (6) | `SacredHeart`      | +68           | yes            |
| Sabato dopo la II domenica dopo Pentecoste         | Cuore Immacolato della b. Vergine Maria | Memoria (3)      | `ImmaculateHeart`  | +69           | no             |

**Grade mapping.** Confirmed by the Missal's own *Tabella dei giorni liturgici disposta secondo
l'ordine di precedenza* (pp. LXV–LXVI), which separates **rank 2** — Natale, Epifania, Ascensione,
Pentecoste, Sundays of Advent/Lent/Easter, *Sabato in traditione symboli*, octave days and the
Dedicazione del Duomo, i.e. exactly the keys the current data grades **7** — from **rank 3**,
"Solennità e feste **del Signore**, elencate nel Calendario comune ambrosiano", where these
celebrations sit alongside `ChristKing`, graded **6**. So `Solennità dS` → `LitGrade::SOLEMNITY` (6)
and `Memoria` → `LitGrade::MEMORIAL` (3). Note this differs from the Roman temporale, which grades
`Trinity` and `CorpusChristi` as 7; copying the Roman entries would be wrong. Rank 3 outranking
**rank 4** ("domeniche… dopo Pentecoste") is also what lets Trinity displace the I domenica.

**Colour.** White for all five. The *calendario* does not print colours, but the Roman temporale
entries for `Trinity`, `CorpusChristi`, `SacredHeart` and `ImmaculateHeart` are all `["white"]`, and
`MaryMotherChurch` likewise. Worth a spot-check against the Ambrosian Mass formularies.

**Keys.** All five exist in the Roman calendar under settled keys (`Trinity`, `CorpusChristi`,
`SacredHeart`, `ImmaculateHeart`, `MaryMotherChurch`). Reusing them keeps cross-rite comparison cheap
and matches how the Ambrosian file already reuses `Easter`, `Ascension`, `Pentecost`, `ChristKing` and
`BaptismLord` for shared celebrations.

## Design

### Data

Add the five entries to `propriumdetempore.json` as `type: "mobile"`, with names in the two shipped
locales (`i18n/it.json`, `i18n/la.json`). Italian names come from the *calendario* verbatim; Latin names
follow the existing convention in `la.json`.

### Engine

Place all five in `AmbrosianTemporale::calculateAfterPentecostAnchors()`, alongside the existing
`DedicationDuomo` anchor. **No new mechanism is required.** `buildTemporale()` already sequences:

1. `calculateAfterPentecostAnchors()` (line 37)
2. `calculateAfterPentecostSundays()` (line 40)
3. `calculateAfterPentecostWeekdays()` (line 41)

Both downstream passes already refuse to overwrite an occupied day:

- `numberSundayBlock()` skips a Sunday when `$ctx->cal->inCalendar($sunday)` is true, while still
  incrementing the ordinal.
- `fillFerialWeekdays()` guards each weekday with `false === $ctx->cal->inCalendar($day)` (line 396).

So anchoring first is sufficient for Trinity (a Sunday), for the two weekday solemnities and for the two
weekday memorials.

### Season classification

`AmbrosianTemporale::stampSeason()` classifies every event it creates via
`LitSeason::forEventKey()`, whose default branch returns `ORDINARY_TIME` and names `Trinity` and
`CorpusChristi` explicitly as examples. That is correct for the Roman rite and wrong for the
Ambrosian, where all five belong to the *tempo dopo Pentecoste*. None of the five match
`AFTER_PENTECOST_PATTERNS`, so without intervention all five would be stamped Ordinary Time.

`forEventKey()` is rite-agnostic and is also consumed by the Roman `/temporale` endpoint, so it must
not be changed. `stampSeason()` already local-overrides `ChristKing` for exactly this reason (the same
key means a different season in each rite); extend that override to cover the five new keys.

### Why the Sunday numbering does not shift

`numberSundayBlock()` increments its ordinal even when it skips an occupied Sunday. Adding `Trinity` on
the first Sunday after Pentecost therefore *consumes* the "I domenica dopo Pentecoste" slot, and the
following Sunday remains "II domenica dopo Pentecoste".

That is exactly what the Missal presupposes: it anchors the Sacred Heart to "la **II** domenica dopo
Pentecoste", which only makes sense if Trinity has occupied the first. The existing numbering is already
correct; this change adds the missing celebrations **without renumbering anything**. Blast radius is
limited to the five new days.

### Schema — optional year gating

`jsondata/schemas/PropriumDeTempore.json` currently supports only `event_key`, `grade`, `type`, `color`,
`is_dominical` and `is_aliturgical` (`additionalProperties: false`). Mary Mother of the Church was
instituted in 2018, so it must not appear in earlier years — and the API claims historical fidelity back
to the Ambrosian floor of 1976 (`CalendarParams::AMBROSIAN_YEAR_LOWER_LIMIT`).

Add optional `since_year` / `until_year` to the schema and honour them when materialising temporale
events. `MaryMotherChurch` carries `since_year: 2018`; the other four are ancient and carry neither.

This is preferred over hardcoding a year check in `AmbrosianTemporale` (the pattern used for the Roman
Eternal High Priest at `CalendarHandler.php:1859`) because the Ambrosian rollout's remaining work — the
`AmbrosianMissal::EDITIO_1976` backfill — needs exactly this gating mechanism. Building it here means
that work inherits it instead of retrofitting it, and avoids adding another hardcoded year to unwind.

The change is backward-compatible: both fields are optional and no existing entry uses them.

## Testing

### The annual table as a regression oracle

Transcribe the Missal's table (pp. LXXXVIII–LXXXIX, reproduced in the appendix) into a test fixture and
drive a data-driven test over all 32 years. The table is a *published* oracle and covers considerably
more than the five new celebrations: Easter, Ascension, Pentecost, Corpus Domini, Advent I, the number of
weeks after Epiphany, Lent I, both lectionary cycles, the after-Pentecost and after-Martyrdom Sunday
counts, and the Dedication of the Duomo.

The offsets encoded in this design were validated against all 32 rows before it was written, with zero
mismatches:

- Ascension = Easter + 39 (the 40th day) in every year
- Pentecost = Easter + 49 in every year
- Corpus Domini = Pentecost + 11 in every year, and a Thursday in every year

Because the Ambrosian temporale currently has no multi-year regression net, this fixture is worth
building even though most of what it asserts is already correct — it pins the existing behaviour while
the new celebrations are added.

Mark the full 32-year sweep `@group slow` if its runtime warrants it, following
`Routes/Readonly/TemporaleTest`.

### Targeted tests

- The five celebrations appear on the expected dates, with the expected grade, colour and `is_dominical`.
- `MaryMotherChurch` is absent for years before 2018 and present from 2018 onward.
- The after-Pentecost Sunday numbering is unchanged: the Sunday following Trinity is still
  `AfterPentecost2`, and the sub-block Sunday counts still match the table.
- The two weekday solemnities and two weekday memorials are not overwritten by the ferial fill.
- Schema: `PropriumDeTempore.json` accepts entries with and without `since_year`/`until_year`, and the
  existing Roman and Ambrosian files still validate.
- Golden-master fixtures for Ambrosian years will change by exactly these five days per year (plus the
  ferie they displace) — regenerate deliberately and review the diff.

## Out of scope

Tracked separately, not addressed here:

1. **`/data` rite-awareness** — design already agreed (rite path segment on `/data`, strict agreement
   between the segment and the payload's `metadata.rite`, full CRUD parity). To be specced next.
2. **Stale published artifacts** — `jsondata/schemas/openapi.json` documents the four
   `/calendar/ambrosian/diocese/{calendar_id}{,/{year}}` operations with `501` and **no `200`**, though
   they have returned 200 since PR #738; and the comments at `CalendarHandler.php:1161` and `:4318`
   still describe the 501 as in force.
3. **Rejecting the inert request parameters** — `epiphany`, `ascension`, `corpus_christi` and
   `eternal_high_priest` are advertised on the Ambrosian operations but cannot take effect
   (`AmbrosianTemporale.php:140` fixes Epiphany to 6 January; `:207` fixes Ascension to Easter + 39; the
   Eternal High Priest block is Roman-only). The praenotanda fix these in the rite's own books
   (nn. 22, 34), so they should be rejected rather than silently ignored. This becomes coherent only
   once Corpus Domini exists, i.e. after this work.
4. **Plan 8c — città di Milano tier** — the 47 basilica-specific dedication rows.
5. **`AmbrosianMissal::EDITIO_1976` backfill** — will consume the `since_year`/`until_year` mechanism
   added here.

## Appendix — Tabella annuale, 2025–2056

Transcribed from Premesse/Praenotanda pp. LXXXVIII–LXXXIX. `Dom`/`Fer` are the Sunday and ferial
lectionary cycles; `WkEpi` is the number of weeks after Epiphany; `SunPent`/`SunMart` are the
after-Pentecost and after-Martyrdom Sunday counts (each "+ ultima" in the source).

| Year | Dom | Fer | Advent I   | WkEpi | Lent I     | Easter     | Ascension  | Pentecost  | Corpus     | SunPent | SunMart | Dedication |
|------|-----|-----|------------|-------|------------|------------|------------|------------|------------|---------|---------|------------|
| 2025 | C   | I   | 2024-11-17 | 6     | 2025-03-09 | 2025-04-20 | 2025-05-29 | 2025-06-08 | 2025-06-19 | 10      | 7       | 2025-10-19 |
| 2026 | A   | II  | 2025-11-16 | 4     | 2026-02-22 | 2026-04-05 | 2026-05-14 | 2026-05-24 | 2026-06-04 | 12      | 7       | 2026-10-18 |
| 2027 | B   | I   | 2026-11-15 | 3     | 2027-02-14 | 2027-03-28 | 2027-05-06 | 2027-05-16 | 2027-05-27 | 14      | 6       | 2027-10-17 |
| 2028 | C   | II  | 2027-11-14 | 6     | 2028-03-05 | 2028-04-16 | 2028-05-25 | 2028-06-04 | 2028-06-15 | 11      | 6       | 2028-10-15 |
| 2029 | A   | I   | 2028-11-12 | 4     | 2029-02-18 | 2029-04-01 | 2029-05-10 | 2029-05-20 | 2029-05-31 | 13      | 7       | 2029-10-21 |
| 2030 | B   | II  | 2029-11-18 | 6     | 2030-03-10 | 2030-04-21 | 2030-05-30 | 2030-06-09 | 2030-06-20 | 10      | 7       | 2030-10-20 |
| 2031 | C   | I   | 2030-11-17 | 5     | 2031-03-02 | 2031-04-13 | 2031-05-22 | 2031-06-01 | 2031-06-12 | 11      | 7       | 2031-10-19 |
| 2032 | A   | II  | 2031-11-16 | 3     | 2032-02-15 | 2032-03-28 | 2032-05-06 | 2032-05-16 | 2032-05-27 | 14      | 6       | 2032-10-17 |
| 2033 | B   | I   | 2032-11-14 | 6     | 2033-03-06 | 2033-04-17 | 2033-05-26 | 2033-06-05 | 2033-06-16 | 11      | 6       | 2033-10-16 |
| 2034 | C   | II  | 2033-11-13 | 5     | 2034-02-26 | 2034-04-09 | 2034-05-18 | 2034-05-28 | 2034-06-08 | 12      | 6       | 2034-10-15 |
| 2035 | A   | I   | 2034-11-12 | 3     | 2035-02-11 | 2035-03-25 | 2035-05-03 | 2035-05-13 | 2035-05-24 | 14      | 7       | 2035-10-21 |
| 2036 | B   | II  | 2035-11-18 | 5     | 2036-03-02 | 2036-04-13 | 2036-05-22 | 2036-06-01 | 2036-06-12 | 11      | 7       | 2036-10-19 |
| 2037 | C   | I   | 2036-11-16 | 4     | 2037-02-22 | 2037-04-05 | 2037-05-14 | 2037-05-24 | 2037-06-04 | 12      | 7       | 2037-10-18 |
| 2038 | A   | II  | 2037-11-15 | 7     | 2038-03-14 | 2038-04-25 | 2038-06-03 | 2038-06-13 | 2038-06-24 | 10      | 6       | 2038-10-17 |
| 2039 | B   | I   | 2038-11-14 | 5     | 2039-02-27 | 2039-04-10 | 2039-05-19 | 2039-05-29 | 2039-06-09 | 12      | 6       | 2039-10-16 |
| 2040 | C   | II  | 2039-11-13 | 4     | 2040-02-19 | 2040-04-01 | 2040-05-10 | 2040-05-20 | 2040-05-31 | 13      | 7       | 2040-10-21 |
| 2041 | A   | I   | 2040-11-18 | 6     | 2041-03-10 | 2041-04-21 | 2041-05-30 | 2041-06-09 | 2041-06-20 | 10      | 7       | 2041-10-20 |
| 2042 | B   | II  | 2041-11-17 | 4     | 2042-02-23 | 2042-04-06 | 2042-05-15 | 2042-05-25 | 2042-06-05 | 12      | 7       | 2042-10-19 |
| 2043 | C   | I   | 2042-11-16 | 3     | 2043-02-15 | 2043-03-29 | 2043-05-07 | 2043-05-17 | 2043-05-28 | 13      | 7       | 2043-10-18 |
| 2044 | A   | II  | 2043-11-15 | 6     | 2044-03-06 | 2044-04-17 | 2044-05-26 | 2044-06-05 | 2044-06-16 | 11      | 6       | 2044-10-16 |
| 2045 | B   | I   | 2044-11-13 | 5     | 2045-02-26 | 2045-04-09 | 2045-05-18 | 2045-05-28 | 2045-06-08 | 12      | 6       | 2045-10-15 |
| 2046 | C   | II  | 2045-11-12 | 3     | 2046-02-11 | 2046-03-25 | 2046-05-03 | 2046-05-13 | 2046-05-24 | 14      | 7       | 2046-10-21 |
| 2047 | A   | I   | 2046-11-18 | 5     | 2047-03-03 | 2047-04-14 | 2047-05-23 | 2047-06-02 | 2047-06-13 | 11      | 7       | 2047-10-20 |
| 2048 | B   | II  | 2047-11-17 | 4     | 2048-02-23 | 2048-04-05 | 2048-05-14 | 2048-05-24 | 2048-06-04 | 12      | 7       | 2048-10-18 |
| 2049 | C   | I   | 2048-11-15 | 6     | 2049-03-07 | 2049-04-18 | 2049-05-27 | 2049-06-06 | 2049-06-17 | 11      | 6       | 2049-10-17 |
| 2050 | A   | II  | 2049-11-14 | 5     | 2050-02-27 | 2050-04-10 | 2050-05-19 | 2050-05-29 | 2050-06-09 | 12      | 6       | 2050-10-16 |
| 2051 | B   | I   | 2050-11-13 | 4     | 2051-02-19 | 2051-04-02 | 2051-05-11 | 2051-05-21 | 2051-06-01 | 13      | 6       | 2051-10-15 |
| 2052 | C   | II  | 2051-11-12 | 7     | 2052-03-10 | 2052-04-21 | 2052-05-30 | 2052-06-09 | 2052-06-20 | 10      | 7       | 2052-10-20 |
| 2053 | A   | I   | 2052-11-17 | 4     | 2053-02-23 | 2053-04-06 | 2053-05-15 | 2053-05-25 | 2053-06-05 | 12      | 7       | 2053-10-19 |
| 2054 | B   | II  | 2053-11-16 | 3     | 2054-02-15 | 2054-03-29 | 2054-05-07 | 2054-05-17 | 2054-05-28 | 13      | 7       | 2054-10-18 |
| 2055 | C   | I   | 2054-11-15 | 6     | 2055-03-07 | 2055-04-18 | 2055-05-27 | 2055-06-06 | 2055-06-17 | 11      | 6       | 2055-10-17 |
| 2056 | A   | II  | 2055-11-14 | 4     | 2056-02-20 | 2056-04-02 | 2056-05-11 | 2056-05-21 | 2056-06-01 | 13      | 6       | 2056-10-15 |
