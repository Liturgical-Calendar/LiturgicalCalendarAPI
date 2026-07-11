# Decrees Write Paths (PUT/PATCH/DELETE) — Design

**Date:** 2026-07-11
**Status:** Approved design, pending implementation
**Repos:** LiturgicalCalendarAPI (primary), LiturgicalCalendarFrontend (coordinated)
**Related issues:** [#706](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/706) (path-shape alignment)

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

## File mutations

All writes touch files under `jsondata/sourcedata/decrees/`, using `JsonFormatter::encode()` + `LOCK_EX`
(temporale convention, diff-friendly formatting):

- **`decrees.json`** (single database, deliberate single-file design to minimize disk I/O):
  PUT appends; PATCH replaces the matching entry in place; DELETE removes it.
- **`i18n/{locale}.json`**: for name-bearing actions, provided translations are written under the
  `event_key`; every existing locale file receives the key, with an empty-string placeholder where no
  translation was provided (Weblate fills these later).
- **`lectionary/{locale}.json`**: the `readings` sidecar is distributed the same way.

**DELETE garbage collection**: the decree's `event_key` is removed from `i18n/*.json` and
`lectionary/*.json` **only when no surviving decree still references that key** (keys are shared across
decrees). No FGA tuple purge is performed: permissions attach to the collection object
`general_roman_calendar:decrees`, never to individual decrees.

**Audit logging**: every write emits an audit-log entry (operation, `decree_id`, user sub, client IP,
files touched), following `RegionalDataHandler`'s pattern.

## GET enrichment

`GET /decrees` and `GET /decrees/{decree_id}` gain a `readings` property per decree, resolved from
`lectionary/{locale}.json` for the request locale with base-locale fallback (temporale's resolution
ladder). The localized event `name` already works; no other read-path changes.

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

**Page migration.** `decrees.php` is migrated to `admin-decrees.php` and removed; navigation updates
accordingly. Public consumption of decree data continues via the API.

**Visibility and capability gating.**

- Dashboard card and page access: `isAdmin || (hasRole('calendar_editor') && FGA viewer-or-above on
  general_roman_calendar:decrees)`. Without this, the card does not render on `admin-dashboard.php`.
- Capability detection on page load via existing `GET /admin/permissions/check`:
  viewers get the read-only enriched view; editors get create/edit; resource admins additionally get
  delete and a "manage permissions" link deep-linking into `admin-permissions.php` filtered to this
  resource. No new permission UI is built.

**Enriched viewing** (gaps in the old `decrees.php`): localized event name plus expandable list of all
`i18n` translations; lectionary readings (from the new `readings` property); full liturgical-event
details — `month`/`day` for fixed events or human-rendered `strtotime` for mobile ones, `grade`,
`color`, `type`, `common`; decree metadata (date, protocol, per-locale source URL).

**Editor.** Modal-based CRUD cloned from the `admin-tests.js` pattern: shared `fetchJson` with
`credentials: 'include'`, 15-second abort, modal alerts. The form is action-driven — selecting
`createNew` / `setProperty→grade` / `setProperty→name` / `makeDoctor` reveals exactly the fields the
payload matrix allows (fixed-vs-mobile toggle, grade/color/common selectors, i18n name inputs with the
current locale required, readings inputs per the matrix, decree metadata fields). Client-side checks
mirror the server matrix for fast feedback; the server remains authoritative. Page strings use the
standard gettext-into-`window.Config` i18n pattern.

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
