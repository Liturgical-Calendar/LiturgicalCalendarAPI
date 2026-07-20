# Ambrosian Rite Integration — Design

**Date:** 2026-07-20
**Status:** Design — approved in brainstorming, pending written-spec review
**Scope:** Architectural foundation + full Ambrosian data corpus for the dioceses of
Milano, Bergamo, Novara (nation IT) and Lugano (nation CH).

## 1. Problem & goal

The Liturgical Calendar API currently supports **only the Roman Rite**. The temporal
cycle is hardcoded to Roman rules inside `CalendarHandler` (~5,400 lines): Advent is
computed as four Sundays counted back from Christmas, Ash Wednesday as `Easter − 46`,
Ordinary Time as numbered weeks, and the coincidence/transfer logic assumes the Roman
precedence table. National and diocesan calendars are pure **event-level overlays**
(`createNew` / `setProperty` / `moveEvent` / `makePatron` / `makeDoctor`) layered on top
of that single fixed temporale — they cannot redefine seasons, the precedence table, or
the temporal computation.

The **Ambrosian Rite** (Rite of the Church of Milan) is not a calendar overlay: it is a
different rite with its own temporal cycle, precedence table, grade taxonomy, colour
vocabulary, and missal line. It therefore overflows every extension point the API
offers today.

**Goal:** integrate the Ambrosian Rite as a first-class rite alongside Roman, restricted
to the four dioceses that have Ambrosian-rite parishes, while keeping the Roman calendar
byte-for-byte unchanged. Both rites must coexist in those dioceses (each contains both
Roman and Ambrosian parishes), so **rite is an explicit request dimension**, never
inferred from the diocese.

### Scope decisions (from brainstorming)

- **This spec covers:** the rite abstraction, the `AmbrosianTemporale` engine, the
  rite-aware precedence/season/grade/colour model, rite-governed missal resolution, the
  full Ambrosian source-data corpus (comune ambrosiano + the four diocesan propers), and
  the schemas/validation for all of it.
- **Historical fidelity:** full, matching the Roman engine. The reformed Ambrosian Missal
  begins with the **1976** 1st edition, so the rite's effective floor is **1976** (the
  Roman 1970 floor is unaffected). The **2008** calendar/lectionary reform is a structural
  boundary handled inside the engine and via year-gating (§6), not a separate missal.
- **Rite selection:** an **optional leading path segment**, absence = Roman, so every
  existing URL is unchanged.
- **Data sourcing:** hand-authored from the *Messale Ambrosiano* (II ed. 2024) and the
  earlier editions, into the existing JSON source-data format.
- **Out of scope:** liturgical hours / Vespers emission (the calendar is day-granular,
  as with the Roman API); non-calendar endpoints beyond the metadata advertisement.

## 2. Architecture spine

A `Rite` selects a `RiteProfile`; the profile supplies the rite-specific strategies while
`CalendarHandler` becomes a rite-agnostic orchestrator.

### New abstractions

- **`Rite` enum** (`src/Enum/Rite.php`): `ROMAN` (default), `AMBROSIAN`.
- **`RiteProfile`** — one per rite, bundling the four things that actually differ between
  rites:
  - a **`TemporaleEngine`**,
  - a **`PrecedenceResolver`**,
  - a **`MissalResolver`**,
  - the **vocabularies** (valid seasons, grade labels, colours) and **supported locales**.
- **`TemporaleEngine` interface** — `buildTemporale(CalendarParams, resolvedMissals): LiturgicalEventCollection`.
  Produces the temporal-cycle events (dates, seasons, grades) for the year, drawing
  event keys/grades/colours from the resolved *Proprium de Tempore*. `RomanTemporale` is
  the extracted current logic; `AmbrosianTemporale` is new (§4).
- **`PrecedenceResolver` interface** — resolves coincidences/transfers per the rite's
  precedence table (§5). The Roman transfer logic currently inlined in `CalendarHandler`
  extracts to `RomanPrecedenceResolver`.
- **`MissalResolver` interface** — given `(rite, nation/diocese, year)`, returns the
  ordered set of missal editions that supply the base *Proprium de Sanctis* + *de Tempore*
  (§3).

