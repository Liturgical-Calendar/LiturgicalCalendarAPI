# Decrees Write Paths (PUT/PATCH/DELETE) — Design

**Date:** 2026-07-11 (revised 2026-07-12)
**Status:** Implemented (API #708, Frontend #400 in review)
**Repos:** LiturgicalCalendarAPI (primary), LiturgicalCalendarFrontend (coordinated)
**Related issues:** [#706](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/706) (path-shape alignment)
**Companion spec:** `LiturgicalCalendarFrontend/docs/superpowers/specs/2026-07-12-admin-decrees-interface-design.md`
(the admin-decrees interface behaviours, refined from all Frontend #400 commits)

> **Revision note (2026-07-12).** Beyond the original write-path design, implementation added two API-contract
> refinements captured below: the `url_lang_map` schema was loosened to any ISO 639-1 language
> (see *Metadata: source URL and `url_lang_map`*), and the single-decree GET was enriched to return the full
> per-locale translation and readings sets (see *GET enrichment*). These let the frontend editor prefill every
> defined translation in one request.

## Motivation

The `/decrees` endpoint currently serves GET only; PUT/PATCH/DELETE are 405 stubs in `DecreesHandler`.
Decree definitions (Dicastery decrees affecting the General Roman Calendar) can only be changed by editing
`jsondata/sourcedata/decrees/` files by hand. This design adds authenticated, permission-gated write paths
and a coordinated frontend admin page, following the proven patterns of the `/temporale` and `/tests`
write paths.

## Authorization

Access is gated in two layers, both already implemented platform-wide:

1. **JWT authentication** (`JwtAuthMiddleware`): HttpOnly cookie preferred, `Authorization: Bearer` fallback.
2. **Role + OpenFGA** (`AuthorizationMiddleware::forCalendarEditor()` then
   `OpenFgaAuthorizationMiddleware::forGeneralRomanCalendar($fgaClient, 'decrees')`):
   the FGA object is `general_roman_calendar:decrees`.

**Relation map** (deviates from the platform default; matches the tests-endpoint map, per approved decision):

| Method | Required FGA relation           |
|--------|---------------------------------|
| PUT    | `editor`                        |
| PATCH  | `editor`                        |
| DELETE | `admin`                         |

- Global admins (Zitadel `admin` role) bypass FGA checks entirely (existing middleware behavior).
- The FGA model's union semantics mean `admin` holders satisfy `editor` checks; no model changes needed.
- **Permission granting requires no new API surface**: `PermissionAdminHandler`
  (`POST/DELETE /admin/permissions`) already allows global admins and holders of the `admin` relation on
  `general_roman_calendar:decrees` to grant/revoke `viewer`/`editor`/`admin` on that resource.
  This satisfies "grc admins can grant permissions; editors cannot".
- **`GET /decrees` remains public** (approved decision): decree data is published magisterial record;
  anonymous API consumers and component libraries are unaffected. The `viewer` relation gates only
  frontend admin-page visibility.

## Routing and path shape

The Router's `decrees` case is extended (write methods move from the collection root to per-item paths):

| Route                        | Methods            | Auth      |
|------------------------------|--------------------|-----------|
| `/decrees`                   | GET                | public    |
| `/decrees/{decree_id}`       | GET                | public    |
| `/decrees/{decree_id}`       | PUT, PATCH, DELETE | JWT + FGA |

- The `decree_id` in the URL is authoritative. If the body carries `decree_id`, it must match the URL
  or the request fails with 400.
- This debuts the per-item creation shape proposed in
  [#706](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/706); other endpoints
  (`/tests`, `/missals`, `/data`) will be aligned separately.

## Payload shape and validation

PUT and PATCH accept a single decree object matching the `LitCalDecree` definition of
`jsondata/schemas/LitCalDecreesSource.json` (`decree_id`, `decree_date`, `decree_protocol`, `description`,
`liturgical_event`, `metadata`), extended with two **write-only sidecar properties**:

- **`i18n`** — object mapping base locale → translated liturgical event name.
- **`readings`** — object mapping base locale → readings object
  (`first_reading`, `responsorial_psalm`, optional `second_reading`, `gospel_acclamation`, `gospel`),
  the shape used in `jsondata/sourcedata/decrees/lectionary/{locale}.json`.

**Per-action requirement matrix** (approved; the `i18n` column applies equally to PUT and PATCH, since
PATCH replaces the stored entry and its i18n distribution):

| `metadata.action`           | `i18n`               | `readings`                            |
|-----------------------------|----------------------|---------------------------------------|
| `createNew`                 | required (≥1 entry)  | required on PUT, optional on PATCH    |
| `makeDoctor`                | required (≥1 entry)  | rejected on PUT (400), optional PATCH |
| `setProperty` (`name`)      | required (≥1 entry)  | rejected on PUT (400), optional PATCH |
| `setProperty` (`grade`)     | rejected (400)       | rejected on PUT (400), optional PATCH |

- When `i18n` is required, it must include the **Accept-Language base locale** entry
  (temporale parity: the creating client immediately sees what it created in its own locale).
- Sidecar locales must be valid base locales (no regional identifiers), validated against `LitLocale`.
- `description` is intentionally single-language (whatever language it was authored in); it is not part
  of the i18n sidecar and is never translated.

**Validation layers** (all reusing existing machinery):

1. **JSON schema**: a new single-decree write-payload schema (referencing `LitCalDecreesSource.json`
   definitions, plus the sidecar properties), validated the way `RegionalDataHandler` validates payloads.
   Registered with the schema corpus so `SchemaValidationTest` covers it.
2. **DTO invariants**: `DecreeItem::fromObject()` enforces the per-action shapes already coded in
   `DecreeItemCreateNewFixed`/`Mobile`, `DecreeItemMakeDoctor`, `DecreeItemSetPropertyGrade`/`Name`
   (fixed events need `day`+`month`, mobile need `strtotime`, `setProperty` needs `property`, etc.).
3. **Handler checks**: URL/body `decree_id` match; PUT conflict (409) / PATCH-DELETE existence (404);
   Accept-Language locale present in `i18n` when required; per-action sidecar matrix.

## Metadata: source URL and `url_lang_map`

A decree's `metadata.url` is the link to the published Vatican document. Vatican URLs are often language-specific
and do **not** follow ISO/BCP-47 conventions (e.g. `ge` for German, `po`/`portD`/`portoghese` for Portuguese),
so the metadata supports:

- **`url`** — the source URL. It may contain a single `%s` placeholder where the Vatican language token appears.
- **`url_lang_map`** — an object mapping ISO 639-1 language code → Vatican URL token
  (e.g. `{"de":"ge","pt":"po"}`). At read time, `%s` is substituted with the token for the requested locale
  (`DecreeEventMetadata::getUrl()`); the raw `%s` form is preserved in the JSON body so an edit round-trips.
- **`urls_langs`** — a derived/computed field (per-language expanded URLs); not authored directly.

**Schema (`DecreeLangs`) is intentionally open.** The original schema hard-coded eight languages, each with an
enum of the Vatican tokens seen in existing decrees — a snapshot, not a rule. Because new decrees may be in any
language with unpredictable tokens, `DecreeLangs` was loosened to `propertyNames: ^[a-z]{2}$` (any ISO 639-1
key) mapping to any non-empty string token. Existing data still validates. Enumerating known tokens is a
*frontend suggestion* concern (see the companion spec), never a server constraint.

## File mutations

All writes touch files under `jsondata/sourcedata/decrees/`, using `JsonFormatter::encode()` + `LOCK_EX`
(temporale convention, diff-friendly formatting):

- **`decrees.json`** (single database, deliberate single-file design to minimize disk I/O):
  PUT appends; PATCH replaces the matching entry in place; DELETE removes it.
- **`i18n/{locale}.json`**: for name-bearing actions, provided translations are written under the
  `event_key`. Locales supplied in the `i18n` map receive the provided string; locale files NOT in the
  map keep their existing translation when the key already exists, and receive an empty-string
  placeholder only when the key is new to that file (Weblate fills placeholders later).
- **`lectionary/{locale}.json`**: the `readings` sidecar is distributed ONLY to the locales supplied
  in the `readings` map; other locale files are not touched, and no placeholders are created.

**DELETE garbage collection**: the decree's `event_key` is removed from `i18n/*.json` and
`lectionary/*.json` **only when no surviving decree still references that key** (keys are shared across
decrees). No FGA tuple purge is performed: permissions attach to the collection object
`general_roman_calendar:decrees`, never to individual decrees.

**Audit logging**: every write emits an audit-log entry (operation, `decree_id`, user sub, client IP,
files touched), following `RegionalDataHandler`'s pattern.

## GET enrichment

**Both list and single GET** resolve, for the request locale (base-locale fallback), the localized event
`name` and a per-decree `liturgical_event.readings` object from `lectionary/{locale}.json`.

**The single-decree GET additionally aggregates the full translation and readings sets** so the admin editor
can prefill every defined translation in one request. `GET /decrees/{decree_id}` returns, alongside the
localized decree:

- **`i18n`** — object mapping locale → translated name, gathered from every `i18n/{locale}.json` file,
  **excluding empty strings** (a locale with no translation is omitted).
- **`readings`** — object mapping locale → readings object, gathered from every `lectionary/{locale}.json`
  file, **including only locales whose readings have at least one non-empty field**.

These two maps mirror the PUT/PATCH write-body shape exactly, so the single-decree GET response is the same
shape one would submit back — a deliberate read/write symmetry. The list GET stays lean (request-locale only)
to avoid bloating a multi-decree response. Documented in `openapi.json` via `allOf` (LitCalDecree + the two
maps).

## Error handling

| Case                                                                    | Status  |
|-------------------------------------------------------------------------|---------|
| Missing/invalid JWT                                                     | 401     |
| Missing `calendar_editor` role, or FGA check fails                      | 403     |
| Schema violation, id mismatch, sidecar matrix violation, invalid locale | 400     |
| PATCH/DELETE on unknown `decree_id`                                     | 404     |
| PUT on existing `decree_id`                                             | 409     |
| Unsupported content type / unacceptable Accept                          | 415/406 |
| File I/O failure                                                        | 503     |

Success responses: `201 Created` for PUT (echoing the stored decree); `200 OK` with a success body for
PATCH and DELETE (RFC 9110 §9.3.5).

## Testing

- **Pure-logic tests** (plain `TestCase`): per-action sidecar matrix, DTO invariants for write payloads.
- **Handler tests** (`AbstractHandlerTestCase`): method-support regression (replacing
  `testPutIsNotImplemented`), validation failures, URL/body id mismatch — in-process, no server.
- **Route tests** (`Routes/ReadWrite/DecreesTest`, `ApiTestCase`, modeled on `TemporaleTest`):
  401 unauthenticated, 409 conflict, 400 malformed, 404 unknown id (all non-mutating), plus one
  net-zero lifecycle test (create synthetic decree → PATCH → DELETE, cleanup in `finally`).
- **Schema corpus**: `SchemaValidationTest` picks up the new write-payload schema.
- **OpenAPI**: new path items for `/decrees/{decree_id}` write methods, write-payload component,
  security annotations matching other protected routes; validated by `composer lint:openapi`.

## Frontend (LiturgicalCalendarFrontend)

The admin-decrees interface (`admin-decrees.php` + `assets/js/admin-decrees.js`) replaces the public
`decrees.php`. Its objectives and behaviours — capability gating, the enriched read-only cards, and the
action-driven editor modal with the derived decree_id, the multilingual source-URL editor, and the
all-locales translation/readings prefill — are specified in full in the companion document so the interface
is reproducible from scratch:

**→ `LiturgicalCalendarFrontend/docs/superpowers/specs/2026-07-12-admin-decrees-interface-design.md`**

Key API-contract dependencies the interface relies on (summarised here, detailed there):

- `GET /decrees` list fetched with `Accept-Language` = the page UI locale, so card names and locale labels agree.
- `GET /decrees/{decree_id}` returns the aggregated `i18n` + `readings` maps (above); the editor prefills from
  them in one request, with a per-locale probing fallback for older API deployments.
- Public reads use `credentials: 'omit'` (the endpoint serves a wildcard `Access-Control-Allow-Origin`,
  which browsers reject on credentialed requests); write requests use `credentials: 'include'` (cookie JWT).
- Capability detection via `GET /admin/permissions/check` (self-check exemption); the FGA relation tiers
  (`viewer`/`editor`/`admin`) map to view / create-edit / delete + grant.

## Out of scope

- Localizing decree `description` (single-language by design).
- Path-shape alignment of `/tests`, `/missals`, `/data` creates
  ([#706](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/706)).
- UnitTestInterface: not affected (its admin interface is slated for removal).
- Per-decree FGA objects or new FGA model changes: none needed.

## Decisions log

| Decision                              | Choice                                                        |
|---------------------------------------|---------------------------------------------------------------|
| Relation map                          | PUT/PATCH → `editor`, DELETE → `admin` (tests-endpoint map)   |
| Path shape                            | Per-item `PUT/PATCH/DELETE /decrees/{decree_id}`              |
| i18n in payload                       | Per-action matrix; Accept-Language base locale entry required |
| readings in payload                   | Required on `createNew` PUT; optional on PATCH                |
| `GET /decrees` access                 | Public (viewer relation gates only frontend visibility)       |
| Granting                              | Existing `PermissionAdminHandler`; no new surface             |
| decrees.php                           | Migrated to gated `admin-decrees.php`, public page removed    |
| `DecreeLangs` schema (`url_lang_map`) | Loosened to any ISO 639-1 key → any token (was closed enum)   |
| Single-decree GET                     | Returns full `i18n` + `readings` maps (write-body symmetry)   |
| List GET                              | Stays lean (request-locale only) to avoid response bloat      |
