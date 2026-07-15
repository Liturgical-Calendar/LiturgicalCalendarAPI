# Issue #314 — Refine the LitCalDecreesSource schema

**Date:** 2026-07-15
**Issue:** [Liturgical-Calendar/LiturgicalCalendarAPI#314](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/314)
**Milestone:** v5.3

## Problem

`jsondata/schemas/LitCalDecreesSource.json` is lax and generic: a single `LitCalDecree` definition
accommodates every decree by simply not requiring most properties. The shape of a decree actually
depends on its `metadata.action` (and, for `setProperty`, on `metadata.property`), exactly as
`NationalCalendar.json` already models for national calendar items.

The PHP models already discriminate five shapes (`src/Models/Decrees/`):

| Model class                 | Discriminator                          | Decrees in data |
|-----------------------------|----------------------------------------|-----------------|
| `DecreeItemCreateNewFixed`  | `createNew` + `day`/`month`            | 8               |
| `DecreeItemCreateNewMobile` | `createNew` + `strtotime`              | 1               |
| `DecreeItemSetPropertyGrade`| `setProperty` + `property: grade`      | 1               |
| `DecreeItemSetPropertyName` | `setProperty` + `property: name`       | 1               |
| `DecreeItemMakeDoctor`      | `makeDoctor`                           | 2               |

The schema should mirror these shapes so that source data, write payloads, and responses are all
validated precisely.

## Constraints

- **Draft-07 only.** Validation uses `swaggest/json-schema ~0.12`, which does not support
  `unevaluatedProperties` (2019-09+). Strict variants (`additionalProperties: false`) therefore
  cannot be composed via `allOf` inheritance; each variant lists its full property set, with
  property-level `$ref`s to shared definitions to limit duplication. This matches the existing
  `NationalCalendar.json` precedent.
- **`LitCalDecreeWritePayload.json` `$ref`s into the source schema** (`LiturgicalEvent`,
  `Metadata`, and individual `LitCalDecree` property definitions), so it must be restructured in
  the same change.
- The method-dependent sidecar matrix (i18n/readings on PUT vs PATCH) stays in
  `DecreeWritePayloadGuard` — it cannot be expressed method-sensitively in a JSON schema, and the
  guard's error messages are better than draft-07 `oneOf` failures.

## Decisions taken

1. **Data cleanup:** remove the stray `grade` from `StMartha_NameChange.liturgical_event` in
   `decrees.json` (ignored by `DecreeItemSetPropertyName`; it was only ever contextual). No other
   data changes.
2. **`urls_langs` stays** in the source data and source/write schemas as an optional property: it
   is a precalculated commodity (derivable from `url` + `url_lang_map`) for consuming clients.
   The models drop it, so it does **not** appear in the response schema.
3. **`decree_id` suffix is enforced per action:** `_Create` ↔ `createNew`, `_Upgrade` ↔
   `setProperty`/`grade`, `_NameChange` ↔ `setProperty`/`name`, `_Doctor` ↔ `makeDoctor`.
4. **Scope includes all three decree schemas:** source, write payload, and response path.

## Design

### 1. `LitCalDecreesSource.json`

`items` becomes a `oneOf` over five self-contained definitions, each `additionalProperties: false`
at every level:

| Definition               | `decree_id` pattern           | `liturgical_event` required properties                                                        | `metadata` consts                        |
|--------------------------|-------------------------------|-----------------------------------------------------------------------------------------------|------------------------------------------|
| `DecreeCreateNewFixed`   | `^[A-Z][A-Za-z]+_Create$`     | `event_key`, `calendar`, `grade`, `day`, `month`, `color`, `common`, `type` (const `"fixed"`) | `action: createNew`                      |
| `DecreeCreateNewMobile`  | `^[A-Z][A-Za-z]+_Create$`     | `event_key`, `calendar`, `grade`, `color`, `common`, `type` (const `"mobile"`), `strtotime`   | `action: createNew`                      |
| `DecreeSetPropertyGrade` | `^[A-Z][A-Za-z]+_Upgrade$`    | `event_key`, `calendar`, `grade`                                                              | `action: setProperty`, `property: grade` |
| `DecreeSetPropertyName`  | `^[A-Z][A-Za-z]+_NameChange$` | `event_key`, `calendar`                                                                       | `action: setProperty`, `property: name`  |
| `DecreeMakeDoctor`       | `^[A-Z][A-Za-z]+_Doctor$`     | `event_key`, `calendar`, `common`                                                             | `action: makeDoctor`                     |

Common to all five variants:

- Top-level required properties: `decree_id`, `decree_date` (format `date`), `decree_protocol`,
  `description`, `liturgical_event`, `metadata`. The shared scalars (`decree_date`,
  `decree_protocol`, `description`) are defined once in `definitions` and `$ref`'d from each
  variant.
- Metadata required properties: `action` (plus `property` for the `setProperty` variants),
  `since_year`, `url`. (`since_year` is currently optional in the schema but required by every
  metadata model and present in all 14 decrees.) Optional: `url_lang_map`, `urls_langs`.
  No `until_year` (no model or data support).
- `type` is the explicit fixed/mobile discriminator within `createNew`, alongside the structural
  requirement of `day`+`month` vs `strtotime`, matching `DecreeItem`'s dispatch logic.
- Scalar sub-schemas keep their existing `$ref`s to `CommonDef.json` (`EventKey`, `LitGrade`,
  `LitCommon`, `LitColor`, `Calendar`, `Month`, `Day`, `RelativeDateObject`).

Removed from the source schema:

- `name` and `readings` inside `liturgical_event` — they live in the i18n and lectionary sidecar
  files, never in `decrees.json`.
- Top-level `api_path` — response-only, never present in source files.
- The generic `LiturgicalEvent`, `Metadata`, and `MemorialFromDecreesAction` definitions —
  replaced by the per-action definitions.

### 2. Data cleanup (`decrees.json`)

Remove `"grade": 3` from `StMartha_NameChange.liturgical_event`. Nothing else. (The
whitespace-only reformatting currently uncommitted in the working tree is unrelated to this
design.)

### 3. `LitCalDecreeWritePayload.json`

Becomes a `oneOf` over five payload variants. Each variant:

- `$ref`s the source schema's per-action `liturgical_event` and `metadata` definitions and the
  per-action `decree_id` pattern;
- repeats the shared top-level properties via property-level `$ref`s (as today);
- adds the optional `i18n` and `readings` sidecar properties (shapes unchanged);
- is `additionalProperties: false`.

The PUT/PATCH sidecar matrix remains enforced by `DecreeWritePayloadGuard` (schema = structural,
guard = semantic).

### 4. `LitCalDecreesPath.json`

Stops wrapping the source schema; defines five response variants of its own (draft-07 forces the
duplication; scalar `$ref`s still point to `CommonDef.json` and the source schema's `DecreeURL` /
`DecreeLangs`). Differences from the source shapes, all verified against the live handler output:

- `api_path` required at top level, with the per-action suffix in its pattern.
- `name` required in `liturgical_event` for the name-bearing shapes (`createNew` ×2, `makeDoctor`,
  `setProperty`/`name`); absent for `setProperty`/`grade`.
- `readings` required in `liturgical_event` for the two `createNew` variants (merged in from the
  lectionary sidecar); absent elsewhere.
- Metadata: `urls_langs` not allowed (the models drop it); `url_lang_map` optional.

### 5. Validation and test impact

- `phpunit_tests/Schemas/SchemaValidationTest.php` (source corpus) passes once the StMartha
  `grade` is dropped.
- `phpunit_tests/Schemas/DecreeWritePayloadSchemaTest.php` is updated for the `oneOf` structure:
  per-action valid fixtures, plus negative cases (mismatched `decree_id` suffix, stray
  properties, missing `since_year`, wrong `property` const).
- New negative tests for the source schema: each action shape rejects properties belonging to the
  other shapes.
- Health checks validate `/decrees` responses against `LitCalDecreesPath.json`; the new response
  schema must hold against live handler output (shapes verified against the running API during
  design).
- `composer lint:openapi` — `openapi.json` references the decree schemas; verify the Redocly lint
  still passes and update any embedded copies of the decree shapes if present.

## Out of scope

- Deriving `urls_langs` at build time instead of storing it.
- Upgrading the JSON schema validation library to support draft 2019-09/2020-12.
- Any change to the sidecar i18n / lectionary file schemas (`LitCalTranslation.json`, lectionary
  schemas).
- The `moveEvent` action (commented out in `DecreeItem`, no data).
