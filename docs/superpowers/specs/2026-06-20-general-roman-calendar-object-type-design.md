# Design: `general_roman_calendar` Object Type for Calendar Editor Permissions

- **Date:** 2026-06-20
- **Status:** Draft (awaiting review)
- **Repos affected:** `LiturgicalCalendarAPI` (authoritative), `LiturgicalCalendarFrontend` (mirror/UI)
- **Related:** OpenFGA fine-grained authorization (`OpenFgaAuthorizationMiddleware`, `AccessRequestRepository`, `RoleCascadeService`)

## 1. Background

The permission system models editable resources as OpenFGA **object types**, each with the relations
`admin / viewer / editor / deleter`. Today the types are:

| Object type         | Object ID                | Write route guarded                         |
|---------------------|--------------------------|---------------------------------------------|
| `national_calendar` | nation code (e.g. `IT`)  | `/data/nation/{id}`                         |
| `diocesan_calendar` | diocese id               | `/data/diocese/{id}`                        |
| `wider_region`      | region name              | `/data/widerregion/{id}`                    |
| `test_definition`   | test id                  | `/tests/{id}`                               |

`VALID_OBJECT_TYPES` / `ROLE_OBJECT_TYPES` live in `AccessRequestRepository`; the FGA model is in
`scripts/openfga-model.json` (byte-identical copy in the Frontend repo); enforcement is wired per-route
in `Router::configureAuthorizationPipeline()` via `OpenFgaAuthorizationMiddleware`.

There is currently **no object type for the General Roman Calendar** (the base, universal calendar). Its
editable sub-resources are reached through routes that are either over-restricted or unguarded:

- `temporale` (PUT/PATCH/DELETE) — guarded by **admin-only** (`AuthorizationMiddleware::forAdmin()`).
- `missals` (PUT/PATCH/DELETE) — **not** in the authenticated/authorized route set at all.
- `decrees` (PUT/PATCH/DELETE) — **not** in the authenticated/authorized route set at all.

## 2. Goals

1. Add a new object type **`general_roman_calendar`** usable in Calendar Editor permission tuples.
2. A user with `editor` on the relevant `general_roman_calendar` object may edit:
   - the **Temporale**,
   - the **Sanctorale** of the Editio Typica editions (1970, 2002, 2008),
   - the **Decrees of the Dicastery for Divine Worship**.
3. Wire real enforcement on the `temporale`, `missals`, and `decrees` write routes.
4. Make national/regional missals (non-Editio-Typica) follow the **existing `national_calendar` grants**.

## 3. Non-goals

- Per-diocese or per-wider-region missals (none exist today).
- A management UI redesign — we extend the existing grant / request-access flows only.
- Changing the relation set (`admin/viewer/editor/deleter` is reused unchanged).

## 4. Design decisions (confirmed)

### 4.1 One object type, enumerated object IDs

`general_roman_calendar` is **not** a singleton and **not** free-text. It has a **fixed, enumerated set of
five object IDs**, each mapping to one editable sub-resource:

| UI label                                      | Object ID (FGA)        | Write route / target                          |
|-----------------------------------------------|------------------------|-----------------------------------------------|
| Temporale                                     | `temporale`            | `/temporale` (PUT/PATCH/DELETE)               |
| Sanctorale — Editio Typica 1970               | `EDITIO_TYPICA_1970`   | `/missals/EDITIO_TYPICA_1970`                 |
| Sanctorale — Editio Typica 2002               | `EDITIO_TYPICA_2002`   | `/missals/EDITIO_TYPICA_2002`                 |
| Sanctorale — Editio Typica 2008               | `EDITIO_TYPICA_2008`   | `/missals/EDITIO_TYPICA_2008`                 |
| Decrees of the Dicastery for Divine Worship   | `decrees`              | `/decrees` (PUT/PATCH/DELETE)                 |

The Sanctorale object IDs **reuse the `RomanMissal` edition identifiers verbatim**, so the `missals` write
route can use its path parameter directly as the FGA object ID with no translation layer. Editions 1971/1975
are excluded — they have no Sanctorale data file (`getSanctoraleFileName()` is `false`).

A new constant `AccessRequestRepository::GRC_OBJECT_IDS` enumerates the five valid IDs; grant/request
validation rejects any `general_roman_calendar` tuple whose object ID is not in this set.

### 4.2 Roles

`general_roman_calendar` is added to the allowed object types for:

- `calendar_editor` (per the requirement), and
- `developer` (which already holds every object type; keeps it a superset).

`test_editor` is unaffected.

### 4.3 Enforcement — route dispatch

`PUT/PATCH → editor`, `DELETE → deleter` (existing `RELATION_MAP`).

- **`temporale`** route: replace `forAdmin()` with `forCalendarEditor()` **+** FGA check
  `editor`/`deleter` on `general_roman_calendar:temporale`. (Loosens from admin-only.)
- **`decrees`** route: add to the authenticated+authorized route set; `forCalendarEditor()` **+** FGA check
  on `general_roman_calendar:decrees`. (Newly guarded.)
- **`missals`** route: add to the authenticated+authorized route set; `forCalendarEditor()` **+** a
  **dispatch by missal id**:
  - if `RomanMissal::isLatinMissal($missalId)` (Editio Typica) → object type `general_roman_calendar`,
    object id = `$missalId` (must be one of the three enumerated editions, else 403/404);
  - otherwise (national missal, e.g. `IT_1983`) → object type `national_calendar`, object id =
    the owning nation = `explode('_', $missalId)[0]`.

