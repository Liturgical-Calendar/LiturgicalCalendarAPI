# Ambrosian Precedence Resolver Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for
> tracking.

**Goal:** Implement `AmbrosianPrecedenceResolver` — a data-driven resolver of the Ambrosian *Tabella dei
giorni liturgici* (13-rank precedence + transfer/suppression rules) — as a pure, unit-tested
`PrecedenceResolver`, without touching the Roman precedence logic and while the `/calendar/ambrosian` endpoint
continues to return `501`.

**Architecture:** A new `PrecedenceResolver` interface (`resolve(PrecedenceContext $ctx): void`) mirrors the
`TemporaleEngine`/`TemporaleContext` seam from Plans 1/3. `AmbrosianPrecedenceResolver` reads an **ordered,
inspectable 13-rank table** (predicate → rank) to classify each event, groups events by date, keeps the
highest-ranked celebration on each contested day, and suppresses or transfers the losers per the Missal's
transfer rules — recording every move/suppression through the existing `LiturgicalEventCollection` API and
`messages[]`. The resolver is exercised **only** by unit tests against constructed coincidences; it is not
wired into `CalendarHandler` (the endpoint 501s before any calculation). The Roman precedence logic stays
inline in `CalendarHandler` — a byte-identical extraction of it was assessed as the highest-risk, lowest-value
change in the rollout and is explicitly out of scope (see Global Constraints / Background).

**Tech Stack:** PHP 8.4+, PHPUnit (pure-logic `PHPUnit\Framework\TestCase`), PHPStan level 10, phpcs, gettext `_()`.

## Background — why Ambrosian-only, Roman precedence NOT extracted

A code study of `CalendarHandler` (`.superpowers/sdd/precedence-map.md`) established that Roman precedence is
**not a liftable module**: it is enforced by *procedural pipeline order* across ~15 interleaved
"occupancy-check-then-create" sites (`calculateFixedSolemnities`, `calculateFeastsOfTheLord`, five
near-identical weekday methods, memorials, decrees), plus a second parallel vigil sub-system, plus a
more-entangled national/diocesan layer. There is no precedence-free substring to extract (unlike the temporale
block). A byte-identical extraction would be a huge, golden-master-sensitive diff that mostly re-encodes
existing ordering for little value, and it is **not needed** for a correct Ambrosian calendar. Decision
(approved): build only the Ambrosian resolver now; leave Roman precedence where it is. `spec §5`'s "the Roman
logic extracts to `RomanPrecedenceResolver`" is therefore **superseded** for this plan.

## Global Constraints

- **Endpoint stays 501.** Do not wire `AmbrosianPrecedenceResolver` into `CalendarHandler`'s request path; do
  not relax the `ImplementationException` (501) for `Rite::AMBROSIAN`. The resolver is validated by unit tests
  only.
- **Roman output byte-identical.** No Roman precedence code is touched. The golden-master gate
  (`phpunit_tests/Handlers/CalendarGoldenMasterTest.php`) must stay green after every task; all model/enum
  changes are strictly additive.
- **Roman precedence is NOT extracted in this plan.** `RomanRiteProfile::precedenceResolver()` throws a deferred
  `\LogicException` (never reached — Roman uses the inline handler logic, Ambrosian is 501). This mirrors how
  `AmbrosianRiteProfile::temporaleEngine()` threw before Plan 3.
- **Output through existing APIs only.** Record transfers via
  `LiturgicalEventCollection::moveLiturgicalEventDate()`, suppressions via `removeLiturgicalEvent()` +
  `addSuppressedEvent()`, and explanations via the `messages[]` sink on `PrecedenceContext`. Do **not** add
  `moved_from`/`moved_to` response-schema fields (Roman does not have them; keep output rite-agnostic).
- **Additive vocabulary this plan needs** (now exercised by rank classification — no longer YAGNI):
  `is_dominical` (nullable bool on `LiturgicalEvent`) and `LitSeason::AFTER_EPIPHANY` + `AFTER_PENTECOST`.
  Nothing else.
- **Proper-vs-comune is an event attribute** the rank function reads (`isProper`, default `false` = comune
  ambrosiano). Real proper/diocesan events arrive with Plan 5; Plan 4 exercises the proper-rank branches with
  constructed fixtures.
