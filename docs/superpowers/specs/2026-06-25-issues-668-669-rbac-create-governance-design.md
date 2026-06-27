# RBAC create-governance + admin-superset (#668 + #669)

- **Date:** 2026-06-25
- **Issues:** [#668] (drop `deleter`, cascade-revoke on delete), [#669] (create-governance)
- **Branch:** `feat/rbac-create-governance`
- **Status:** Approved design (pending spec review)

[#668]: https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/668
[#669]: https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/669

## Summary

Two coupled authorization-model changes, delivered on one coordinated branch (mirroring the
calendar-scoped-tests pass in PR #666):

1. **Make `admin` a proper superset.** Drop the standalone `deleter` relation; `DELETE`
   authorizes against `admin`. Add OpenFGA union rewrites so `admin` implies `editor` and
   `viewer` (and therefore delete). (#668 Part 1.)
2. **Resource-type-aware create authz + create-governance.** `PUT` (= create in this API) maps
   to `admin` for calendar/missal/GRC resources and to `editor` for tests. A user requests the
   `admin` relation on a (possibly not-yet-existing) national calendar; `admin` authorizes
   creating and seeding it. (#669 Parts 1–4.)
3. **Cascade-revoke operational tuples on resource deletion.** When a calendar/test resource is
   deleted, purge its `editor`/`viewer` tuples via the transactional outbox, **retaining
   `admin`** (governance survives data deletion). A periodic reconciler sweep provides
   defense-in-depth. (#668 Part 2.)
4. **`wider_region` create-governance via membership.** A user who is `admin` of any national
   calendar in a wider region inherits `admin` on that wider region through a `member_nation`
   tuple-to-userset rewrite — no approval flow needed.

### Decisions locked during brainstorming

- **Scope/sequencing:** one coordinated branch + spec for both issues; single staged model
  migration.
- **#668 delete model:** Option A — drop `deleter` entirely; `DELETE → admin` (delete is
  admin-only; editor gets no delete sub-capability).
- **Relation hierarchy:** union rewrites in the model (`editor = this or admin`,
  `viewer = this or editor or admin`).
- **Governance role:** reuse `calendar_editor` — it may request the `admin` relation; approval by
  a system admin is the control. No new Zitadel role.
- **National missal coupling:** one tuple — `national_calendar:<N>#admin` already authorizes
  national missal `PUT`s via the existing `forMissals → national_calendar:<nation>` mapping. No
  redundant missal tuple.
- **Create scope:** `national_calendar` (incl. `VA`), `general_roman_calendar`, missals, tests,
  **and `wider_region`** (via TTU). Diocesan deferred to a follow-up.
- **`wider_region` authority:** full admin via TTU — `member_nation` admins fold into
  `wider_region#admin` (create + edit + delete). No extra capability relation.
- **Reconciler sweep:** included in this branch.

### Out of scope (follow-up issues)

- Diocesan create-governance (the "national admin authorizes diocesan create" cross-resource
  rule).
- GRC/Latin-missal seeding governance (Editio Typica editions resolve to
  `general_roman_calendar:*`, which is GRC governance, not national).
- The frontend `deleter`-removal PR (separate repo; coordinated, see Rollout).

## Current state (verified)

- **Model** (`scripts/openfga-model.json`): flat. Every resource type — `national_calendar`,
  `diocesan_calendar`, `wider_region`, `general_roman_calendar`, `test_definition`, and the three
  scoped `*_calendar_test` types — defines `admin`/`viewer`/`editor`/`deleter` as independent
  direct (`{"this":{}}`) relations. `admin` does **not** imply anything.
- **Middleware** (`src/Http/Middleware/OpenFgaAuthorizationMiddleware.php`): a shared
  `private const RELATION_MAP = ['PUT'=>'editor','PATCH'=>'editor','DELETE'=>'deleter']` applied
  uniformly (`self::RELATION_MAP[$method]`). Zitadel-`admin` users bypass FGA entirely. Factories
  `forCalendarData`, `forTestScopes`, `forGeneralRomanCalendar`, `forMissals` set object type /
  resolver but all share the one map. `forMissals` maps a national missal id to the FGA object
  `national_calendar:<nation>`; a Latin missal to `general_roman_calendar:<missalId>`.
- **Wiring** (`src/Router.php::configureAuthorizationPipeline`): pipes the right factory per route
  and sets `calendar_id`/`test_id` request attributes.
- **Constants:** `VALID_RELATIONS = ['admin','viewer','editor','deleter']` in
  `AccessRequestRepository` (public, source of truth), `AccessRequestHandler` (private),
  `PermissionAdminHandler` (private). `RoleCascadeService` iterates `VALID_RELATIONS`.
- **OpenAPI** (`jsondata/schemas/openapi.json`): `deleter` appears in relation enums and one
  example at ~lines 2110, 2169 (example), 2473, 7417, 7826, 7870.
- **Outbox:** `OutboxOperation` enum = `WRITE_TUPLE`, `DELETE_TUPLE`; dispatched by a hardcoded
  `match` in `OutboxProcessor::invoke()`. `OutboxRepository::insertBatch` is idempotent on
  `metadata->>'idempotency_key'`. `ConsumerLoop::tick` → `processOne` → `CascadeReconciler::evaluate`.
  `CascadeReconciler` dispatches on a `metadata.cascade_kind` discriminator.
  `OpenFgaClient::readTuples($user, $object, $relation, $limit, $token)` paginates; a
  **full-object** filter (`"national_calendar:IT"`) is reliable (the type-only filter is not — see
  `migrate-test-tuples.php`).
- **Delete paths:** `RegionalDataHandler::deleteCalendar` and `TestsHandler` delete by direct
  `unlink()` — no DB transaction, **no FGA cleanup**.
- **Validation:** `AccessRequestRepository::isValidObjectIdForType` — `general_roman_calendar`
  must be in `GRC_OBJECT_IDS`; `general_roman_calendar_test` must equal `'general_roman_calendar'`;
  everything else accepts **any non-empty string**. Nation codes are dynamic (folders under
  `jsondata/sourcedata/calendars/nations/`); `VA` is hardcoded in `CalendarMetadataProvider`.
- **Roles:** `VALID_ROLES = ['developer','calendar_editor','test_editor']`; `calendar_editor`'s
  `ROLE_OBJECT_TYPES` = national/diocesan/wider/GRC. `validateRolePermissionConsistency` gates by
  object_type only (not relation), so `calendar_editor` may already request the `admin` relation on
  its allowed object types.
- **System admin:** `OidcAuthMiddleware::isAdmin($oidcUser)` checks the Zitadel project `admin`
  role; `AccessRequestAdminHandler` approval already requires a global admin.
- **Membership source:** each nation file declares its region, e.g.
  `jsondata/sourcedata/calendars/nations/IT/IT.json` → `"wider_region": "Europe"`. Regions:
  `Americas`, `Asia`, `Europe`.

## Design

### 1. OpenFGA model (`scripts/openfga-model.json`)

Applied to **all** resource types:

- **Union rewrites:** `editor = this or admin`; `viewer = this or editor or admin`. `admin` stays
  direct.
- **Drop `deleter`** (the relation and its `metadata.relations.deleter`).

`wider_region` additionally gets the membership rewrite:

```text
type wider_region
  relations
    define member_nation: [national_calendar]            # tupleset: which nations belong
    define admin:  [user] or admin from member_nation     # national admins inherit WR admin
    define editor: [user] or admin
    define viewer: [user] or editor or admin
```

**Staged as two model versions** (no-downtime, per the PR #666 runbook):

- **v1 (additive):** add union rewrites + `wider_region.member_nation`/TTU; **keep** `deleter`.
- **v2 (cleanup):** remove `deleter` from all types.

The branch ships both versions and the documented apply order.

### 2. Middleware — per-instance method→relation map

Replace the shared `const RELATION_MAP` with an instance property each factory supplies:

| Factory                   | `PUT` (create) | `PATCH` | `DELETE`  |
| ------------------------- | -------------- | ------- | --------- |
| `forCalendarData`         | **admin**      | editor  | **admin** |
| `forTestScopes`           | **editor**     | editor  | **admin** |
| `forMissals`              | **admin**      | editor  | **admin** |
| `forGeneralRomanCalendar` | **admin**      | editor  | **admin** |

`forCalendarData` covers national/diocesan/wider uniformly (create→admin). The Zitadel-`admin`
bypass is unchanged. Update the class docblock.

### 3. Constants

`VALID_RELATIONS → ['admin','viewer','editor']` in all three files. `RoleCascadeService` iterates
`VALID_RELATIONS`, so it self-corrects (stops touching `deleter`). Update the
`PermissionAdminHandler` docblock.

### 4. Create-governance — validation & approval (national + VA)

- **`AccessRequestRepository::isValidObjectIdForType`:** for `national_calendar`, accept a valid
  **ISO 3166-1 alpha-2 code or `VA`**, *without* requiring an existing calendar (prospective).
  This tightens today's "any non-empty string" while permitting not-yet-existing nations. The ISO
  allowlist comes from `intl` (`\ResourceBundle`/`Locale` region data) plus the special `VA`;
  exact source chosen at implementation. Diocesan/wider validation unchanged (deferred).
- **Approval routing:** an `admin` grant on a non-existent `national_calendar` is approved by a
  **system admin** — already the only approval path. **No code change**; document the governance
  chain (system admin seeds the first national admin, who thereafter self-governs scoped
  editor/viewer requests).
- **Role:** stays `calendar_editor`; it may already request the `admin` relation on
  `national_calendar`.

### 5. Calendar + missal coupling (Part 4)

No new tuple. `national_calendar:<N>#admin` authorizes both the calendar `PUT` and that nation's
missal `PUT`s, because `forMissals` resolves a national missal to the FGA object
`national_calendar:<nation>`. Documented in OpenAPI + the governance doc.

### 6. Cascade-revoke on resource deletion (#668 Part 2)

**Mechanism: enqueue concrete `DELETE_TUPLE` rows — no new outbox operation.** The
`openfga_outbox` table is tuple-shaped (`fga_user`/`fga_relation`/`fga_object` all `TEXT NOT NULL`)
and `operation` is a fixed Postgres `outbox_op` enum (`write_tuple`/`delete_tuple`). Rather than add
an enum value + a DB migration + semantically-empty columns, the purge **enumerates the object's
operational tuples and enqueues one `DELETE_TUPLE` row per tuple** — exactly how the existing
permission-revoke cascade already works.

A shared service **`ResourceTuplePurgeService`** centralises this:

- `purgeForObject(string $fgaObject): int` — `readTuples('', $fgaObject, null, …)` (paginated, full
  object filter — the reliable form), keep only operational relations
  (`AccessRequestRepository::OPERATIONAL_RELATIONS = ['viewer','editor']` — **never** `admin`), and
  for each enqueue a `DELETE_TUPLE` row (idempotency key `resource_purge:{object}:{user}:{relation}`)
  then `processSync`. No-op when OpenFGA is unconfigured. Idempotent (`TupleNotFound` benign).
- **`admin` is never enumerated**, so governance survives data deletion.

**Hook points:**

- `RegionalDataHandler::deleteCalendar` — derive `{type}` from the path category (`OBJECT_TYPE_MAP`)
  and `{id}` from the key. **After** successful file deletion (files are authoritative; no DB txn
  wraps them today), call `purgeForObject("{type}:{id}")`. Order: delete files → purge.
- `TestsHandler` delete — resolve `[type, id]` via `TestScopeResolver`, then
  `purgeForObject("{type}:{id}")`.

Governance is removed **only** by an explicit role/permission revoke (the existing
`RoleCascadeService` path), never as a side effect of data deletion.

### 7. Reconciler sweep (defense-in-depth)

A new isolated service **`ResourceTuplePurgeReconciler`** plus a CLI
`scripts/reconcile-resource-tuples.php` (`--dry-run`/`--apply`), cron-able (e.g. daily — kept off
the hot `ConsumerLoop`/`BackstopRunner` path because it scans all tuples):

1. Enumerate all tuples (paginated full scan, then filter in app code — same caveat as
   `migrate-test-tuples.php`).
2. Group by object; for each object of a calendar/test type whose backing resource **no longer
   exists** and which still holds operational (`editor`/`viewer`) tuples, call
   `ResourceTuplePurgeService::purgeForObject()` (the same enqueue-`DELETE_TUPLE` path as the delete
   handlers).
3. **Ignore `admin` tuples** on non-existent resources — intentional governance (per #669), not
   orphans. GRC fixed object ids always "exist".

Resource existence is resolved by a small `ResourceExistenceChecker` mapping object type+id →
backing file path (reusing `JsonData` paths, `RegionalDataHandler` path logic, and
`TestScopeResolver`).

### 8. `wider_region` membership wiring

- **Seeder `scripts/seed-wider-region-membership.php`** (`--dry-run`/`--apply`, idempotent):
  iterate nation files, read each `wider_region`, write
  `wider_region:<Region>#member_nation@national_calendar:<nation>`.
- **Create-path sync:** when a national calendar is created (`RegionalDataHandler` `PUT`) with a
  `wider_region`, enqueue a `WRITE_TUPLE` outbox row for the `member_nation` tuple so the new
  nation's admin immediately inherits wider-region authority.

### 9. OpenAPI

Drop `deleter` from the six enum/example locations; document per-path create semantics and the
relation hierarchy.

### 10. Migration — `scripts/migrate-deleter-tuples.php`

Mirror `migrate-test-tuples.php`: enumerate all tuples → for each `relation == 'deleter'`, write the
`admin` equivalent then delete the `deleter` tuple. `--dry-run`/`--apply`, write-before-delete,
idempotent (`TupleAlreadyExists`/`TupleNotFound` benign).

## Rollout (no downtime — mirror the PR #666 runbook)

1. Apply model **v1** (additive: union rewrites + `wider_region.member_nation`; `deleter` kept).
2. Run `migrate-deleter-tuples.php --dry-run` then `--apply` — **before** the API deploy, so no
   `deleter`-only grant loses delete access once the API enforces `DELETE→admin` (the additive v1
   model still defines both `deleter` and `admin`, so the migration is valid here).
3. Deploy API (per-instance relation map: create→admin/editor, `DELETE→admin`; validation;
   purge-on-delete; create-path membership sync).
4. Run `seed-wider-region-membership.php --apply`.
5. Deploy the coordinated frontend PR (drop `deleter` option).
6. Apply model **v2** (remove `deleter`) once no `deleter` tuples/usages remain.
7. Schedule `reconcile-resource-tuples.php` (daily).

Admins bypass OpenFGA throughout, so they are unaffected during the window.

## Testing

- Middleware: per-resource `PUT`/`DELETE` mapping (calendar-data create denies a plain `editor`,
  requires `admin`; test create allowed for `editor`; `DELETE` requires `admin`).
- Model/resolver: `admin` implies `editor`/`viewer`/delete; `wider_region` member-nation admin
  resolves to `wider_region#admin`.
- Validation: accepts prospective ISO nation ids + `VA`; rejects non-ISO and arbitrary strings.
- Migration mapper unit (`deleter`→`admin`); seeder unit (region membership).
- Outbox purge handler: asserts `admin` **retained**, `editor`/`viewer` purged for a deleted
  resource.
- Delete paths enqueue the purge (`RegionalDataHandler`, `TestsHandler`).
- Reconciler sweep: enqueues purges for deleted resources with operational tuples; **ignores
  `admin`**; no-ops for existing resources and GRC.

## Affected components checklist

- [ ] `scripts/openfga-model.json` (v1 union rewrites + `member_nation` TTU; v2 drops `deleter`)
- [ ] `OpenFgaAuthorizationMiddleware` (per-instance relation map; `DELETE→admin`; docblock)
- [ ] `VALID_RELATIONS` ×3 (`AccessRequestRepository`, `AccessRequestHandler`, `PermissionAdminHandler`)
- [ ] `AccessRequestRepository::isValidObjectIdForType` (prospective ISO national ids + `VA`)
- [ ] `jsondata/schemas/openapi.json` (drop `deleter` enums + example; document create semantics)
- [ ] `ResourceTuplePurgeService` (enqueue one `DELETE_TUPLE` per operational tuple; `admin` never)
- [ ] `AccessRequestRepository::OPERATIONAL_RELATIONS = ['viewer','editor']`
- [ ] `RegionalDataHandler` delete → `purgeForObject`; `PUT` national → enqueue `member_nation` sync
- [ ] `TestsHandler` delete → `purgeForObject`
- [ ] `ResourceExistenceChecker` + `ResourceTuplePurgeReconciler` + `scripts/reconcile-resource-tuples.php`
- [ ] `scripts/seed-wider-region-membership.php`
- [ ] `scripts/migrate-deleter-tuples.php`
- [ ] Ops runbook doc (`docs/`)
- [ ] Tests (per the Testing section)
- [ ] Frontend (separate repo, coordinated): drop `deleter` option
