# `/validations`: advertising what the API can check

Design for step A of [API#806](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/806) — the discovery
endpoint. Client counterpart: [UnitTestInterface#42](https://github.com/Liturgical-Calendar/UnitTestInterface/issues/42).

## Problem

Clients hardcode this API's on-disk layout. `UnitTestInterface/assets/js/index.js` and `resources.js` both embed
repo-relative paths into `jsondata/sourcedata/`, and those paths must match what the server's schema-resolution table
expects. Nothing detects divergence.

Every incident #806 cites is that one fault:

| Incident         | What broke                                                                                         |
|------------------|----------------------------------------------------------------------------------------------------|
| API#737 → UTI#38 | source data moved under `rite/roman/`; the client's copy of the layout had to follow "in lockstep" |
| API#795          | all twelve `.vscode` schema globs matched nothing — a third copy of the layout, never chased       |
| API#800          | Ambrosian temporale unvalidatable by any client: the data existed, no client listed it             |

Under this design the server advertises its inventory and the client sends an opaque id. No path crosses the wire, so
the whole class of breakage disappears.

## Scope

| In scope                                                                   | Out of scope                                                                  |
|----------------------------------------------------------------------------|-------------------------------------------------------------------------------|
| A `GET /validations` endpoint advertising static source-data files/folders | Per-calendar items — wider regions, nations, dioceses, tests                  |
| Collapsing the server's two schema-resolution vocabularies into one        | The API's own endpoints (`resourceDataChecks`)                                |
| A drift test that fails when data exists with no inventory entry           | Any change to the WebSocket protocol itself                                   |
| —                                                                          | The `hello` frame and `protocol` versioning (#806 section F)                  |
| —                                                                          | Client adoption, and the client-side scope predicate (UnitTestInterface#42)   |
| —                                                                          | A RiteSelect control on either page (client work; UnitTestInterface#42 / #39) |

### Why only static files

The runners check three different kinds of thing, and only one of them is the problem:

1. **Static source files and folders** — missal propriums for both rites, decrees, and their `i18n` directories. These
   are hardcoded in the clients *as filesystem paths*.
2. **Per-calendar source data** — wider regions, nations, dioceses, tests. The client already derives these at runtime
   from `/calendars` metadata. No path is embedded.
3. **The API's own endpoints** — `/calendars`, `/decrees`, `/tests`, `/events`, `/easter`, `/schemas`, `/missals`. The
   client builds these from its `ENDPOINTS` map. No path is embedded.

Only kind 1 caused #737/#38, #795 and #800. Advertising kinds 2 and 3 would add surface without removing duplication,
so this endpoint covers kind 1 only.

## Who consumes it, and how they differ

The two UnitTestInterface pages check overlapping sets for different reasons, and the inventory has to serve both.

**`resources.php` is exhaustive.** It checks every API path — `/calendars`, `/decrees`, `/tests`, `/events`,
`/easter`, `/schemas`, `/missals`, `/data`, plus the per-nation and per-diocese combinations — and the calendar source
files for *every* calendar the API supports. It cares about the whole surface, filtered only by the selected rite.

**`index.php` is calendar-scoped.** It checks the source files for the *selected* calendar, and runs the liturgical
accuracy tests. With no calendar selected it falls back to the General Roman Calendar, or to the Ambrosian calendar when
the Ambrosian rite is selected with no specific calendar.

Two consequences for this design:

1. **`rite` alone is not enough.** Two of the nine files are national missal editions — `propriumdesanctis_US_2011` and
   `propriumdesanctis_IT_1983` — and they are in scope for the USA and Italy calendars respectively, and nowhere else.
   A flat, rite-tagged list cannot express that, so items carry a `region` as well.
2. **Accuracy tests are filtered too**, by rite and by calendar: a test naming a calendar appears only for that calendar
   and rite; a test naming no calendar appears for any calendar, still filtered by rite. Those tests are kind 2 (they
   come from `/tests`), so they are outside this endpoint — but they are the reason the client's filtering predicate
   has to exist regardless of what this endpoint returns.

### A client-side prerequisite, not in this scope

Neither page has a RiteSelect dropdown yet, so today nothing selects a rite to filter by. UnitTestInterface#39
brought the calendar dropdown's options as far as carrying `data-rite`; the control itself, and the filtering it
drives, is client work tracked as **UnitTestInterface#48**, alongside #42. This endpoint supplies the data those
filters need; it does not depend on them, and it ships useful without them.

Worth knowing when reading #48: the client half is gated on adopting `@liturgical-calendar/components-js`, whose
`CalendarSelect` is rite-aware and whose `MetaComponents/CalendarControls` already wires a rite select to a calendar
select. The PHP components UnitTestInterface currently depends on render server-side, so they can supply the control
but not the wiring. Either way the gate is a client-side dependency decision, not this endpoint.

## The inventory

18 items: 9 files and their 9 `i18n` folders.

Half of them need not be written down at all.

### Derived — the Roman sanctorale (10 items)

`RomanMissal` already registers every missal edition and already knows which have a sanctorale file:
`getMissalIds()` returns eleven ids, and `getSanctoraleFileName()` returns a path for five of them
(`EDITIO_TYPICA_1970`, `EDITIO_TYPICA_2002`, `EDITIO_TYPICA_2008`, `US_2011`, `IT_1983`) and `false` for the other six.

Those five are **exactly** the five Roman sanctorale arms in today's hand-written `Health::getPathToSchemaFile()` table.
So the inventory derives them instead of restating them, together with their `i18n` folders via
`getSanctoraleI18nFilePath()`, their labels via `getName()`, and their `region` the same way `produceMetadata()` already
computes it. A new missal edition with a sanctorale file joins the inventory with no edit here.

### Explicit — four pairs (8 items)

These have dedicated `JsonData` constants rather than a registry to derive from:

| Item                      | Path constant                                | Schema                     |
|---------------------------|----------------------------------------------|----------------------------|
| Roman temporale           | `JsonData::TEMPORALE_FILE`                   | `PropriumDeTempore.json`   |
| Roman temporale i18n      | `JsonData::TEMPORALE_I18N_FOLDER`            | `LitCalTranslation.json`   |
| Roman decrees             | `JsonData::DECREES_FILE`                     | `LitCalDecreesSource.json` |
| Roman decrees i18n        | `JsonData::DECREES_I18N_FOLDER`              | `LitCalTranslation.json`   |
| Ambrosian temporale       | `JsonData::AMBROSIAN_TEMPORALE_FILE`         | `PropriumDeTempore.json`   |
| Ambrosian temporale i18n  | `JsonData::AMBROSIAN_TEMPORALE_I18N_FOLDER`  | `LitCalTranslation.json`   |
| Ambrosian sanctorale      | `JsonData::AMBROSIAN_SANCTORALE_FILE`        | `PropriumDeSanctis.json`   |
| Ambrosian sanctorale i18n | `JsonData::AMBROSIAN_SANCTORALE_I18N_FOLDER` | `LitCalTranslation.json`   |

Paths always come from `JsonData` cases, never from string literals. `JsonData` is already the single place the layout
is written down; this design stops *duplicating* it, it does not add a rival.

## Components

Three units, each with one responsibility.

**`src/Models/ValidationsPath/CheckableItem.php`** — one item. Readonly properties: `id`, `kind` (`file` or `folder`),
`rite`, `region`, `label`, `schema`, `steps`, and `path`. Implements `JsonSerializable` and **omits `path`** from its serialized
form: the server needs it to resolve a check, and no client may ever see it. That omission is the whole point of the
feature, so it belongs in the type rather than in the handler.

**`src/Models/ValidationsPath/CheckableInventory.php`** — assembles the list, derived plus explicit, and exposes
`all()`, `byId(string $id): ?CheckableItem`, and `byPath(string $path): ?CheckableItem`.

**`src/Handlers/ValidationsHandler.php`** — `GET /validations`, modelled on `SchemasHandler`, which is the smallest
handler in the codebase and already has the shape: preflight, `setAccessControlAllowOriginHeader()`,
`validateRequestMethod()`, `validateAcceptHeader()`, `encodeResponseBody()`. Serialization only.

Plus `Route::VALIDATIONS = '/validations'` and a `case 'validations'` in `Router::route()`.

### What `Health` loses

`Health` gains nothing. It *sheds*:

- `getPathToSchemaFile()` becomes `CheckableInventory::byPath($path)?->schema`.
- The `sourceDataCheck` slug branch — eight anchored `preg_match` calls — becomes `byId($id)?->schema` for the entries
  the inventory owns.

That collapses #806's ambiguity 4, where the same file resolves under two different vocabularies depending on which
page asks: `PropriumDeTempore` with `category: universalcalendar` from one runner, `proprium-de-tempore` with
`category: sourceDataCheck` from the other. One id, one lookup.

The slug branch's *calendar* patterns (`wider-region-…`, `national-calendar-…`, `diocesan-calendar-…`, `tests-…`) stay
where they are. They are kind 2, out of scope.

## Response

The house envelope is a `litcal_*` key — `/schemas` returns `litcal_schemas`, `/calendars` returns `litcal_metadata`:

```jsonc
{
  "litcal_validations": [
    {
      "id": "temporale:roman",
      "kind": "file",
      "rite": "roman",
      "region": null,
      "label": "Roman Proprium de Tempore",
      "schema": "PropriumDeTempore.json",
      "steps": ["exists", "parses", "validates"]
    },
    {
      "id": "sanctorale:roman:US_2011",
      "kind": "file",
      "rite": "roman",
      "region": "US",
      "label": "Roman Missal, USA edition (2011)",
      "schema": "PropriumDeSanctis.json",
      "steps": ["exists", "parses", "validates"]
    }
  ]
}
```

`steps` is `["exists", "parses", "validates"]` for every item today. It is carried per-item anyway because #806 section D
exists to delete the clients' four hardcoded `* 3` constants, and a per-item list is what lets them do that without
another shared constant.

**No `protocol` field.** #806's sketch has one, but versioning and capability negotiation are section F. Stamping a
version on one endpoint before that contract is designed is a guess, and a wrong guess would have to be supported.

### `region` semantics

`null` means the item applies to its whole rite. A two-letter nation code means it applies only to that nation's
calendar. So a client's scope test is one expression:

```javascript
const inScope = ( item, rite, nation ) =>
    item.rite === rite && ( item.region === null || item.region === nation );
```

This deliberately diverges from `RomanMissal::produceMetadata()`, which reports `'VA'` rather than `null` for the
*editiones typicae*. `'VA'` is a nation code, and using it as a scope marker only works because the General Roman
Calendar happens to be served under nation `VA`; applied to the Ambrosian items it would be simply false. `null` says
"not nation-specific" without borrowing a nation to say it.

### No query parameters

The endpoint takes none, and always returns the whole inventory. Both pages fetch it once at load and filter in the
browser, so changing the rite or calendar selection re-filters an array rather than making another request. That keeps
the two dropdowns instant and the response cacheable.

The obvious objection is that "is this item in scope" is server knowledge, and two runners implementing it
independently would recreate the drift #806 exists to end — in the two files that have already drifted apart from each
other. The answer is not to move the rule to the server but to write it **once** on the client: the predicate above
belongs in the shared `assets/js/wsProtocol.js` module, which both runners already import. One implementation, no extra
request.

## Deliberately not doing

**The endpoint does not touch the filesystem.** Advertising is not verification. `exists` is the first *check*, not a
precondition for being listed, so a missing file appears as a failed check in the UI rather than as a silent absence
from the list. That distinction is exactly how #800 stayed invisible: the Ambrosian data was present and no client
listed it. A list that quietly drops what it cannot stat reintroduces the same blindness from the other direction.

It also keeps the endpoint cheap and its output identical across environments.

## Error handling

| Condition                                  | Behaviour                                                                       |
|--------------------------------------------|---------------------------------------------------------------------------------|
| Non-GET request                            | 405 from `validateRequestMethod()`, as every read handler does                  |
| `OPTIONS`                                  | CORS preflight, handled before method validation                                |
| Unacceptable `Accept` header               | 406 from `validateAcceptHeader()` at `LAX` for GET                              |
| Any path parameter                         | 400 — `/validations` takes none                                                 |
| A registered missal has no sanctorale file | Not an error; `getSanctoraleFileName()` returns `false` and no item is produced |

The endpoint has no failure mode of its own: it serves a static structure and never reads a file.

## Testing

**Handler tests** (`phpunit_tests/Handlers/ValidationsHandlerTest.php`, extending `AbstractHandlerTestCase` — in-process
via direct `handle()`, no running server):

1. `GET` returns 200 with the `litcal_validations` envelope.
2. Every item carries `id`, `kind`, `rite`, `region`, `label`, `schema`, `steps`; `region` is `null` or a two-letter code.
3. **No item exposes `path`** — the serialized payload must not contain a `jsondata/` substring anywhere.
4. Ids are unique.
5. A non-GET verb is 405; a path parameter is 400.

**Inventory tests** (`phpunit_tests/Models/ValidationsPath/CheckableInventoryTest.php`):

1. `byPath()` returns the same schema for every path that today's `getPathToSchemaFile()` table maps — the table is
   pasted into the test as the oracle, so the refactor is proved equivalent rather than assumed.
2. `byId()` resolves the slugs the `sourceDataCheck` branch handles for inventory-owned entries.
3. The five Roman sanctorale editions are present and the six without a sanctorale file are absent.
4. `region` is `null` for the *editiones typicae* and for both Ambrosian items, `'US'` for `US_2011`, `'IT'` for
   `IT_1983` — the mapping the client's scope predicate depends on.
5. Applying that predicate to the inventory yields, for `('roman', 'USA')`, the universal Roman items plus `US_2011`
   and not `IT_1983`; and for `('ambrosian', null)`, exactly the four Ambrosian items.

**The drift test** — the one that earns its keep:

Walk `jsondata/sourcedata/rite/*/missals/*/` and `jsondata/sourcedata/rite/*/decrees/`, and assert every data file and
`i18n` directory found has an inventory entry. This converts silent divergence into a red test. It would have failed
the moment the Ambrosian data landed without a match-table entry, which is #800.

The inverse — an inventory entry with nothing on disk — is deliberately **not** asserted, for the reason given above:
that is the `exists` check's job, and a test asserting it would make the inventory unable to advertise data a
deployment is missing.

## Migration

Purely additive. No existing route changes, no client change is required to ship it, and the `Health` refactor is
behaviour-preserving with the old table as the test oracle. UnitTestInterface#42 adopts it separately, and can do so one
page at a time.