- **The `LitGrade` numeric ladder is NOT forked** (`HIGHER_SOLEMNITY=7, SOLEMNITY=6, FEAST_LORD=5, FEAST=4,
  MEMORIAL=3, MEMORIAL_OPT=2, COMMEMORATION=1, WEEKDAY=0`). Ambrosian rank is a function of `(grade,
  is_dominical, season, isProper, special-key membership)`, not grade alone.
- **Vespers coincidence (norm 57) is out of scope** — the calendar is day-granular (consistent with the Roman API).
- **Deferred to ordo-validation / Plan 5–6** (do NOT implement here): populating
  `is_dominical`/`isProper`/season on the *real* comune + diocesan sanctorale events; wiring the resolver into
  an Ambrosian pipeline; the Dec 26–28 octave-Sunday interactions (norm 32). Where a transfer's exact target
  under a rare double-impediment is uncertain, follow the norm literally and flag it for ordo-proofing.

---

## The 13-rank *Tabella dei giorni liturgici* (authoritative, from the Missal)

Lower number = higher precedence. Encoded in Task 4 as an ordered predicate list (first match wins).

```text
TIER I
 1. Easter Triduum (Passion & Resurrection).
 2. Christmas, Epiphany, Ascension, Pentecost · Sundays of Advent/Lent/Easter · Sabato in traditione symboli
    · ferie of the settimana autentica (Mon–Thu of Holy Week) · days of the octave of Easter and of Christmas
    · Dedication of the Duomo di Milano (3rd Sunday of October).
 3. Solemnities AND feasts of the Lord in the comune ambrosiano · All Souls · dedication (or its anniversary)
    of one's own church (= solemnity of the Lord) · anniversary-of-dedication feast of one's own cathedral
    outside Milan (= feast of the Lord).
 4. Sunday after the Christmas octave · Sundays of the times after Epiphany and after Pentecost.
 5. Solemnities of the BVM and of saints in the comune ambrosiano.
 6. Proper solemnities (principal patron of place/city; title of one's church; title/founder/patron of order).

TIER II
 7. Ferie of Lent (only the solemnities of the Annunciation and St Joseph precede them).
 8. Feasts of the BVM and saints of the comune · ferie de Exceptáto · commemoration of the annunciation to St Joseph.
 9. Proper feasts.

TIER III
10. Obligatory memorials of the comune ambrosiano.
11. Proper obligatory memorials.
12. Optional memorials.
13. Ferie and Saturdays of Advent (to Dec 15) / Christmas / Easter / after-Epiphany / after-Pentecost.
```

**Transfer & suppression rules** (norms 56 + spec §5):

- **General (n.56):** on a contested day the higher-ranked celebration is kept. A **solemnity** impeded by a
  higher day transfers to the **first day free of ranks 1–10**; the year's other impeded celebrations are
  **omitted**.
- **Solemnity of the Lord** on an Advent/Lent/Easter **Sunday** → **Monday**. A **feast of the Lord** so impeded → **omitted** that year.
- **Solemnity of a saint** on a Sunday → **Monday**; if that Monday is itself a solemnity → **anticipated to the Saturday**.
- **Annunciation / St Joseph** falling in the *Sabato in traditione symboli* or the *settimana autentica* →
  transferred to the **Monday / Tuesday after the Easter octave** respectively.
- During Lent, **ferie cede only** to the Annunciation and St Joseph.

---

## File Structure

**New source:**

- `src/Models/Calendar/Precedence/PrecedenceResolver.php` — interface (`resolve(PrecedenceContext $ctx): void`).
- `src/Models/Calendar/Precedence/PrecedenceContext.php` — carries `cal`, `params`, `localeDateFormatter`, `messages` (by-ref), mirroring `TemporaleContext`.
- `src/Models/Calendar/Precedence/AmbrosianLiturgicalDayRank.php` — the ordered 13-rank table + `rankOf(LiturgicalEvent): int` classifier.
- `src/Models/Calendar/Precedence/AmbrosianPrecedenceResolver.php` — `implements PrecedenceResolver`; the coincidence/transfer/suppression algorithm.

**Modified source:**