### `CalendarHandler` orchestration (after refactor)

1. resolve `Rite` (from the path) → `RiteProfile`;
2. `profile.missalResolver.resolve(nation/diocese, year)` → year-gated edition set;
3. `profile.temporaleEngine.buildTemporale(params, resolvedMissals)` → temporal events;
4. load base *Proprium de Sanctis* from the resolved missals **+ apply national/diocesan
   overlays** (existing mechanism, now rite-scoped);
5. `profile.precedenceResolver.resolve(...)`;
6. hand off to the existing `Negotiator` for JSON / YAML / XML / ICS.

**Steps 4 (overlay application) and 6 (negotiation) are shared and unchanged across
rites** — that is the payoff of the strategy-interface approach. Steps 2, 3, 5 are the
rite-specific strategies.

### Approach chosen

Strategy interface (extract Roman logic behind `TemporaleEngine` / `PrecedenceResolver` /
`MissalResolver`, add Ambrosian siblings). Rejected alternatives:

- **Parallel `AmbrosianCalendarHandler`** (duplicate the pipeline): zero risk to Roman but
  permanently doubles ~5,000 lines of orchestration/precedence/negotiation — punished by
  the full-history + full-data scope.
- **Config-driven temporale** (seasons as declarative JSON interpreted by one engine):
  elegant for future rites, but the Ambrosian irregularities (aliturgical Fridays,
  *sabato in traditione symboli*, edition-dependent restructuring, the nn. 4/32/56
  transfers) would turn the config into a mini-language. Premature for a two-rite reality.

## 3. Rite routing, validation, inheritance & missal resolution

### Routing

`rite` is an **optional leading path segment**; absence = Roman.

- `/calendar/diocese/romamo_it` → Roman (unchanged)
- `/calendar/ambrosian` → comune ambrosiano base (exposed as a first-class calendar)
- `/calendar/ambrosian/2008` → comune ambrosiano, year 2008
- `/calendar/ambrosian/diocese/milano_it/2008` → Milano overlay, year 2008

`Router` parses the optional segment into a `Rite`, defaulting to `ROMAN`, and passes it
into the params.

### Inheritance chain differs by rite

- **Roman:** `diocese → nation → wider region → General Roman Calendar`.
- **Ambrosian:** `diocesan overlay → comune ambrosiano base`. There is **no national
  layer** (the rite spans Italy *and* Switzerland). The *comune ambrosiano* plays the role
  the General Roman Calendar plays for Roman — it is the rite base the four diocesan
  overlays extend.

### Whitelist — data-driven, not hardcoded

Each diocesan calendar's metadata declares `supported_rites`. Only **Milano, Bergamo,
Novara** (nation IT) and **Lugano** (nation CH) declare `ambrosian` (alongside `roman`,
since Roman parishes exist there too); everything else is Roman-only. The Ambrosian
`RiteProfile` derives its valid-calendar set from these declarations. The params-validation
layer (`src/Params/`) enforces rite↔calendar compatibility and returns **400 Bad Request**
with a clear message on mismatch:

- `/calendar/ambrosian/nation/US` → **fail** (Ambrosian has no national calendars)
- `/calendar/ambrosian/diocese/romamo_it` → **fail** (Rome doesn't declare `ambrosian`)
- `/calendar/ambrosian/diocese/milano_it` → **valid**
- `/calendar/ambrosian` → **valid** (comune ambrosiano base)

### Discovery

`MetadataHandler` (`/calendars`) advertises `supported_rites` per calendar so clients know
which dioceses offer the Ambrosian rite.

### Missal resolution is rite-governed

`MissalResolver` returns the edition set that supplies the base *Proprium de Sanctis* +
*de Tempore*:

- **Roman:** Editio Typica (1970 / 2002 / 2008) + applicable national editions
  (e.g. US 2011, IT 1983), year-gated.
- **Ambrosian:** the Ambrosian *editio typica* line only — **never** the Roman missals.

A new **`AmbrosianMissal` enum** (`src/Enum/AmbrosianMissal.php`), sibling to `RomanMissal`,
enumerates the editions: **`EDITIO_1976`, `EDITIO_2024`**. (The 2008 reform is handled by
year-gating + engine branch, not as a third edition — §6.)

## 4. The `AmbrosianTemporale` engine

`AmbrosianTemporale implements TemporaleEngine`. It computes the temporal cycle from
anchors, drawing event keys/grades/colours from the resolved Ambrosian *Proprium de
Tempore* (per edition), exactly as `RomanTemporale` does from `propriumdetempore.json`.

**Shared:** Gregorian Easter (`Utilities::calcGregEaster`). Everything below is
rite-specific.

**Anchors:** Easter; Christmas (Dec 25); Epiphany (fixed **Jan 6**); **St Martin (Nov 11)**
→ Advent I; Dedication of the Duomo di Milano (**3rd Sunday of October**); Martyrdom of
St John the Baptist (**Aug 29**).

**Season set** (new rite-scoped `LitSeason` values, §6): `ADVENT` (6 weeks), `CHRISTMAS`,
`AFTER_EPIPHANY`, `LENT`, `EASTER_TRIDUUM`, `EASTER`, `AFTER_PENTECOST` (carrying which of
three sub-blocks a week belongs to). **No `ORDINARY_TIME`.**

### Computation sequence & the rules that break the Roman engine

Each season below lists its **rule (Missal norm)** and then the **contrast with Roman**:

- **Advent** — 6 Sundays; Advent I = Sunday after Nov 11; VI = "dell'Incarnazione / Divina
  Maternità" (Sunday before Christmas). Ferie *de Exceptáto* Dec 17(18)–23 (nn. 37–39).
  *Roman:* 4 Sundays counted back from Christmas.
- **Christmas** — octave ends **Jan 1 (Circoncisione)**; rite-specific vigil-shift when
  Dec 26/27/28 fall on Sunday (nn. 31–33). *Roman:* octave → Mary Mother of God.
- **After Epiphany** — Monday after Baptism (Sunday after Jan 6) → Saturday before Lent;
  last Sunday of January = Holy Family (nn. 40–41). *Roman:* no analogue block.
- **Lent** — **no Ash Wednesday**; begins on a Sunday (6th before Easter), ashes the
  following Monday. Named Sundays (Samaritana / Abramo / Cieco / Lazzaro); **aliturgical
  Fridays** (no Mass); **Sabato in traditione symboli** before Palm Sunday (nn. 24–27).
  *Roman:* Ash Wednesday = Easter − 46; no aliturgical days.
- **Triduum / Easter** — Triduum from Mass *inter vesperas in Cena Domini*; Easter octave
  → *in albis*; Ascension = Easter + 39d, Pentecost = Easter + 49d (nn. 15–22). *Roman:*
  structurally close; naming/boundaries differ.
- **After Pentecost** — Monday after Pentecost → Saturday before Advent I, split into
  **dopo Pentecoste / dopo il Martirio (anchored Aug 29) / dopo la Dedicazione (anchored
  3rd Sun Oct)**; Christ the King = last Sunday after the Dedication (nn. 42, 5e). *Roman:*
  numbered Ordinary Time; Christ the King = last week of OT.

### Edition-awareness

The engine takes the resolved edition/year. The **2008 reform** introduced the
post-Epiphany / post-Pentecost restructuring; **pre-2008 (1976 ed.)** used a single
~32-Sunday post-Pentecost block. `AmbrosianTemporale` **branches its post-Epiphany /
post-Pentecost logic at year 2008**, mirroring how the Roman engine already varies
behaviour by year/edition.

### Deferred to ordo-validation (§7), enumerated here but not trusted until pinned

- exact 6-Sunday Advent counting when Nov 11 is itself a Sunday;
- the Dec 26–28 Sunday vigil shifts (n. 32);
- the St-Joseph / Annunciation-in-Holy-Week transfers (n. 4).

## 5. Precedence & transfer resolution

`AmbrosianPrecedenceResolver implements PrecedenceResolver`, encoding the Missal's
*Tabella dei giorni liturgici* (nn. 55–57). The Roman logic extracts to
`RomanPrecedenceResolver`.

### Table-as-data, not hardcoded branches

The 13-rank Ambrosian table is expressed as a **declarative ordered ranking** the resolver
reads — keyed by `(grade, is_dominical, season, proper-vs-comune category)` — so it stays
inspectable against the norms and the resolver stays generic:

The 13 ranks fall into three tiers (condensed from the *Tabella*):

- **Tier I** — 1 Easter Triduum · 2 Christmas/Epiphany/Ascension/Pentecost, **Sundays of
  Advent/Lent/Easter**, *Sabato in traditione symboli*, *settimana autentica* Mon–Thu,
  Easter & Christmas octave days, **Dedication of the Duomo (3rd Sun Oct)** · 3 solennità /
  **feste del Signore**, All Souls, own-church dedication · 4 **Sundays of after-Epiphany /
  after-Pentecost** · 5 solennità BVM & saints (comune) · 6 proper solennità (patron /
  title / founder).
- **Tier II** — 7 **ferie of Lent** (yield only to Annunciation & St Joseph) · 8 feste BVM
  & saints (comune), ferie *de Exceptáto* · 9 proper feste.
- **Tier III** — 10 obligatory memories (comune) · 11 proper obligatory memories ·
  12 optional memories · 13 ferie / Saturdays of Advent, Christmas, Easter, after-Epiphany
  & after-Pentecost.

The **`is_dominical` flag** (§6) is what lets rank 3 (*of the Lord*) outrank an ordinary
Sunday (rank 4) while a saint's solemnity (rank 5) does not — the exact asymmetry the
Roman resolver cannot express.

### Rite-specific transfer rules (nn. 4, 56)

- Solemnity of **the Lord** on an Advent/Lent/Easter Sunday → **Monday**; a **feast of the
  Lord** so impeded → **omitted that year**.
- Solemnity of a **saint** on a Sunday → **Monday**; if Monday is itself a solemnity →
  **anticipated to Saturday**.
- **Annunciation / St Joseph** falling in *Sabato in traditione symboli* or *settimana
  autentica* → **Monday / Tuesday after the Easter octave** respectively (both → Mon & Tue).
  During Lent, ferie cede only to these two; if one lands on an aliturgical Friday, the
  no-Mass rule is suspended.
- Generic (n. 56): a solemnity impeded by any higher day → first day free of ranks 1–10;
  other impeded celebrations that year → omitted.

### Shared infrastructure, unchanged

The resolver populates the **same** response structures the Roman path already uses —
`moved_from` / `moved_to` metadata, suppressed/transferred markers, and the `messages[]`
explanations — so output negotiation and the response schema stay rite-agnostic.

### Out of scope (consistent with the Roman API)

The Vespers-coincidence rule (n. 57) and first/second-Vespers vigil shifts — the calendar
works at **day granularity** and does not emit liturgical hours.

### Deferred to ordo-validation (§7)

The saint-solemnity Sunday → Monday → Saturday cascade and the Annunciation / St-Joseph
Holy-Week transfers are enumerated here but their date arithmetic is pinned against a
published ordo before being trusted.

## 6. Data model, schemas & vocabularies

### Vocabularies (owned/declared per `RiteProfile`)

- **Seasons** — add `AFTER_EPIPHANY` and `AFTER_PENTECOST` to `LitSeason`; the latter
  carries which of the three sub-blocks (*dopo Pentecoste / dopo il Martirio / dopo la
  Dedicazione*) a week belongs to. The profile declares its valid season set;
  `ORDINARY_TIME` is simply not in the Ambrosian set.
- **Grades** — **do not fork** `LitGrade`'s numeric ladder (it is the precedence key). The
  Ambrosian "of the Lord" distinction becomes an **event-level `is_dominical` flag**; the
  profile supplies the rite's five grade **labels** over the shared numeric ladder.
- **Colours** — add `morello` and ensure `black` exist in `LitColor`; the profile supplies
  rite colour labels/usage. Existing white / red / green / rose reused.
- **Aliturgical days** — represented as a temporal event carrying `is_aliturgical: true`
  (Lenten Fridays), surfaced as an optional boolean on the litcal item so clients can render
  "no Mass celebrated." Additive to the response schema.

### Source-data layout (mirrors the existing Roman structure)

```text
jsondata/sourcedata/
  missals/ambrosian/
    propriumdetempore/propriumdetempore.json   + i18n/{it,la}   # Ambrosian temporal keys
    propriumdesanctis_1976/propriumdesanctis.json + i18n/…       # comune ambrosiano, 1st ed.
    propriumdesanctis_2024/propriumdesanctis.json + i18n/…       # comune ambrosiano, 2nd ed.
  calendars/dioceses/
    IT/{milano_it, bergam_it, novara_it}/…                       # diocesan Ambrosian propers
    CH/lugano_ch/…
```

Diocese IDs follow the existing convention (six-ish char stem + `_nation`); exact stems
confirmed during data authoring.

### The comune ambrosiano base = the Ambrosian missals' proprium

Exactly as the General Roman Calendar *is* the Roman missals' proprium (not a `calendars/`
file). So there is **no Ambrosian nation file**; `/calendar/ambrosian` renders straight from
the missal proprium (de tempore + de sanctis).