This makes national missals follow the **same grant** as the national calendar itself: `editor` on
`national_calendar:IT` authorizes editing both `/data/nation/IT` and `/missals/IT_1983`, `/missals/IT_2020`
(the ids found under that nation's `metadata.missals`). Admin users continue to bypass all FGA checks.

### 4.4 Object ID → nation resolution

The owning nation of a national missal is the id prefix (`IT_1983 → IT`), which is exactly how
`RomanMissal::produceMetadata()` already derives a missal's `region`. The authoritative cross-check is the
nation's `metadata.missals` array (e.g. `IT.json → metadata.missals: ["IT_1983","IT_2020"]`). The middleware
uses the prefix for the FGA object id; resolution does not require loading every national calendar.

## 5. Detailed changes

### 5.1 API — `LiturgicalCalendarAPI` (authoritative; lands first)

1. **`scripts/openfga-model.json`** — add a `general_roman_calendar` type definition mirroring the existing
   types (relations `admin/viewer/editor/deleter`, each `directly_related_user_types: [{ "type": "user" }]`).
2. **`src/Repositories/AccessRequestRepository.php`**
   - add `'general_roman_calendar'` to `VALID_OBJECT_TYPES`;
   - add it to `ROLE_OBJECT_TYPES['calendar_editor']` and `ROLE_OBJECT_TYPES['developer']`;
   - add `public const GRC_OBJECT_IDS = ['temporale','EDITIO_TYPICA_1970','EDITIO_TYPICA_2002','EDITIO_TYPICA_2008','decrees'];`
   - enforce, in tuple validation, that a `general_roman_calendar` object id ∈ `GRC_OBJECT_IDS`.
3. **`src/Http/Middleware/OpenFgaAuthorizationMiddleware.php`**
   - add a `forGeneralRomanCalendar($client, $fixedObjectId)` factory (for `temporale` / `decrees`);
   - add a `forMissals($client)` factory whose `objectType`/`objectId` are resolved per request from the
     missal id (ET → `general_roman_calendar:<edition>`, national → `national_calendar:<nation>`).
   - (Implementation note: this is the first route whose object **type** is dynamic; either generalize the
     middleware to accept a resolver closure, or add a dedicated missals middleware. Decide in the plan.)
4. **`src/Router.php`**
   - extend the protected-route guard (currently `['data','tests','temporale']`) to include
     `'missals'` and `'decrees'`;
   - in `configureAuthorizationPipeline()` add `missals` and `decrees` branches and change the `temporale`
     branch from `forAdmin()` to `forCalendarEditor()` + FGA, per §4.3;
   - set the resource-id request attribute(s) the middleware reads.
5. **`src/Services/RoleCascadeService.php`** — verify it cascades correctly for an object type whose object
   IDs are an enumerated set (it iterates `ROLE_OBJECT_TYPES[$role]`); add GRC handling if it assumes
   free-form ids.
6. **Tests** — middleware dispatch (temporale/decrees/ET-missal/national-missal/unknown-missal), validation
   of `GRC_OBJECT_IDS`, role-object-type mapping, and a cascade test.

### 5.2 Frontend — `LiturgicalCalendarFrontend` (mirror + UI; lands after API)

1. **`scripts/openfga-model.json`** — keep byte-identical with the API copy.
2. **`admin-permissions.php`** — add a `general_roman_calendar` `<option>` to `#grantObjectType`; add its
   display name to the i18n map.
3. **`assets/js/admin-permissions.js`** — when `general_roman_calendar` is selected, replace the free-text
   `#grantObjectId` input with a **dropdown** of the five enumerated IDs (label → id per §4.1); restore the
   free-text input for other types.
4. **`permission-requests.php`** and **`request-access.php`** — add `general_roman_calendar` to the
   `calendar_editor` role's object types, with the enumerated object-id choices.
5. **i18n** — add the new display strings (object type label + five object-id labels) to the gettext catalog.

## 6. Validation rules

- A `general_roman_calendar` tuple is valid only if object id ∈ `GRC_OBJECT_IDS`.
- On the `missals` write route, a Latin/Editio-Typica missal id outside the three Sanctorale editions
  (e.g. `EDITIO_TYPICA_1971`) is rejected (no editable Sanctorale).
- A national missal id with no resolvable nation prefix is rejected.

## 7. Rollout / sequencing

1. API PR (model + validation + enforcement + tests) → targets `LiturgicalCalendarAPI` `development`.
2. After the API model is deployed, run the OpenFGA model-update script (`scripts/setup-openfga.sh`) so the
   running store learns the new type before tuples are written.
3. Frontend PR (mirror model + UI) → targets `LiturgicalCalendarFrontend` `development`.

The model files in the two repos must remain byte-identical (existing invariant).

## 8. Risks

- **Behavior change on `temporale`:** existing admin-only editing is loosened to calendar_editor + FGA. Admins
  retain access (FGA bypass), so this is strictly additive for non-admin editors.
- **Newly guarded `missals`/`decrees`:** writes that were previously unauthenticated now require a token and
  the right grant. If any internal tooling wrote to these routes unauthenticated, it must be updated.
- **Dynamic object type on `missals`:** the middleware must resolve type+id per request; covered by tests.

## 9. Open questions

None blocking. The only implementation choice deferred to the plan is the exact mechanism for the dynamic
`missals` middleware (resolver closure vs dedicated subclass).
