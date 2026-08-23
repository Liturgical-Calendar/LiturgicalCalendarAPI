# Diocesan `setProperty` action for Ambrosian grade overrides

Design for [issue #740](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/740).

**Status: implemented** (2026-08-23), on `feature/740-diocesan-set-property` — 26 commits: the ten
planned tasks, plus the fix wave that followed the whole-branch review.

Verified in this environment:

| Check                              | Result                                                     |
| ---------------------------------- | ---------------------------------------------------------- |
| Golden master (Roman rite)         | 9/9 byte-identical                                         |
| PHPStan level 10                   | No errors                                                  |
| phpcs                              | clean                                                      |
| `phpstan-baseline.neon`            | unchanged across the whole branch                          |
| Test suite, excluding `WebSocket/` | 2970 tests, 0 failures, 337 pre-existing environment skips |

**Not verified here: the full suite cannot run to completion in this environment.**
`phpunit_tests/WebSocket/*` requires a running WebSocket server and the `docker-compose` stack;
without them those tests time out, and `composer test` aborts on its own 300-second process
timeout before finishing. The branch touches no WebSocket, Zitadel or OpenFGA file, so those tests
are unaffected by it — but that is an argument from the diff, not an observed pass. Anyone with the
stack running should confirm a full green suite before merging.

## Problem

Ambrosian suffragan dioceses celebrate a handful of comune (Milan) feasts at a lower grade — the
Missal's *"Al di fuori di Milano"* rows. The Roman diocesan model cannot express a downgrade: it
prefixes diocesan keys and adds events alongside the comune one, and precedence resolution always
keeps the *higher* grade.

The Ambrosian overlay works around this by **re-declaring the comune `event_key`** in the diocesan
file. `CalendarHandler::applyAmbrosianDiocesanCalendar()` sees the key already occupied, calls
`LiturgicalEventCollection::removeLiturgicalEventWithoutSuppression()`, and re-adds the diocesan
definition in its place.

Three problems follow:

1. **`/events` and `/calendar` disagree.** `EventsHandler::processAmbrosianDiocesanCalendarData()`
   prefixes every diocesan key, so the catalog lists `lugano_ch_StFrancisOfAssisi` *alongside* the
   still-present comune `StFrancisOfAssisi` — two entries, neither matching the single plain-keyed
   `StFrancisOfAssisi` that `/calendar` emits.
2. **A method that exists only to hide a destructive operation.**
   `removeLiturgicalEventWithoutSuppression()` is bookkeeping that keeps a replaced comune event
   out of `suppressed_events` and clears the per-grade sub-collections. An in-place change needs
   neither.
3. **A footgun.** Reusing a comune `event_key` silently deletes the comune celebration, with no
   diagnostic if it happens by mistake.

## Scope of the real data

Only **4 rows across 3 dioceses** collide with a comune key. Milan's 31 proper saints use unique
keys and are unaffected.

| Diocese | `event_key`         | comune (Milan)                             | diocesan              |
| ------- | ------------------- | ------------------------------------------ | --------------------- |
| Lugano  | `StsProtaseGervase` | grade 4, `["Martyrs:For Several Martyrs"]` | grade 3, `["Proper"]` |
| Lugano  | `StFrancisOfAssisi` | grade 4, `["Pastors:For One Founder"]`     | grade 3, `["Proper"]` |
| Bergamo | `StsProtaseGervase` | grade 4, `["Martyrs:For Several Martyrs"]` | grade 3, `["Proper"]` |
| Novara  | `StsProtaseGervase` | grade 4, `["Martyrs:For Several Martyrs"]` | grade 3, `["Proper"]` |

An override row changes whichever properties it needs — one property per row. These four are not
uniform:

| Row                         | grade | common | name                                                                                     |
| --------------------------- | ----- | ------ | ---------------------------------------------------------------------------------------- |
| Lugano `StFrancisOfAssisi`  | 4 → 3 | ✓      | ✓ — comune *"S. Francesco d'Assisi, patrono d'Italia"*, Lugano *"S. Francesco d'Assisi"* |
| Lugano `StsProtaseGervase`  | 4 → 3 | ✓      | ✗ — Italian identical                                                                    |
| Bergamo `StsProtaseGervase` | 4 → 3 | ✓      | ✗ — Italian identical                                                                    |
| Novara `StsProtaseGervase`  | 4 → 3 | ✓      | ✗ — Italian identical                                                                    |

Only Lugano's `StFrancisOfAssisi` is a genuine name override: Lugano is Swiss, so the
patron-of-Italy epithet is deliberately dropped. The three `StsProtaseGervase` rows carry an
identical Italian name; their Latin differs from the comune only in grammatical **case**
(*Sancti Protasius et Gervasius, martyres* — nominative — against the comune's genitive
*Sanctorum Protasii et Gervasii, martyrum*), which is the diocesan tree's house style rather than
an override. See §9.

So the data needs **9 rows**, not 12: grade + common for each of the four overrides, plus a single
name row for `StFrancisOfAssisi`.

**colour** is identical in all four cases (red/red, white/white), so no colour override is needed.

## Decisions

| Decision             | Choice                                                                               |
| -------------------- | ------------------------------------------------------------------------------------ |
| Action shape         | Mirror `NationalData`: `metadata.action` + `metadata.property`, one property per row |
| Property vocabulary  | `grade`, `name`, `common`                                                            |
| Readings placeholder | Derived from grade, applied across the whole diocesan overlay                        |
| Rite scope           | Ambrosian only for now                                                               |

### Why per-property rows

Mirroring the existing national vocabulary (`LitCalItemSetPropertyGrade` /
`LitCalItemSetPropertyName`) keeps one concept in the codebase instead of two, and leaves room to
grow the vocabulary later. It costs verbosity: the four overrides become 9 rows.
`form_rownum` is a Frontend admin-form artifact and is not read anywhere in `src/`, so multiple rows
per Missal line is mechanically fine.

National also has two *combined* convenience actions, but neither fits this case:

- `makePatron` sets **grade + name** in one row — no `common`.
- `makeDoctor` (decree-only) sets **name** alone, computing "…and Doctor of the Church".

A grade + common combiner would have to be invented. At 9 rows that is not worth a new action; if
the Ambrosian diocesan corpus grows a lot more overrides, revisit it.

### Why `common` joins the vocabulary

National supports only `name` and `grade`. Without `common`, the resolved calendar would keep the
comune's Common (`["Martyrs:For Several Martyrs"]`) on a diocesan memorial that the Missal marks
`Proper` — a visible, wrong output change. Adding it requires widening `setProperty()`'s parameter
type (below).

## Architecture

### 1. `LiturgicalEventCollection::setProperty()` — widen the accepted value type

The signature at `src/Models/Calendar/LiturgicalEventCollection.php:827` is:

```php
public function setProperty(string $key, string $property, string|int|bool|LitGrade $newValue): bool
```

`LiturgicalEvent::$common` is typed `LitCommons|array`. `LitCommons` has no `__toString()` and does
not implement `Stringable`, and the file does not `declare(strict_types=1)`, so passing either a
`LitCommons` or a plain `array` raises a **`TypeError` at the call boundary** — the method body is
never entered. `$color` (typed `array`) is unreachable for the same reason.

Fix: widen the parameter to `string|int|bool|array|LitGrade|LitCommons`.

The reflection logic inside the method already handles union-typed properties correctly and needs
no change. `$unionTypeCondition` compares a `get_debug_type()` string against the
`ReflectionNamedType` objects from `getTypes()` using a **loose** `in_array()`; because
`ReflectionNamedType` implements `__toString()`, PHP coerces each member to its type name and the
comparison resolves as intended (verified: `in_array('array', $unionType->getTypes())` is `true`).
It is correct but relies on implicit coercion — mapping `getTypes()` through `getName()` first would
say the same thing explicitly, and is worth doing as a readability change, not a fix.

Note also that `$readings` is declared `public private(set)`. `ReflectionProperty::isPublic()`
reports `true` for it (read visibility), so the existing `isPublic()` guard would not reject it and
the assignment would fatal. This is currently unreachable — the widened parameter type still
excludes every `Readings*` class — but `readings` must stay out of any future property vocabulary.

### 2. Data model — `src/Models/RegionalData/DiocesanData/`

New classes, mirroring `NationalData/` one-for-one:

- `DiocesanLitCalItemSetPropertyGrade` + `DiocesanLitCalItemSetPropertyGradeMetadata`
- `DiocesanLitCalItemSetPropertyName` + `DiocesanLitCalItemSetPropertyNameMetadata`
- `DiocesanLitCalItemSetPropertyCommon` + `DiocesanLitCalItemSetPropertyCommonMetadata`

Each metadata class carries `readonly CalEventAction $action` and `readonly string $property`,
pinned in the constructor exactly as the national ones are.

`DiocesanLitCalItem::__construct()` gains a dispatch on `metadata.action`:

- **absent ⇒ `CalEventAction::CreateNew`.** Every existing diocesan file — all 4 Ambrosian and
  every Roman one — omits `action`, so back-compat is free and no existing data needs touching.
- `setProperty` ⇒ one of the three new types, selected by `metadata.property`; an unrecognised
  `property` throws `\ValueError`.

The union on `DiocesanLitCalItem::$liturgical_event` and on `DiocesanLitCalItemCollection` widens
accordingly.

**Names come from i18n, not from the row.** Diocesan calendars keep names in
`.../{nation}/{diocese}/i18n/{locale}.json`, and `DiocesanData::setNames()` already stamps every
item's `->liturgical_event->name` from that file. A `setProperty:name` row therefore carries only
`{"event_key": "..."}`; the value is injected by `setNames()`. Because all three rows for one
override share an `event_key`, the single existing i18n entry serves all of them and `setNames()`
(which throws on a missing translation) stays satisfied.

### 3. Schema — `jsondata/schemas/DiocesanCalendar.json`

Split the inline `LitCal.items` object into a `oneOf` over named definitions, matching how
`NationalCalendar.json` is already organised:

- `DiocesanCreateNewFixed`, `DiocesanCreateNewMobile` — today's shape, `action` optional
  (`enum: ["createNew"]`).
- `DiocesanSetPropertyGrade` — requires `liturgical_event.event_key` + `grade`;
  `metadata.action: "setProperty"`, `metadata.property: "grade"`.
- `DiocesanSetPropertyName` — requires `liturgical_event.event_key` only.
- `DiocesanSetPropertyCommon` — requires `liturgical_event.event_key` + `common`.

The current blanket `required: ["event_key", "color", "grade", "common"]` applies only to the
create-new branches.

### 4. `CalendarHandler::applyAmbrosianDiocesanCalendar()`

Dispatch per item:

- **createNew** (Fixed | Mobile) — today's path: interpret the date, build the `LiturgicalEvent`,
  stamp readings, add.
- **setProperty** — skip date interpretation entirely (these rows carry no `day`/`month`/
  `strtotime`), then call `$this->Cal->setProperty($key, $property, $value)`. A `false` return means
  the key is not present in this year's calendar; push an explanatory message onto
  `$this->Messages` rather than failing.

The `removeLiturgicalEventWithoutSuppression()` branch is deleted. Grade changes flow through
`handleGradeProperty()`, which keeps the per-grade sub-collections consistent — the reason the
removal helper existed in the first place.

Ordering is unchanged: the overlay still runs after `addAmbrosianSanctoraleToCalendar()` (so the
comune definitions exist to be modified) and before `resolveAmbrosianPrecedence()` (so the new grade
is what precedence resolution sees).

### 5. Readings placeholder — `AmbrosianReadings::forGrade()`

There is no Ambrosian lectionary yet, so every event carries an empty-readings placeholder. Today
there are exactly three call sites:

| Call site                                | Placeholder                                    |
| ---------------------------------------- | ---------------------------------------------- |
| `addAmbrosianSanctoraleToCalendar()`     | `empty()` — 4-field ferial, all grades         |
| `applyAmbrosianDiocesanCalendar()`       | `emptyFestive()` — 5-field festive, all grades |
| `backfillAmbrosianReadingsPlaceholder()` | `empty()` — 4-field ferial                     |

The blanket-festive choice in the diocesan overlay was never a liturgical decision (its docblock
attributes it to "this task's brief"), and an in-place modify has no natural moment to re-stamp it.

Add `AmbrosianReadings::forGrade(LitGrade $grade)`: festive at `>= LitGrade::FEAST`, ferial below.
Use it for **every** diocesan row, createNew and setProperty alike.

Effect, counted against the re-authored data (§8): of the **36** remaining createNew rows, **33** at
grade < FEAST move festive → ferial and 3 at grade ≥ FEAST are unchanged. The 4 setProperty rows
change nothing here — they all downgrade to MEMORIAL, and the comune events they modify already
carry the ferial placeholder from `addAmbrosianSanctoraleToCalendar()`.

So **33 events change**, not 37; the earlier figure was counted before the override rows were
reduced to setProperty. The visible difference is a single empty `second_reading: ""` field
disappearing. Both placeholders are all-empty-strings and both validate against
`CommonDef.json#/definitions/Readings`; no golden master covers Ambrosian.

Comune sanctorale keeps its ferial-for-everything placeholder. Making *that* grade-derived would
change comune-only Ambrosian output for every feast and solemnity and belongs in its own issue.

### 6. `EventsHandler::processAmbrosianDiocesanCalendarData()`

- **createNew** rows — unchanged: `{diocese}_{key}` prefixing, `[ {diocese_name} ] {name}`.
- **setProperty** rows — add nothing to the catalog. Fetch the existing comune entry with
  `LiturgicalEventMap::getEvent($key)` and apply the property to it under its **plain** key, using
  the **unprefixed** diocesan name so the catalog entry matches what `/calendar` emits. If the key
  is absent from the catalog, skip (consistent with the calendar path's no-op).

This is what removes the phantom prefixed duplicate.

### 7. Remove `removeLiturgicalEventWithoutSuppression()`

After §4 its only remaining caller is its own test. Delete the method
(`LiturgicalEventCollection.php:918`) and `phpunit_tests/Models/Calendar/LiturgicalEventCollectionRemoveWithoutSuppressionTest.php`.
It is internal — not reachable from any HTTP route — so removal is not a public API break.

### 8. Re-author the data

The 4 override rows in `Lugano.json`, `Diocesi di Bergamo.json` and `Diocesi di Novara.json` become
**9** setProperty rows — grade + common each, plus one name row for `StFrancisOfAssisi`:

```json
{
  "liturgical_event": { "event_key": "StsProtaseGervase", "grade": 3 },
  "metadata": { "action": "setProperty", "property": "grade", "since_year": 2024, "form_rownum": 1 }
},
{
  "liturgical_event": { "event_key": "StsProtaseGervase", "common": ["Proper"] },
  "metadata": { "action": "setProperty", "property": "common", "since_year": 2024, "form_rownum": 2 }
}
```

`form_rownum` is renumbered sequentially within each file.

### 9. Normalize the diocesan Latin names to the genitive

The Ambrosian **comune** sanctorale renders every Latin entry in the **genitive**, the Missal's
calendar convention:

```text
Sancti Raimundi de Penyafort, presbyteri
Sanctorum Basilii Magni et Gregorii Nazianzeni, episcoporum et Ecclesiae doctorum
```

Every one of the **40** entries in the Ambrosian **diocesan** `la_VA` i18n files is instead
**nominative**:

```text
Sanctus Petrus de Verona, presbyter et martyr
Beatus Manfredus Settala, presbyter
Sanctus Ioannes XXIII, papa
```

This divergence is pre-existing and already visible: all 40 diocesan events render nominative in a
Latin calendar whose comune events are genitive. #740 neither causes nor worsens it, but the
override work makes it conspicuous — with no name row, `StsProtaseGervase` picks up the comune's
genitive form, so the tree's two conventions would meet inside the same calendar.

Convert all 40 entries across the four `la_VA` files to the genitive. Italian surnames stay
indeclinable, as is standard in Latin liturgical texts:

```text
Beatus Manfredus Settala, presbyter   ->  Beati Manfredi Settala, presbyteri
Sanctus Ioannes XXIII, papa           ->  Sancti Ioannis XXIII, papae
Sanctus Arialdus, diaconus et martyr  ->  Sancti Arialdi, diaconi et martyris
```

This is a data change independent of the refactor: it lands as its **own commit**, with the full
40-entry conversion table presented for review before it is applied. It changes Latin `/calendar`
and `/events` output for all 40 diocesan events. Italian output is untouched.

## Rite scoping

`applyDiocesanCalendar()` (Roman) rejects a `setProperty` item with a clear
"not supported for the Roman rite" error. Roman diocesan events are key-prefixed, so "modify the
national or general event in place" is a genuinely new semantic that needs its own design and test
coverage; this design does not attempt it. The schema documents the action as Ambrosian-scoped.

Roman diocesan behaviour is therefore provably untouched, and the golden masters must stay
byte-identical.

## Testing

| Test                                                    | Covers                                                                                                                                          |
| ------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| `LiturgicalEventCollectionSetPropertyCommonTest` (new)  | §1 — `common` can be set as both `LitCommons` and `array`; the widened parameter type accepts them                                              |
| `CalendarHandlerAmbrosianDiocesanSetPropertyTest` (new) | §4 — grade/common/name applied in place; key not duplicated; overridden event absent from `suppressed_events`; absent-key no-op emits a message |
| `AmbrosianReadingsForGradeTest` (new)                   | §5 — festive at ≥ FEAST, ferial below                                                                                                           |
| `EventsHandlerRiteRoutingTest` (update)                 | §6 — no phantom prefixed duplicate; catalog key matches the resolved key                                                                        |
| `CalendarHandlerAmbrosianDiocesanTest` (update)         | createNew path still correct; readings now grade-derived                                                                                        |
| `SchemaValidationTest`                                  | §3 — all 4 Ambrosian diocesan source files validate against the new `oneOf`                                                                     |
| `CalendarGoldenMasterTest`                              | 9/9 byte-identical — Roman untouched                                                                                                            |

Full suite, PHPStan level 10, and phpcs must all be green.

None of these belong in the `slow` group.

## Acceptance criteria (from the issue)

- [x] Suffragan downgrades render at the diocesan grade and common in `/calendar`, and at the
      diocesan name for `StFrancisOfAssisi`.
      *Proved by* `CalendarHandlerAmbrosianDiocesanSetPropertyTest::testSetProperty{Grade,Common,Name}ChangesTheComuneEventInPlace`,
      and end-to-end against the on-disk data by `CalendarHandlerAmbrosianDiocesanTest`.
- [x] `/events/ambrosian/diocese/{id}` lists no phantom prefixed duplicate for an overridden key.
      *Proved by* `EventsHandlerRiteRoutingTest::testAmbrosianDiocesanOverrideHasNoPrefixedDuplicate`,
      which also asserts a genuine createNew row is still prefixed, so it cannot pass by disabling
      prefixing wholesale.
- [x] No overridden comune event appears in `suppressed_events`.
      *Proved by* the `isSuppressed()` assertions in `CalendarHandlerAmbrosianDiocesanSetPropertyTest`
      and `CalendarHandlerAmbrosianDiocesanTest`.
- [x] Golden master 9/9 byte-identical; PHPStan level 10 and phpcs green.
- [ ] **Full suite green — not verifiable in this environment.** Everything outside
      `phpunit_tests/WebSocket/` passes (2970 tests, 0 failures). The WebSocket suite needs a running
      WebSocket server and the `docker-compose` stack; see the status note at the top of this
      document. This box should be ticked by someone who can run the complete suite.

**Deviations from the issue text.** The issue asks for Ambrosian `/calendar` output "exactly as
today". Three deliberate changes:

1. Readings placeholders change for 33 diocesan events (§5).
2. `StsProtaseGervase` takes the comune's genitive Latin name instead of the diocesan nominative,
   since no name row is authored (§8).
3. All 40 diocesan Latin names move to the genitive (§9).

Italian output changes only where a genuine override applies. Roman output is untouched.

## Follow-ups (not in scope)

- Make the comune sanctorale readings placeholder grade-derived too.
- Decide whether Roman diocesan calendars should gain a modify-in-place action.
- Consider a combined grade + common action if the Ambrosian override corpus grows.