### Transcription split (important for hand-authoring)

The PDF calendar interleaves rite-wide saints with location notes
("*A Milano, nella basilica di S. Ambrogio: S. Savina*"). Rite-wide entries → comune
sanctorale; location/church-specific entries → the relevant **diocesan overlay** (existing
`createNew` / `setProperty` / … actions). Milano's city-vs-arcidiocesi propers and Lugano's
proper (B. Manfredo Settala) live in their diocesan files.

### Editions & historical gating

- **`AmbrosianMissal` = `EDITIO_1976`, `EDITIO_2024`** (the two actual missal editions).
- The **2008 calendar/lectionary reform** is a structural boundary handled two ways: the
  `AmbrosianTemporale` engine **branches its season logic at year 2008** (§4), and
  individual sanctorale events carry **`since_year` / `until_year`** (the historical-fidelity
  mechanism the Roman calendars already use). **Effective floor for the rite = 1976.**

### Locales

The Ambrosian profile declares supported locales **`it_IT` and `la`** (Latin *editio typica
altera*), handled by the existing Latin-aware `Negotiator`.

### Schemas (`jsondata/schemas/`, Health-wired validation)

Reuse `PropriumDeSanctis.json` / `PropriumDeTempore.json` where the shapes match,
parameterised for the rite's season/grade/colour enums; diocesan overlays reuse
`DiocesanCalendar.json` extended with `supported_rites` + a `rite` field. Add a schema (or
enum extension) for the Ambrosian temporal keys.