- `src/Models/Calendar/LiturgicalEvent.php` — add `?bool $is_dominical = null` (+ jsonSerialize, + `fromObject` passthrough).
- `src/Enum/LitSeason.php` — add `AFTER_EPIPHANY`, `AFTER_PENTECOST` (+ `forEventKey` patterns for the new Ambrosian keys).
- `src/Models/Calendar/Rite/RiteProfile.php` — add `precedenceResolver(): PrecedenceResolver`.
- `src/Models/Calendar/Rite/RomanRiteProfile.php` — implement it as a deferred `\LogicException`.
- `src/Models/Calendar/Rite/AmbrosianRiteProfile.php` — return `new AmbrosianPrecedenceResolver()`.

**New tests:**

- `phpunit_tests/Models/Calendar/Precedence/AmbrosianLiturgicalDayRankTest.php` (the classifier — one case per rank tier + the of-the-Lord asymmetry).
- `phpunit_tests/Models/Calendar/Precedence/AmbrosianPrecedenceResolverTest.php` (the resolver — one case per transfer rule with constructed coincidences).
- `phpunit_tests/Models/Calendar/Rite/AmbrosianRiteProfileTest.php` / `RomanRiteProfileTest.php` — extend for `precedenceResolver()`.
- `phpunit_tests/Models/Calendar/LiturgicalEventIsDominicalTest.php`, `phpunit_tests/Enum/LitSeasonAmbrosianTest.php` — the additive vocabulary.

**Reference (read-only):**
`src/Models/Calendar/Temporale/{TemporaleEngine,TemporaleContext,RomanTemporale}.php` (the seam pattern),
`src/Models/Calendar/LiturgicalEventCollection.php` (the move/suppress/enumerate API),
`src/Enum/LitGrade.php`, `.superpowers/sdd/precedence-map.md` (the study).

---

## Test Harness Convention (used by resolver tests)

