# Design: let a national calendar belong to more than one wider region

- **Date:** 2026-09-28
- **Status:** Draft (awaiting review)
- **Issue:** #1005
- **Repos affected:** `LiturgicalCalendarAPI` (this design). `LiturgicalCalendarFrontend`, `liturgy-components-js` and
  `liturgy-components-php` follow in their own issues (section 11).

## 1. Background

A national calendar belongs to exactly one wider region: `metadata.wider_region` is a single string, and
`CalendarHandler` and `EventsHandler` each load exactly one wider region file for it. The Notitiae survey (#985,
epics #987 and #988) shows that the calendars shared by several nations are mostly not continents. They are the calendars of
multinational episcopal conferences and of language areas, and a nation can belong to one of those and to a
continent-wide region at the same time. Sweden is in Europe (its patrons) and in the Nordic conference (Eternal High
Priest 2012, John Paul II 2011, the Sts Peter and Paul transfer 2016). Germany is in Europe and in the German language
area. With one `wider_region`, neither can be modelled without copying the shared data into every member's national
calendar, which is what wider regions exist to avoid.

### 1.1 What the code already supports, and what it gets wrong

A read-only survey of every `wider_region` touchpoint changes the premises of the issue in three ways.

**The authorization side is already multi-region.** The OpenFGA model (`cdcf-infra`,
`auth/models/LiturgicalCalendar.json`) declares `wider_region.member_nation: [national_calendar]` with
`admin = this or admin from member_nation`. It is tuple-based, so a nation that has a tuple for each of several regions
already inherits admin on each. `WiderRegionMembership::regionsOf()` already returns a list, and
`OpenFgaAuthorizationMiddleware::forWiderRegionLocale()` already consumes it as one. Neither needs a new concept, only
a list to read.

**The wider region lectionary is never loaded.** The issue says the lectionary lookup "does the same" as the single
region load. It does something else: `CalendarHandler.php:5562` builds the "wider region lectionary" path from
`NATIONAL_CALENDAR_LECTIONARY_FILE` with `{nation}`, so it loads the nation's own lectionary a second time and never
reads `wider_regions/{region}/lectionary/{locale}.json`. Europe ships four such files (`en_UK`, `fr_FR`, `it_IT`,
`nl_NL`) that no request has ever used.

**Membership tuples drift from the source files.** Six defects, all in code this design replaces:

1. `RegionalDataHandler::updateNationalCalendar()` (PATCH) never writes or deletes a `member_nation` tuple, so a change
   of region leaves OpenFGA on the old one.
2. Deleting a nation never removes its `member_nation` tuples. `ResourceTuplePurgeService` reads tuples by object, and
   in this tuple the nation is the user.
3. A national PUT queued as a change request writes its `member_nation` tuple immediately, before anything has merged.
4. `WiderRegionMembershipSeeder` emits unqualified ids (`national_calendar:IT`, `wider_region:Europe`), while the
   handler writes rite-qualified ones (`national_calendar:roman/IT`). It also only adds, never removes.
5. `NationalData::hasWiderRegion()` is always true: it tests `property_exists()` on a property the class declares, so a
   nation without a region would try to load `wider_regions//.json`.
6. The allowed region names disagree in five places: the PHP model's regex (7 names), `WiderRegionCalendar.json`'s
   inline enum (5), `CommonDef.json`'s `WiderRegionNames` (17), the frontend's regex (5) and `CalendarsTest` (5).

## 2. Goals

- A national calendar declares an ordered list of wider regions, and a calendar is assembled from each of them in
  that order, then from the nation itself.
- Every nation that belongs to one region produces the same `/calendar` and `/events` output as before, except for the
  deliberate lectionary fix (section 5.3).
- OpenFGA `member_nation` tuples follow the published source files in every write mode.
- `/calendars` publishes each nation's list and each region's member nations, for Frontend #66.
- The API keeps working with today's frontend and component libraries until their follow-ups land.

## 3. Non-goals

- New wider region data (Nordic, Southern Africa, the German language area, …). This design delivers the model; the
  data follows from the Notitiae survey in its own issues.
- The `Asia` wider region's membership, name, grade and source: #1006.
- Nested regions (a region declaring a parent). Rejected in section 4.
- Frontend and component library changes: section 11.

## 4. Decision: a flat, ordered list on the nation

Each national calendar declares `metadata.wider_regions`, most general first, and that order is the order in which the
layers are applied:

```text
SE.json  metadata.wider_regions: ["Europe", "Nordic"]

General Roman Calendar + missal propria → Europe → Nordic → SE
```

**Rejected: nested regions.** A region declaring a parent (Nordic → Europe) would keep membership in one place, but a
region can have only one parent, so groupings that cross continents do not fit: the Indies of #1006 span Africa and
Asia. It would also need cycle detection and a parent-to-child admin inheritance in OpenFGA, which is a change to the
authorization model. The cost of the flat list is that Nordic's membership in Europe is recorded nowhere, so each Nordic
nation lists both.

**Rejected: merging the regions into one synthetic region at load time.** It would leave the handlers almost untouched,
but event names come from each region's own i18n file, and a merge loses which file a name came from. Two regions
defining the same event key would also shadow each other instead of layering.

### 4.1 Which record is authoritative

Membership is recorded in two places: the nation's `wider_regions` and each region's `national_calendars` map. They mean
different things:

- The **nation's list** decides which layers apply, in what order, and which `member_nation` tuples exist.
- The **region's map** is the roster of nations eligible to join, including nations with no calendar yet. Europe's map
  lists 29 nations, 3 of which have a calendar. It is what authorizes a nation's editor to add that nation's locale to
  the region (#999).

A nation may declare only a region whose map lists it. The reverse is not required: a map entry for a nation that does
not declare the region is a prospective member, not an inconsistency.

### 4.2 Region names

A declared region must exist, meaning it has a file at `wider_regions/{region}/{region}.json`. The schemas stop
enumerating names and check only their shape, so the five disagreeing lists (section 1.1, item 6) collapse into one
runtime rule, and a new region is created with `PUT /data/widerregion/{key}` without a schema edit.

The shape is one or more capitalised words separated by single spaces: `^[A-Z][A-Za-z]*( [A-Z][A-Za-z]*)*$`. It admits
every name in today's `WiderRegionNames` (`Middle East`, `Central America`, …).

## 5. Calendar assembly

### 5.1 `WiderRegionLayers`

A new unit, `src/Services/WiderRegionLayers.php`:

```php
/** @return list<WiderRegionLayer> in the nation's declared order */
public static function for(NationalData $nation, string $locale): array;
```

Each `WiderRegionLayer` is a readonly value object carrying:

- `region`: the region name;
- `data`: the loaded `WiderRegionData`, with names applied from that region's own `i18n/{locale}.json`;
- `lectionaryFile`: the path of `wider_regions/{region}/lectionary/{locale}.json`, or `null` when it does not exist.

`CalendarHandler` and `EventsHandler` both call it and replace their single `?WiderRegionData` with the list. Loading,
name application and lectionary resolution then live in one place instead of two, which is how the lectionary bug
arose.

### 5.2 Order

Every layer runs through the existing `handleNationalCalendarEvents()` path, in the declared order, before the
national calendar's own `litcal`. When two regions act on the same event, the later, more specific one acts last, and
the nation still acts after all of them. Names stay per layer: each region's i18n names only that region's `litcal`
items, as the single region does today.

### 5.3 Lectionary fix

Each layer's readings load from its own `lectionaryFile`. This is the one intended change to current output: Europe's
`it_IT` and `nl_NL` files start being used, so Italy and the Netherlands (and their dioceses) gain the Europe patrons'
readings. Croatia is unaffected: Europe has no `hr_HR` lectionary. Of the golden masters, `nation-IT-2023` and
`diocese-romamo-2023` (Rome inherits Italy) change and are regenerated deliberately, in their own commit, with the diff
described in the PR.

`Europe/lectionary/en_UK.json` matches no locale any nation declares (the ICU locale is `en_GB`). It is noted as a
follow-up, not renamed here.

### 5.4 Errors

- A missing region file, or a missing region i18n file for the request's locale, fails the request loudly, as a missing
  single region does today. A calendar quietly missing its Europe patrons would be wrong output.
- The message "Could not find a `wider_region` property in the `metadata`…" is removed: an empty list is now a
  legitimate state, not a defect.
- `NationalData::hasWiderRegion()` becomes a non-empty-list check (section 1.1, item 5).

## 6. Source data, schemas and input

### 6.1 Source files

The five shipped national files (CA, HR, IT, NL, US) are migrated from `wider_region` to `wider_regions` in this change,
so the corpus uses only the new form. An empty list means the nation belongs to no region.

### 6.2 `NationalMetadata`

`NationalMetadata::$wider_region` (`?string`) becomes `NationalMetadata::$wider_regions` (`list<string>`). `fromObject()`
and `fromArray()`:

- read `wider_regions` as given, rejecting duplicates and items that fail the name shape;
- read a legacy `wider_region` string as a one-element list;
- reject a payload carrying both fields.

A `usedLegacyWiderRegion` flag records which form was read, for the handler's deprecation message (section 6.4).

### 6.3 Schemas

- `CommonDef.json`: the 17-name `WiderRegionNames` enum becomes `WiderRegionName`, a string with the shape pattern of
  section 4.2.
- `NationalCalendar.json`: `metadata.wider_regions` is an array of `WiderRegionName` with `uniqueItems`. The legacy
  `wider_region` stays, marked deprecated. `required` lists neither, so either form, or none, is schema-valid, and the
  model enforces "not both".
- `WiderRegionCalendar.json`: the inline 5-name enum on `metadata.wider_region` (the region's own id) uses
  `WiderRegionName`.
- `LitCalMetadata.json`: see section 8.

### 6.4 Writes during the transition

On `PUT` and `PATCH /data/nation/{nation}`:

- Each declared region must exist and must list the nation in its `national_calendars` map, or the request is refused
  with **422**, naming the region and, for an unknown one, the known regions.
- A payload in the legacy form is accepted, and the response carries a deprecation message naming `wider_regions`.
- A `PATCH` in the legacy form to a nation whose stored file declares **two or more** regions is refused with **422**,
  telling the client to send `wider_regions`. Otherwise today's frontend, which sends one string, would drop Sweden's
  Nordic membership on every save.
- Whatever form was sent, the file is always written with `wider_regions`.

`checkWiderRegionCalendarConditions()` blocks deleting a region while any nation declares it. That check becomes
`in_array` over each nation's list.

## 7. Membership tuples: `WiderRegionMembershipSync`

A new unit, `src/Services/WiderRegionMembershipSync.php`:

```php
/**
 * @param list<string> $before the nation's regions before the write ([] on create)
 * @param list<string> $after  the nation's regions after the write ([] on delete)
 * @return list<array<string, mixed>> outbox rows
 */
public static function rowsFor(string $nation, array $before, array $after): array;
```

It returns a `WRITE_TUPLE` row for each region in `$after` but not `$before`, and a `DELETE_TUPLE` row for each region in
`$before` but not `$after`. A reorder produces no rows, since order has no meaning to OpenFGA. Both sides are
rite-qualified (`national_calendar:roman/SE member_nation wider_region:roman/Nordic`), and idempotency keys keep today's
`member_nation:wider_region:{R}:national_calendar:{N}` form, with a `delete:` prefix for removals.

### 7.1 Callers

| Operation     | `$before`                         | `$after`                  | Fixes            |
|---------------|-----------------------------------|---------------------------|------------------|
| PUT nation    | `[]`                              | the payload's list        |                  |
| PATCH nation  | the stored file's list            | the payload's list        | 1.1, item 1      |
| DELETE nation | the stored file's list            | `[]`                      | 1.1, item 2      |

### 7.2 When the tuples change

- **Change applied straight to disk** (`disposition` = `applied`): the rows are enqueued in the same transaction as
  today and processed synchronously when OpenFGA is configured.
- **Change queued as a change request:** nothing is enqueued at request time (section 1.1, item 3). `MergePollRunner`,
  which already purges a queued deletion's tuples once it merges, gains a matching step: when a merged batch touches a
  national calendar file, it syncs that nation's membership: `$after` is the list in the file now on `development`, and
  `$before` is read from the nation's current `member_nation` tuples in OpenFGA, so a merge that races another write
  still converges on the published file.

In both modes, access follows the published data.

### 7.3 Seeder

`WiderRegionMembershipSeeder` becomes a full reconcile from the source files. It reads every nation's `wider_regions`,
emits rite-qualified ids (section 1.1, item 4), and removes `member_nation` tuples that no file declares, including
today's unqualified ones. `scripts/seed-wider-region-membership.php` keeps its dry-run default and `--apply` flag. It is
also the one-off upgrade step: run once after deploy, to qualify and prune the existing tuples.

### 7.4 Unchanged

- The OpenFGA model.
- `WiderRegionMembership::regionsOf()` changes only to read the list, and `forWiderRegionLocale()` not at all.
- Region create, update and locale-PUT remain roster edits (section 4.1) and write no tuples.

## 8. `/calendars`

- Each nation always carries `wider_regions` (possibly `[]`). The deprecated `wider_region` string is still published,
  but only when the nation has exactly one region, so a client written for one region never sees a list truncated to
  one.
- Each region gains `national_calendars`: the codes of the nations that have a calendar and declare that region, in
  code order. This is the region → nation link Frontend #66 needs.
- `LitCalMetadata.json` gains both fields. `WiderRegionDef.api_path`'s pattern checks the shape of a name instead of
  hard-coding the 17 names.

## 9. Health

A new check covers the nation-to-region direction: each nation that declares a region with no file, or a region whose
`national_calendars` map does not list it, is reported by name. The reverse direction is not checked (section 4.1).

## 10. Published contract (OpenAPI)

- National PUT/PATCH bodies document `wider_regions`, the deprecated `wider_region`, and the new 422 cases of
  section 6.4.
- The `/calendars` response documents section 8's fields.
- The wider region path parameter `key` uses `WiderRegionName` instead of the enum.
- The examples for IT (`"wider_region": "Europe"`) and US (`"Americas"`) use `wider_regions`.
- The prose of `PUT /data/widerregion/{key}/{locale}` ("Membership is a region's own `national_calendars` or a
  national calendar's `metadata.wider_region`") names `wider_regions`.

## 11. Follow-up issues

Filed when this lands:

- **LiturgicalCalendarFrontend:** the extending page's single `#associatedWiderRegion` input becomes an ordered
  multi-select; `NationalCalendarPayload.js` sends `wider_regions`; `widerRegionEditRights.js` and
  `widerRegionForNation.js` treat a nation's regions as a list (`includes`, not `===`); the e2e specs.
- **liturgy-components-js:** `typedefs.js` gains `wider_regions` on the national calendar item and `national_calendars`
  on the region item.
- **liturgy-components-php:** `Models/Index/NationalCalendar.php` and `CalendarIndex.php` gain the same fields.
- **API:** `Europe/lectionary/en_UK.json` matches no declared locale (section 5.3).

The deprecated `wider_region` input and output are removed once all three client follow-ups have shipped, in their own
change.

## 12. Testing

TDD throughout. Tests that write source data work in a shadow copy of `jsondata/` (`ShadowProjectRootTrait`), never in
the working tree.

- **`WiderRegionLayers`:** layers in declared order; names applied per layer; the region lectionary path, and `null`
  when absent; a missing region file fails.
- **`WiderRegionMembershipSync`:** region added, removed, reordered (no rows), nation created, nation deleted.
- **`NationalMetadata`:** the legacy string read as a one-element list; both forms refused; duplicates refused; an item
  failing the name shape refused; order preserved.
- **`RegionalDataHandler`:**
  - PUT, PATCH and DELETE produce the rows of section 7.1;
  - an unknown region, and a region whose map lacks the nation, are refused with 422;
  - a legacy-form PATCH to a nation in two regions is refused with 422, and a legacy-form PATCH to a nation in one
    region is accepted with a deprecation message;
  - the stored file uses `wider_regions` whatever form was sent;
  - a queued write enqueues no rows;
  - deleting a region still declared by a nation is refused.
- **`MergePollRunner`:** a merged batch touching a national file syncs that nation's tuples; one that does not touch a
  national file does nothing.
- **Seeder:** qualifies unqualified tuples, adds missing ones, prunes undeclared ones; dry-run changes nothing.
- **Layer order, end to end:** in a shadow root, a synthetic `Nordic` region and a nation declaring
  `["Europe", "Nordic"]`. Nordic's action on an event Europe also acts on wins over Europe's, and the nation's wins over
  both. Run for `/calendar` and `/events`.
- **Golden masters:** `general-*` and `nation-US-2023` stay byte-identical. `nation-IT-2023` and `diocese-romamo-2023`
  are regenerated in their own commit for the lectionary fix, and the PR describes the diff. Clear `engineCache/`
  between runs.
- **`/calendars`:** `wider_regions` is always present; `wider_region` only with exactly one region; each region's
  `national_calendars` lists exactly the nations that declare it.
- **Health:** the new check reports a nation declaring an unknown region, and one missing from its region's map.

## 13. Risks

- **Old clients on nations with several regions.** Mitigated by section 6.4's 422 and section 8's single-region-only
  `wider_region`. No shipped nation has two regions yet, so nothing is affected until such data is entered, by which
  time the frontend follow-up should have shipped.
- **Tuple drift during the upgrade.** Until the reconcile of section 7.3 runs, OpenFGA still holds today's unqualified
  seeder tuples alongside the handler's qualified ones. The upgrade runbook runs the seeder with `--apply` once after
  deploy; its dry-run shows the changes first.
- **Output change for Europe's nations.** Intended (section 5.3), confined to readings, and isolated in one commit so
  it can be reviewed on its own.