## 7. Testing & validation strategy

Mapped to the repo's test layering (`ApiTestCase` / `AbstractHandlerTestCase` / pure-logic
`TestCase`), test-first for the engine/resolver.

1. **Golden-master lock — before touching Roman code (the linchpin).** Capture the current
   Roman output as fixtures across a representative span of years plus a few
   national/diocesan calendars. The extraction of `RomanTemporale` / `RomanPrecedenceResolver`
   out of `CalendarHandler` must produce a **byte-identical** diff against those fixtures.
   The full existing Roman suite stays green throughout as the regression guard.
2. **Pure-logic unit tests per strategy** (no I/O, written from the norms first):
   - `AmbrosianTemporale` — anchor dates & season boundaries for specific years: Advent I
     (incl. Nov-11-on-Sunday edge case), Lent start (no Ash Wednesday) + ashes Monday,
     Baptism, aliturgical Fridays, Dedication of the Duomo, Christ the King, Aug 29
     Martyrdom handling. Edition-branch tests: pre-2008 vs 2008+ post-Pentecost structure.
   - `AmbrosianPrecedenceResolver` — one test per transfer rule with constructed
     coincidences: saint-solemnity Sunday→Monday, →Saturday when Monday blocked,
     Annunciation/St Joseph in Holy Week, of-the-Lord vs ordinary-Sunday asymmetry.
   - `MissalResolver` — year-gated edition sets; Roman missals **never** resolved for
     Ambrosian and vice-versa.
   - `Rite` routing/validation (`Params`): `/calendar/ambrosian/nation/US` → 400,
     `.../diocese/romamo_it` → 400, `.../diocese/milano_it` → 200, bare
     `/calendar/ambrosian` → 200, no segment → Roman.