The resolver operates on a `LiturgicalEventCollection` you populate with constructed events. Define a helper
(in Task 6's test) that builds an event with the precedence-relevant attributes and a `PrecedenceContext`
around a collection:

```php
/**
 * Builds a bare LiturgicalEvent with the fields the rank classifier reads.
 * Uses LiturgicalEvent::fromArray() (confirm its exact shape against the model).
 */
private function makeEvent(string $key, string $ymd, LitGrade $grade, LitSeason $season, bool $isDominical = false, bool $isProper = false): LiturgicalEvent
{
    // Confirm the fromArray/fromObject contract; set date, grade, liturgical_season,
    // is_dominical, and the proper flag. If no `isProper` model field exists, carry it
    // via the mechanism Task 4 introduces (see its Interfaces block).
}

/** @param array<string> $messages */
private function buildContext(int $year, array &$messages): PrecedenceContext
{
    LitLocale::$PRIMARY_LANGUAGE = 'it';
    LitLocale::$RUNTIME_LOCALE   = 'it_IT';
    $params = new CalendarParams();
    $params->setParams(['year' => $year]);
    $params->setRite(Rite::AMBROSIAN);
    return new PrecedenceContext(new LiturgicalEventCollection($params), $params, new LocaleDateFormatter(LitLocale::$RUNTIME_LOCALE), $messages);
}
```

> **Implementer:** verify `LiturgicalEvent::fromArray()`/`fromObject()` and the `LiturgicalEventCollection`
> constructor +
> `addLiturgicalEvent`/`getCalEventsFromDate`/`moveLiturgicalEventDate`/`removeLiturgicalEvent`/`addSuppressedEvent`/`getLiturgicalEvent`
> signatures against the current code before writing Task 6. Mirror the Plan 3 harness
> (`AmbrosianTemporaleHarnessTrait`) for the locale save/restore discipline — capture originals in
> `setUpBeforeClass()` and restore in `tearDownAfterClass()` so the forced Italian locale does not leak.

---

## Task 1: `is_dominical` field on `LiturgicalEvent`

**Files:** Modify `src/Models/Calendar/LiturgicalEvent.php`; Test `phpunit_tests/Models/Calendar/LiturgicalEventIsDominicalTest.php`.

**Interfaces:** Produces `public ?bool $is_dominical = null` on `LiturgicalEvent`, serialized in
`jsonSerialize()` only when non-null (mirror the existing optional-flag pattern, e.g. `is_vigil_mass`).
Consumed by the rank classifier (Task 4). The "of the Lord" distinction that lets a grade-7 Lord solemnity
outrank an ordinary Sunday while a grade-7 saint solemnity does not.

- [ ] **Step 1: Write the failing test** — assert a fresh event has `is_dominical === null`; after
  `->is_dominical = true` it serializes; a null value is omitted from `jsonSerialize()` (match the existing
  nullable-flag serialization convention exactly — read how `is_vigil_mass`/`has_vesper_i` are handled).

- [ ] **Step 2: Run RED.** `vendor/bin/phpunit phpunit_tests/Models/Calendar/LiturgicalEventIsDominicalTest.php` → fails (property undefined).

- [ ] **Step 3: Implement.** Add `public ?bool $is_dominical = null;` beside the other optional flags; add its
  conditional entry to `jsonSerialize()`; if `fromObject()`/`fromArray()` should carry it from source data, add
  the passthrough consistently with the sibling flags (read those first).

- [ ] **Step 4: Run GREEN**, then `composer analyse && vendor/bin/phpcs src/Models/Calendar/LiturgicalEvent.php
  && vendor/bin/phpunit phpunit_tests/Handlers/CalendarGoldenMasterTest.php` (golden-master proves additivity).

- [ ] **Step 5: Commit** `feat(ambrosian): add is_dominical flag to LiturgicalEvent`.

---

## Task 2: `LitSeason::AFTER_EPIPHANY` + `AFTER_PENTECOST`

**Files:** Modify `src/Enum/LitSeason.php`; Test `phpunit_tests/Enum/LitSeasonAmbrosianTest.php`.

**Interfaces:** Produces `LitSeason::AFTER_EPIPHANY` (value `'AFTER_EPIPHANY'`) and `AFTER_PENTECOST`
(`'AFTER_PENTECOST'`). Consumed by the rank classifier (rank 4 vs rank 2 Sunday distinction) and rank 13.
Additive; must not change any existing `forEventKey()` result for Roman keys (golden-master).

- [ ] **Step 1: Write the failing test** — the two cases exist with those values and are `from()`-constructable;
  `LitSeason::forEventKey('DedicationDuomo')` and any after-Epiphany/after-Pentecost Ambrosian key map to the
  new seasons; existing Roman keys (`Advent1`, `Lent1`, `Easter2`, `OrdSunday2`) are unchanged.

- [ ] **Step 2: Run RED.**

- [ ] **Step 3: Implement.** Add the two cases. Extend `forEventKey()` with patterns matching ONLY the new
  Ambrosian keys (e.g. `DedicationDuomo`, and any `AfterEpiphany*`/`AfterPentecost*` keys Plan 5 will introduce)
  so no Roman key's mapping changes. Do not remove `ORDINARY_TIME`.

- [ ] **Step 4: Run GREEN**, then `composer analyse && vendor/bin/phpcs src/Enum/LitSeason.php && vendor/bin/phpunit phpunit_tests/Handlers/CalendarGoldenMasterTest.php`.

- [ ] **Step 5: Commit** `feat(ambrosian): add AFTER_EPIPHANY and AFTER_PENTECOST to LitSeason`.

---

## Task 3: `PrecedenceResolver` interface + `PrecedenceContext`

**Files:** Create `src/Models/Calendar/Precedence/PrecedenceResolver.php`,
`src/Models/Calendar/Precedence/PrecedenceContext.php`; Test
`phpunit_tests/Models/Calendar/Precedence/PrecedenceContextTest.php`.

**Interfaces:** Produces:

```php
interface PrecedenceResolver
{
    public function resolve(PrecedenceContext $ctx): void;
}
```

and a `final class PrecedenceContext` mirroring `TemporaleContext`:

```php
final class PrecedenceContext
{
    /** @param array<string> $messages by-ref message sink */
    public function __construct(
        public readonly LiturgicalEventCollection $cal,
        public readonly CalendarParams $params,
        public readonly LocaleDateFormatter $localeDateFormatter,
        public array &$messages
    ) {
    }

    public function addMessage(string $message): void
    {
        $this->messages[] = $message;
    }
}
```

- [ ] **Step 1: Write the failing test** — construct a `PrecedenceContext` (real `LiturgicalEventCollection` +
  `CalendarParams`), assert `addMessage()` appends to the by-ref array and `cal`/`params` are the injected
  instances.

- [ ] **Step 2: Run RED → Step 3: create both files** (read `TemporaleContext.php` and copy its
  structure/imports exactly, swapping the docblock). → **Step 4: GREEN** + `composer analyse && vendor/bin/phpcs
  src/Models/Calendar/Precedence/`.

- [ ] **Step 5: Commit** `feat(ambrosian): add PrecedenceResolver interface + PrecedenceContext`.

---

## Task 4: `AmbrosianLiturgicalDayRank` — the 13-rank classifier

**Files:** Create `src/Models/Calendar/Precedence/AmbrosianLiturgicalDayRank.php`; Test `phpunit_tests/Models/Calendar/Precedence/AmbrosianLiturgicalDayRankTest.php`.

**Interfaces:** Produces a pure classifier `AmbrosianLiturgicalDayRank::rankOf(LiturgicalEvent $e): int`
returning `1..13` (lower = higher precedence), plus the constants that let the resolver ask "is this rank a
solemnity?" / "is a day free of ranks 1–10?". Consumed by `AmbrosianPrecedenceResolver` (Task 5+). The
classifier reads: `$e->grade` (LitGrade), `$e->is_dominical`, `$e->liturgical_season`, an `isProper` marker,
and special-key membership (Dedication of the Duomo, All Souls, *Sabato in traditione symboli*, *settimana
autentica* ferie, Easter/Christmas octave days, Triduum).

> **`isProper` marker.** Prefer reading it off the event; if `LiturgicalEvent` has no origin/proper attribute
> today, introduce the **minimal** carrier the classifier needs — the simplest is an additive `?bool $is_proper
> = null` on the event (default null/false = comune), set only by tests in this plan and by Plan 5's diocesan
> data later. Decide and document this in the task; keep it additive and out of the response schema unless a
> sibling flag is already serialized. Do NOT infer proper-ness from key-name heuristics.

**Rank table** — implement as an **ordered list of predicates evaluated top to bottom, first match wins**, so
it stays inspectable against the Missal. Encode exactly the Tabella above. Sketch (fill in the exact
predicates; `S` = `$e->liturgical_season`, `g` = `$e->grade`, `dom` = `$e->is_dominical === true`, `prop` =
proper):

```text
rank 1  : key ∈ Triduum {HolyThurs, GoodFri, EasterVigil, Easter}
rank 2  : key ∈ {Christmas, Epiphany, Ascension, Pentecost, DedicationDuomo, SabatoTradSymb}
          OR (dom Sunday AND S ∈ {ADVENT, LENT, EASTER})
          OR key ∈ settimana-autentica ferie {MonHolyWeek, TueHolyWeek, WedHolyWeek}   // Mon–Thu; HolyThurs already rank 1
          OR key ∈ Easter octave {Mon..SatOctaveEaster} OR Christmas-octave days
rank 3  : prop == false AND dom AND g ∈ {HIGHER_SOLEMNITY, SOLEMNITY, FEAST_LORD}       // solemnities & feasts of the Lord (comune)
          OR key == AllSouls OR own-church dedication (flagged)
rank 4  : dom Sunday AND S ∈ {AFTER_EPIPHANY, AFTER_PENTECOST} (also "Sunday after the Christmas octave")
rank 5  : prop == false AND NOT dom AND g ∈ {HIGHER_SOLEMNITY, SOLEMNITY}               // BVM & saint solemnities (comune)
rank 6  : prop == true  AND g ∈ {HIGHER_SOLEMNITY, SOLEMNITY}                           // proper solemnities
rank 7  : S == LENT AND g == WEEKDAY                                                    // ferie of Lent
rank 8  : prop == false AND g == FEAST (+ de Exceptáto ferie)
rank 9  : prop == true  AND g == FEAST
rank 10 : prop == false AND g == MEMORIAL
rank 11 : prop == true  AND g == MEMORIAL
rank 12 : g == MEMORIAL_OPT
rank 13 : g == WEEKDAY (any remaining ferie/Saturday)                                    // default floor
```

- [ ] **Step 1: Write failing tests** — one assertion per rank plus the three that encode the *of-the-Lord
  asymmetry* (a grade-7 dominical comune event → rank 3; a grade-7 non-dominical comune event → rank 5; an
  after-Pentecost Sunday → rank 4; therefore Lord-solemnity(3) < after-Pent-Sunday(4) < saint-solemnity(5)).
  Include a Lenten ferie → rank 7, an optional memorial → rank 12, a plain weekday → rank 13, `DedicationDuomo`
  → rank 2.

- [ ] **Step 2: Run RED → Step 3: implement** the ordered predicate list + any needed constants (e.g.
  `Triduum`/octave key sets). → **Step 4: GREEN** + `composer analyse && vendor/bin/phpcs`.

- [ ] **Step 5: Commit** `feat(ambrosian): 13-rank Tabella classifier (AmbrosianLiturgicalDayRank)`.

---

## Task 5: `AmbrosianPrecedenceResolver` — coincidence + suppression core

**Files:** Create `src/Models/Calendar/Precedence/AmbrosianPrecedenceResolver.php`; Test `phpunit_tests/Models/Calendar/Precedence/AmbrosianPrecedenceResolverTest.php`.

**Interfaces:** Produces `AmbrosianPrecedenceResolver implements PrecedenceResolver`.
`resolve(PrecedenceContext $ctx)`: enumerate the collection's events grouped by date (`getCalEventsFromDate` /
iterate `getLiturgicalEvents()`); for each date holding >1 event, sort by
`AmbrosianLiturgicalDayRank::rankOf()`, keep the top, and for each loser dispatch to the transfer/suppression
rule (Tasks 6–7). This task implements the **grouping + winner selection + the simplest loser outcome
(suppression)**; the specific transfers come next.

