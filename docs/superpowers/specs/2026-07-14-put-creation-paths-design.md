# Design: RFC 9110-aligned resource creation paths (issue #706)

Date: 2026-07-14
Status: Approved
Issue: [#706](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/706)

## Problem

Write-enabled endpoints are inconsistent about where the resource identifier lives on creation.
PATCH and DELETE address resources by path everywhere, but PUT creations carry the id in the
request body (`PUT /tests` with body `name`, `PUT /data/{category}` with the key in body
metadata). Per RFC 9110, `PUT` targets the resource's own URI (create-or-replace at a known
location, idempotent). The id-in-body shape is also the reason `OpenFgaAuthorizationMiddleware`
is skipped entirely on those creates (the Router cannot populate the `test_id`/`calendar_id`
request attribute from the path), leaving creates gated by role only.

`DecreesHandler` (PR #708) already implements the target pattern and serves as the template:
`PUT /decrees/{decree_id}`, path id authoritative, body id must match path, 409 on conflict,
201 on create.

## Decisions

| Decision            | Choice                                                                     |
|---------------------|----------------------------------------------------------------------------|
| Rollout             | Hard cutover; no deprecated aliases. Old collection-level PUT returns 405. |
| Missals scope       | Route shape only: PUT accepted at `/missals/{missal_id}`, still 405 stub.  |
| Tests conflict code | Align to 409 Conflict (was 422).                                           |
| README              | Brief RFC 9110 note in the API description (see below).                    |
| PR structure        | One API PR + one Frontend PR, merged API-first the same day.               |

## Target shapes

| Endpoint     | New create shape                              | Old shape fate                |
|--------------|-----------------------------------------------|-------------------------------|
| `/tests`     | `PUT /tests/{test_name}`                      | `PUT /tests` → 405            |
| `/data`      | `PUT /data/{category}/{key}`                  | `PUT /data/{category}` → 400* |
| `/missals`   | `PUT /missals/{missal_id}` (405 stub for now) | `PUT /missals` → 405          |
| `/temporale` | unchanged (singleton bulk payload)            | —                             |

\* `RegionalDataHandler` validates the request path before the request method, so the legacy `/data` create shape yields a
400 `ValidationException` ("Expected two path params…") rather than a 405. Reordering the handler's validation to force a
405 would change its documented GET/POST arity errors, so the 400 is accepted as the legacy-shape response for `/data`.

## API changes

### Router (`src/Router.php`)

- **tests**: 0 segments → `GET/POST`; 1 segment → `GET/POST/PUT/PATCH/DELETE`. The existing
  count>=1 branch that sets the `test_id` request attribute and applies
  `OpenFgaAuthorizationMiddleware::forTestScopes` now covers create.
- **data**: 1 segment → no write methods (405); 2 segments → `GET/POST/PUT/PATCH/DELETE`.
  The count>=2 branch that sets `calendar_id` from `pathParts[1]` and applies
  `forCalendarData` now covers create.
- **missals**: PUT moves from 0 segments to 1 segment; the collection-level `forAdmin`
  fail-closed fallback becomes dead code and is removed.

**Flagged behavior change — FGA on create:** the FGA check now runs against an object that may
not exist yet (e.g. `editor` on `national_calendar:XYZ` before the file exists). OpenFGA tuples
are independent of resource files, so grants provisioned via the access-request flow still
work, and the `admin` role bypasses; but a plain `calendar_editor` with no object tuple will
now receive 403 on create where the previous role-only check passed. This is the intended
tightening of #706, must be covered by explicit tests, and must be confirmed against the RBAC
model's intent (dev store currently on the ADDITIVE model).

### Handlers

- **TestsHandler::handlePutRequest**: require exactly 1 path param; path `test_name` is
  authoritative; body `name` must match path (mirroring the existing PATCH check and
  `DecreesHandler::requireValidatedPayload`); "already exists" becomes 409 `ConflictException`
  (was 422) with a "use PATCH" hint; still 201 on create.
- **RegionalDataHandler**: `validateRequestPath` requires exactly 2 path params for PUT; the
  payload-derived key (`metadata.diocese_id` / `nation` / `wider_region`) must match the path
  key — same 422 mismatch error PATCH already uses; conflict stays 409, create stays 201.
- **MissalsHandler**: untouched (stubs remain; only the route arity changes).

### OpenAPI (`jsondata/schemas/openapi.json`)

Move the `put` operations from `/data/nation|diocese|widerregion` and `/tests` to the
corresponding `/{...}/{key}` and `/tests/{test_name}` path items; document 409 responses;
remove the collection-level PUTs. Missals writes stay undocumented (still stubs).
`composer lint:openapi` must pass.

### README

Brief note in the API description: HTTP method semantics follow RFC 9110 — `PUT` is
create-or-replace at the resource's own URI (idempotent, 409 when creating over an existing
resource); `PATCH`/`DELETE` address resources by path. Note that this API deliberately uses
`POST` on read endpoints as a body-parameterized synonym of `GET` (not as collection-create),
pending possible adoption of the `QUERY` method when it becomes standard.

## Tests

- Update `phpunit_tests/Handlers/TestsHandlerTest.php`, `RegionalDataHandlerTest.php`, and
  `phpunit_tests/Routes/ReadWrite/RegionalDataTest.php` to the new shapes, using
  `DecreesHandlerWriteTest.php` as the pattern.
- Explicit assertions: old collection-level PUT yields 405 (tests/missals) or 400 (data — see Target shapes note); path/body id mismatch yields 422;
  duplicate create yields 409; FGA-on-create behavior (grant present → allowed, absent → 403,
  admin bypass); missals route shape (PUT `/missals/{id}` → 405 stub, PUT `/missals` → 405
  method-not-allowed).
- Full gate: `composer test`, `composer analyse`, `composer lint`, `composer lint:openapi`.

## Frontend PR (LiturgicalCalendarFrontend)

- `assets/js/admin-tests.js` (~line 781): create becomes
  `PUT /tests/${encodeURIComponent(name)}`; its `err.status === 409` branch already exists.
- `assets/js/extending.js`: the `API.path` proxy currently strips the key from PUT URLs in
  four case branches — update so PUT builds `${RegionalDataUrl}/${category}/${key}` like the
  other verbs.
- `assets/js/missals-editor.js`: verify only — it already PUTs to a per-file endpoint and
  handles the 405 stub.
- Frontend e2e builds `litcal-api` from `development`, so merging the API PR first lets the
  frontend PR's e2e validate the new shapes.

## Process

- API work in a git worktree (`.claude/worktrees/feat-put-creation-paths`, branch
  `feat/put-creation-paths` off `development`); the main checkout is shared with concurrent
  agents and must not be committed in.
- API PR targets `development` and closes #706 (`Closes #706`).
- README RFC 9110 note lands in the same API PR (it documents the state after the change).
- Frontend changes in their own branch/PR in the Frontend repo, merged after the API PR.