3. **Handler tests** (`AbstractHandlerTestCase`, in-process): `/calendar/ambrosian[...]`
   response shape, expected seasons present, `supported_rites` in `/calendars` metadata.
4. **Ordo validation — the acceptance gate.** Generated Ambrosian calendars validated
   against a **published authoritative Ambrosian ordo** (chiesadimilano.it / diocesan
   liturgical calendar) for a span of years — recent plus a couple historical (post-1976,
   post-2008). This is where every "deferred to ordo-validation" edge case from §4 and §5
   gets pinned. Where no machine-readable ordo exists, spot-check specific known dates.
   Multi-year iterations tagged `@group slow`.
5. **Schema validation** — new/extended schemas Health-wired; Ambrosian source data added
   to the `SchemaValidationTest` corpus (`@group slow`).
6. **Integration/route tests** (`ApiTestCase`): a few end-to-end `/calendar/ambrosian/...`
   requests across JSON / YAML / XML / ICS to confirm the shared pipeline serves the new
   rite.

## 8. Files touched (indicative, not exhaustive)

**New:**

- `src/Enum/Rite.php`, `src/Enum/AmbrosianMissal.php`
- `src/Models/Calendar/Rite/RiteProfile.php` (+ `RomanRiteProfile`, `AmbrosianRiteProfile`)
- `src/.../Temporale/{TemporaleEngine.php, RomanTemporale.php, AmbrosianTemporale.php}`
- `src/.../Precedence/{PrecedenceResolver.php, RomanPrecedenceResolver.php, AmbrosianPrecedenceResolver.php}`
- `src/.../Missal/{MissalResolver.php, RomanMissalResolver.php, AmbrosianMissalResolver.php}`
- `jsondata/sourcedata/missals/ambrosian/**`
- `jsondata/sourcedata/calendars/dioceses/{IT/milano_it, IT/bergam_it, IT/novara_it, CH/lugano_ch}/**`
- new/extended schemas under `jsondata/schemas/`
- phpunit tests mirroring the above

**Modified:**

- `src/Handlers/CalendarHandler.php` — reduced to orchestration; Roman temporal/precedence
  logic extracted out
- `src/Router.php` — parse the optional leading rite segment
- `src/Params/**` — rite parsing + rite↔calendar validation
- `src/Enum/{LitSeason.php, LitColor.php}` — additive values
- `src/Handlers/MetadataHandler.php` — advertise `supported_rites`
- `src/Handlers/EventsHandler.php` — rite-aware event catalogue (follows temporale changes)
- response schema — additive `is_dominical` / `is_aliturgical` / season values

## 9. Risks & open questions

- **Extraction risk.** Refactoring the ~5,400-line `CalendarHandler` behind interfaces is
  the highest-risk change. Mitigated by the golden-master lock (§7.1) and the existing
  Roman suite.
- **Ordo availability.** The acceptance gate depends on a published Ambrosian ordo to
  validate against; if no machine-readable source exists, validation falls back to
  date-by-date spot checks, which is slower and less exhaustive.
- **Data volume & correctness.** Hand-authoring the full comune ambrosiano sanctorale plus
  four diocesan propers across two editions is substantial and error-prone; proofing against
  the printed Missal calendar is required.
- **1970–1975 requests.** Below the 1976 floor the Ambrosian rite has no reformed calendar.
  Decision to confirm during data authoring: hard error vs. clamp/fallback. Recommended:
  explicit error, consistent with historical fidelity.
- **`EventsHandler` scope.** The `/events` catalogue is rite-sensitive (it lists a rite's
  possible events). Wiring it for Ambrosian is in scope structurally but its data depth
  follows the sanctorale authoring.