- [ ] **Step 1: Write the failing test** — two comune events on the same date (a rank-5 saint solemnity and a
  rank-10 memorial): after `resolve()`, the solemnity stays, the memorial is suppressed
  (`getLiturgicalEvent('mem')` no longer at that date / is in the suppressed ledger), and a message explains the
  suppression. Use constructed events via the harness.

- [ ] **Step 2: Run RED → Step 3: implement** `resolve()` — group by `date` (format `Y-m-d` key), skip
  singletons, sort each contested group by `rankOf()` ascending, keep index 0, and for each loser call a
  `private function resolveLoser(LiturgicalEvent $winner, LiturgicalEvent $loser, PrecedenceContext $ctx): void`
  that (in this task) suppresses via `removeLiturgicalEvent()` + `addSuppressedEvent()` and appends a
  `messages[]` explanation via `$ctx->addMessage()`. Wire the transfer branches as stubs that Tasks 6–7 fill (or
  add them here and let those tasks add tests — keep this task's test green).

- [ ] **Step 4: GREEN** (this task's test + no regressions) + `composer analyse && vendor/bin/phpcs`.

- [ ] **Step 5: Commit** `feat(ambrosian): AmbrosianPrecedenceResolver coincidence + suppression core`.

---

## Task 6: Transfer rules — Lord solemnity/feast on a privileged Sunday; saint solemnity Sunday→Monday→Saturday

**Files:** Modify `src/Models/Calendar/Precedence/AmbrosianPrecedenceResolver.php`; extend `AmbrosianPrecedenceResolverTest.php`.

**Interfaces:** Extends `resolveLoser()` with the rank-specific transfers. Consumes
`AmbrosianLiturgicalDayRank`, the collection's `moveLiturgicalEventDate()` + occupancy predicates
(`inSolemnities()`), and `dateIsSunday`-style checks.

**Rules (spec §5):**

- A **solemnity of the Lord** (rank 3, dominical, grade ∈ {7,6}) impeded by an Advent/Lent/Easter **Sunday** (rank 2) → move to the **Monday** (Sunday + 1 day).
- A **feast of the Lord** (dominical, `FEAST_LORD`/`FEAST`) so impeded → **omit** (suppress, do not transfer) that year.
- A **solemnity of a saint** (rank 5/6, non-dominical, grade ∈ {7,6}) impeded by a Sunday → move to the
  **Monday**; if that Monday is itself a solemnity (`inSolemnities(monday)`), **anticipate to the Saturday**
  before the Sunday.

- [ ] **Step 1: Write failing tests** with constructed coincidences and **verified deterministic dates**.
  Example fixtures (implementer confirms each date with a one-off PHP snippet before asserting, as in Plan 3):
  - Saint solemnity placed on an Advent Sunday `2025-11-16` (Advent I 2025) → moves to Monday `2025-11-17`.
  - Same, but the Monday is occupied by another solemnity → anticipated to Saturday `2025-11-15`.
  - A Lord solemnity on a Lent Sunday `2025-03-09` (Lent I 2025) → Monday `2025-03-10`.
  - A feast of the Lord on that same Lent Sunday → suppressed (omitted), not moved.

- [ ] **Step 2: Run RED → Step 3: implement** the branches in `resolveLoser()` keyed on the loser's
  rank/dominical/grade and the winner's rank/season, using `moveLiturgicalEventDate()` and the Saturday-fallback
  via `inSolemnities()`. Append a distinct message per outcome. → **Step 4: GREEN** + `composer analyse &&
  vendor/bin/phpcs`.

- [ ] **Step 5: Commit** `feat(ambrosian): Sunday-impeded Lord/saint transfer rules`.

---

## Task 7: Lenten ferie + Annunciation/St Joseph; generic n.56 transfer

**Files:** Modify `src/Models/Calendar/Precedence/AmbrosianPrecedenceResolver.php`; extend the resolver test.

**Interfaces:** Completes `resolveLoser()` with the Lenten-ferie rule, the Annunciation/St Joseph Holy-Week transfer, and the generic n.56 "first day free of ranks 1–10" fallback.

**Rules (norms 4, 56 + spec §5):**

- **Ferie of Lent** (rank 7) yield **only** to the solemnities of the **Annunciation** and **St Joseph** — any
  other higher day does not displace a Lenten ferie's precedence beyond the Tabella (i.e. Lenten ferie are
  protected except against those two solemnities).
- **Annunciation / St Joseph** falling in the *Sabato in traditione symboli* or the *settimana autentica* →
  transfer to the **Monday** / **Tuesday** after the Easter octave, respectively. (Identify the two events by a
  stable key/flag the fixtures set; the "after the Easter octave" anchor = `SatOctaveEaster + N days` — confirm
  the exact target with a PHP snippet.)
- **Generic (n.56):** a **solemnity** impeded by any higher-ranked day, with no specific rule above, transfers
  to the **first subsequent day free of ranks 1–10** (walk forward day-by-day, using `rankOf()` on each date's
  occupants / the collection's occupancy predicates, until a free day is found); other impeded celebrations that
  year are **omitted**.

- [ ] **Step 1: Write failing tests** with constructed coincidences and verified dates: (a) a Lenten ferie
  contested by an ordinary memorial → the ferie's precedence holds (memorial suppressed/reduced); (b) the
  Annunciation placed in the settimana autentica → lands on the Monday after the Easter octave (compute the
  date); (c) a generic solemnity impeded on two consecutive higher days → lands on the first free day (construct
  a small occupied run and assert the landing date).

- [ ] **Step 2: Run RED → Step 3: implement** the three branches. For the n.56 walk, cap the forward search
  (e.g. ≤ 366 days) and `addMessage()` if no free day is found (should not happen; guard anyway). → **Step 4:
  GREEN** (whole resolver + rank suites) + `composer analyse && vendor/bin/phpcs`.

- [ ] **Step 5: Commit** `feat(ambrosian): Lenten-ferie, Annunciation/St-Joseph, and generic n.56 transfers`.

---

## Task 8: Wire the `precedenceResolver()` seam

**Files:** Modify `src/Models/Calendar/Rite/RiteProfile.php`, `RomanRiteProfile.php`,
`AmbrosianRiteProfile.php`; extend `RomanRiteProfileTest.php` + `AmbrosianRiteProfileTest.php`.

**Interfaces:** Adds `public function precedenceResolver(): PrecedenceResolver;` to `RiteProfile`.
`RomanRiteProfile::precedenceResolver()` throws `\LogicException('Roman precedence is resolved inline in
CalendarHandler; no resolver is extracted.')` (deferred, never reached — Roman uses the inline handler,
Ambrosian is 501). `AmbrosianRiteProfile::precedenceResolver()` returns `new AmbrosianPrecedenceResolver()`.

- [ ] **Step 1: Write failing tests** — `AmbrosianRiteProfile::precedenceResolver()` returns an
  `AmbrosianPrecedenceResolver` instance; `RomanRiteProfile::precedenceResolver()` throws `\LogicException`.
  Update the interface-conformance expectations if a test asserts the full method set.

- [ ] **Step 2: Run RED → Step 3: implement** the interface method + both overrides (+ imports). → **Step 4:
  prove the endpoint invariant unchanged**: `vendor/bin/phpunit
  phpunit_tests/Handlers/CalendarRiteRoutingTest.php phpunit_tests/Handlers/CalendarGoldenMasterTest.php`
  (Ambrosian still 501; Roman byte-identical — the handler never calls `precedenceResolver()`). + `composer
  analyse && vendor/bin/phpcs`.

- [ ] **Step 5: Commit** `feat(ambrosian): add precedenceResolver() to the RiteProfile seam`.

---

## Task 9: Mark the Ambrosian temporale data's dominical events

**Files:** Modify `jsondata/sourcedata/missals/ambrosian/propriumdetempore/propriumdetempore.json`; extend `AmbrosianProprioDeTemporeDataTest.php`.

**Interfaces:** Adds `"is_dominical": true` to the temporal keys that are "of the Lord" (all Sundays and the
dominical solemnities/feasts of the Lord), so that when the resolver is wired (Plan 5/6) the temporale events
already classify correctly. Consumed by the rank classifier via `LiturgicalEvent::fromObject` (Task 1's
passthrough).

> **Scope note:** this only annotates the *temporale* data that already exists. Populating
> `is_dominical`/`is_proper` on the comune + diocesan **sanctorale** is Plan 5's data work. Confirm
> `fromObject()` carries `is_dominical` from the JSON (Task 1); if the source-data schema is Health-validated,
> extend the Ambrosian temporale schema/enum to permit the optional `is_dominical` key.

- [ ] **Step 1: Write the failing test** — load the Ambrosian proprium; assert the Sunday/Lord keys (e.g.
  `Advent1`..`Advent6`, `Lent1`..`Lent5`, `Easter`, `Easter2`..`Easter7`, `Epiphany`, `Ascension`, `Pentecost`,
  `PalmSun`, `DedicationDuomo`, `ChristKing`, `Christmas`, `Circoncisione`, `Baptism`) carry `is_dominical ===
  true`; non-dominical/ferial keys (`AshesMonday`, octave weekdays) do not. (Decide the exact dominical set from
  the Tabella — Sundays and Lord solemnities/feasts are dominical; weekday ferie are not.)

- [ ] **Step 2: Run RED → Step 3: add `"is_dominical": true`** to the chosen keys (+ schema/enum allowance if
  Health-validated). → **Step 4: GREEN** (data test + the Plan 3 `AmbrosianTemporaleTest` still green — dates
  unchanged) + `composer analyse && vendor/bin/phpcs` + JSON lint + `@group slow` schema corpus if applicable.

- [ ] **Step 5: Commit** `feat(ambrosian): mark dominical (of-the-Lord) temporale keys`.

---

## Self-Review (completed by plan author)

**Spec coverage (spec §5, superseding note in Background):** the 13-rank Tabella encoded as inspectable data
(Task 4) · of-the-Lord asymmetry via `is_dominical` (Tasks 1, 4, 9) · transfer rules — Lord/saint
Sunday→Monday→Saturday (Task 6), Lenten-ferie + Annunciation/St-Joseph + generic n.56 (Task 7) ·
shared-infrastructure output via existing move/suppress/messages APIs (Tasks 5–7) · Vespers (n.57) explicitly
out of scope · Roman extraction explicitly deferred (Background + Task 8's deferred-throw). ✓

**Deferred (documented, not built here):** real is_dominical/is_proper/season on comune+diocesan sanctorale,
resolver wiring into an Ambrosian pipeline, endpoint un-501-ing, Dec 26–28 octave interactions (norm 32) — all
later plans. ✓

**Placeholder scan:** the rank table is given as an explicit ordered predicate list; transfer rules give
verified-date example fixtures the implementer confirms (Plan-3 pattern). The one genuinely open modelling
choice — the `isProper` carrier — is called out in Task 4's Interfaces block with a concrete default, not left
blank. ✓

**Type/name consistency:** `PrecedenceResolver::resolve(PrecedenceContext)` and
`AmbrosianLiturgicalDayRank::rankOf(LiturgicalEvent): int` are used consistently across Tasks 3–8;
`is_dominical` (Task 1) and `AFTER_EPIPHANY`/`AFTER_PENTECOST` (Task 2) are consumed by the classifier (Task
4) and data (Task 9); the seam method name `precedenceResolver()` matches the `RiteProfile` docblock's own
anticipation. ✓

**Risk note carried to execution:** the rank classifier's exact predicate boundaries (esp. rank 2 vs 3 vs 4
for edge keys, and the `isProper` split) and the transfer-date arithmetic are the parts most likely to need
adjustment once real sanctorale data and an ordo cross-check exist (Plan 5–6). Keep the classifier a pure,
table-driven function so those adjustments are localized.
