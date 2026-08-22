# Diocesan `setProperty` action for Ambrosian grade overrides

Design for [issue #740](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/740).

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

Each override changes **three** properties, not just grade:

- **grade** — 4 → 3 in every case.
- **common** — a specific Common → `["Proper"]` in every case.
- **name** — from the diocesan i18n tree. `StFrancisOfAssisi` is
  *"S. Francesco d'Assisi, patrono d'Italia"* in the comune but *"S. Francesco d'Assisi"* in Lugano
  (Switzerland — the patron-of-Italy epithet is deliberately dropped); the Latin names differ for
  `StsProtaseGervase` too.

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
grow the vocabulary later. It costs verbosity: each override becomes 3 rows, so the 4 overrides
become 12. `form_rownum` is a Frontend admin-form artifact and is not read anywhere in `src/`, so
multiple rows per Missal line is mechanically fine.

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

Effect: 37 of the 40 diocesan rows move festive → ferial (Milan 29, Lugano 3, Bergamo 3, Novara 2);
3 rows at grade ≥ FEAST are unchanged. The visible difference is a single empty `second_reading: ""`
field disappearing. Both placeholders are all-empty-strings and both validate against
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
12 setProperty rows. Example:

```json
{
  "liturgical_event": { "event_key": "StsProtaseGervase", "grade": 3 },
  "metadata": { "action": "setProperty", "property": "grade", "since_year": 2024, "form_rownum": 1 }
},
{
  "liturgical_event": { "event_key": "StsProtaseGervase", "common": ["Proper"] },
  "metadata": { "action": "setProperty", "property": "common", "since_year": 2024, "form_rownum": 2 }
},
{
  "liturgical_event": { "event_key": "StsProtaseGervase" },
  "metadata": { "action": "setProperty", "property": "name", "since_year": 2024, "form_rownum": 3 }
}
```

`form_rownum` is renumbered sequentially within each file. The i18n files are unchanged.

## Rite scoping

`applyDiocesanCalendar()` (Roman) rejects a `setProperty` item with a clear
"not supported for the Roman rite" error. Roman diocesan events are key-prefixed, so "modify the
national or general event in place" is a genuinely new semantic that needs its own design and test
coverage; this design does not attempt it. The schema documents the action as Ambrosian-scoped.

Roman diocesan behaviour is therefore provably untouched, and the golden masters must stay
byte-identical.

## Testing

| Test                                                      | Covers                                                                                                                                          |
| --------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| `LiturgicalEventCollectionSetPropertyCommonTest` (new)    | §1 — `common` can be set as both `LitCommons` and `array`; the widened parameter type accepts them                                              |
| `CalendarHandlerAmbrosianDiocesanSetPropertyTest` (new)   | §4 — grade/common/name applied in place; key not duplicated; overridden event absent from `suppressed_events`; absent-key no-op emits a message |
| `AmbrosianReadingsForGradeTest` (new)                     | §5 — festive at ≥ FEAST, ferial below                                                                                                           |
| `EventsHandlerRiteRoutingTest` (update)                   | §6 — no phantom prefixed duplicate; catalog key matches the resolved key                                                                        |
| `CalendarHandlerAmbrosianDiocesanTest` (update)           | createNew path still correct; readings now grade-derived                                                                                        |
| `SchemaValidationTest`                                    | §3 — all 4 Ambrosian diocesan source files validate against the new `oneOf`                                                                     |
| `CalendarGoldenMasterTest`                                | 9/9 byte-identical — Roman untouched                                                                                                            |

Full suite, PHPStan level 10, and phpcs must all be green.

None of these belong in the `slow` group.

## Acceptance criteria (from the issue)

- [ ] Suffragan downgrades render at the diocesan grade, common and name in `/calendar`.
- [ ] `/events/ambrosian/diocese/{id}` lists no phantom prefixed duplicate for an overridden key.
- [ ] No overridden comune event appears in `suppressed_events`.
- [ ] Golden master 9/9 byte-identical; suite, PHPStan and phpcs green.

**Deviation from the issue text:** the issue asks for Ambrosian `/calendar` output "exactly as
today". Readings placeholders change for 37 diocesan rows (§5), by explicit decision.

## Follow-ups (not in scope)

- Make the comune sanctorale readings placeholder grade-derived too.
- Decide whether Roman diocesan calendars should gain a modify-in-place action.
