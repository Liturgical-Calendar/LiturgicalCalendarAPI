# Design: generalise `general_roman_calendar` into a `rite_calendar` tier

- **Date:** 2026-09-01
- **Status:** Draft (awaiting review)
- **Issue:** #955
- **Repos affected:** `LiturgicalCalendarAPI` (authoritative), `cdcf-infra` (owns the FGA model), `LiturgicalCalendarFrontend` (mirror/UI)
- **Supersedes the naming of:** `docs/superpowers/specs/2026-06-20-general-roman-calendar-object-type-design.md`

## 1. Background

`general_roman_calendar` is the OpenFGA object type for the tier above nations, wider regions and
dioceses. Every rite has such a tier — for the Roman rite it is the General Roman Calendar, for the
Ambrosian rite that rite's own general calendar — so the type is not a name that has outgrown its
contents so much as an archetype that should have been an instance. `rite_calendar:roman` and
`rite_calendar:ambrosian` are the same kind of thing, and a rite added later then needs no new
object type at all.

The type was introduced in
`docs/superpowers/specs/2026-06-20-general-roman-calendar-object-type-design.md`, which is where
this generalisation is re-argued.

### 1.1 Three corrections to the premises in issue #955

The issue was written from assumptions that do not match what is on `development`. Each changes the
work, so each is recorded here rather than silently designed around.

**The interim rite-qualified ids do not exist.** #955 states that #953 left behind
`general_roman_calendar:roman/EDITIO_TYPICA_1970` and `general_roman_calendar:ambrosian/EDITIO_2024`,
and that therefore "only the type token changes". It did not. As landed, `general_roman_calendar`
ids are **bare, and deliberately so**: the rationale is documented in
`AccessRequestRepository::GRC_OBJECT_IDS`, in the `RiteScopedObjectId` class docblock and in
`OpenFgaAuthorizationMiddleware::forMissals()`, and is enforced by
`MissalCatalogTest::testTheRitesDoNotShareIds`. The Ambrosian id is `EDITIO_TYPICA_2024`, not
`EDITIO_2024`. The id shape is therefore an open decision this design must make, not a settled one
it can inherit.

**The `_test` collision is largely already resolved.** #955 treats `rite_calendar_test` as a rival
meaning that would collide head-on with a renamed test type. It is not: `rite_calendar_test` is
*already* `general_roman_calendar_test`'s successor, from #767. `TestScopeResolver` no longer emits
the old type, `scripts/migrate-rite-test-tuples.php` already maps
`general_roman_calendar_test:general_roman_calendar → rite_calendar_test:roman`, and the old type
survives only as a legacy alias so pre-migration tuples keep authorizing. The decision the issue
asks for is therefore not "design the pair together" but "retire the legacy alias", after which the
pair `rite_calendar` / `rite_calendar_test` is clean and consistent.

**There is a stronger argument for the generalisation than the issue makes.**
`jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore` already exists on disk. The bare
object id `temporale` is therefore **already ambiguous in the corpus today** — it only fails to bite
because the temporale write route is Roman-only. This is not a hypothetical about a future rite; it
is a live latent defect, and it is what settles §3 below.

### 1.2 Where the authorization model lives

The FGA authorization model is **not in this repository**. `scripts/setup-openfga.sh` records that
it is owned by `cdcf-infra` at `auth/models/LiturgicalCalendar.json` and uploaded by an operator on
the VPS; this repo only pins `OPENFGA_MODEL_ID`. Adding a type is therefore an external,
cross-repository prerequisite, not a code change — see §9.

## 2. Goals

1. Replace `general_roman_calendar` with a `rite_calendar` type whose ids name their rite.
2. Retire `general_roman_calendar_test`, completing #767, so the data and test tiers agree.
3. Migrate existing FGA tuples and persisted `resource_type` values without any window in which a
   current grant stops authorizing.
4. Leave the API deployable in either order relative to the tuple migration.

## 3. Non-goals

- Changing the relation set (`admin` / `viewer` / `editor` is reused unchanged).
- Dropping the legacy types from the model. That is the prune milestone, §9 step 5.
- Rewriting `audit_log`. See §7.
- Touching `jsondata/supportedLocales.json`'s own top-level `general_roman_calendar` key. That is a
  *data* key describing locale coverage, not an FGA object type, and shares only the spelling.
  See §10.

## 4. Decision: rite-qualified object ids

`rite_calendar` ids are rite-qualified `<rite>/<subresource>` through the existing
`RiteScopedObjectId`:

```text
rite_calendar:roman/temporale              rite_calendar:roman/EDITIO_TYPICA_1970
rite_calendar:roman/decrees                rite_calendar:roman/EDITIO_TYPICA_2002
rite_calendar:roman/supported_locales      rite_calendar:roman/EDITIO_TYPICA_2008
                                           rite_calendar:ambrosian/EDITIO_TYPICA_2024
```

This makes the type stop being the one exception to a rule every other calendar-naming type follows
(`national_calendar:roman/US`, `diocesan_calendar:ambrosian/lugano_ch`,
`wider_region:roman/Europe`), and it mirrors `rite_calendar_test:<rite>`.

**Why the #953 rationale does not survive the generalisation.** That rationale was narrow and
correct on its own terms: a *missal edition id* is unique across rites, so it needs no qualifier to
be unambiguous. But `temporale`, `decrees` and `supported_locales` are not missal ids and are not
unique across rites — they are sub-resource *kinds*, one per rite. `temporale` is ambiguous on disk
already (§1.1). Qualifying only the ambiguous ids was considered and rejected: it puts two id
conventions inside one type, which every validator, every error message and every reviewer then has
to keep straight, in exchange for saving six characters on four ids.

### 4.1 `RiteCalendarObjectIds`

A new `src/Services/RiteCalendarObjectIds.php` owns the catalog, replacing the flat
`AccessRequestRepository::GRC_OBJECT_IDS` constant.

Missal ids are **derived** from `MissalCatalog::for($rite)` (its typical editions) rather than
re-listed. `MissalCatalog` is already the authority — it is how #953 added `EDITIO_TYPICA_2024` — and
deriving is what makes #955's acceptance criterion literally true: a rite added later needs no new
object type *and* no new id list. Only the non-missal sub-resources are declared per rite.

Non-missal sub-resources, and why each rite gets what it gets:

| Sub-resource        | roman | ambrosian | Reason                                                                |
|---------------------|-------|-----------|-----------------------------------------------------------------------|
| `temporale`         | yes   | yes       | Both rites have a `propriumdetempore` on disk                         |
| `decrees`           | yes   | no        | Only `rite/roman/decrees` exists; the Ambrosian rite has no corpus    |
| `supported_locales` | yes   | no        | `supportedLocales.json` is keyed `general_roman_calendar` at its root |

`ambrosian/temporale` is included although no write route consumes it yet. This follows the existing
precedent in `AccessRequestRepository::isValidNationCode()`, which deliberately accepts prospective
nations "so a national liturgy office can request `admin` to create it" (#669): a grantable
permission may precede the route that honours it, and refusing the grant is the harder problem to
unwind later.

`roman/supported_locales` is a known wart — the official-locale set is API-wide rather than
Roman-specific. It is nonetheless the honest reading of today's data, since the file is literally
keyed `general_roman_calendar`. Recorded as a follow-up in §10 rather than fixed here, because
moving it is a data-shape change with its own blast radius.

## 5. Decision: retire `general_roman_calendar_test`

Because `rite_calendar_test` already carries the semantics (§1.1), there is no pair to design and no
collision to break. `general_roman_calendar_test` is a deprecated alias with no producer, and it is
retired on exactly the same schedule as `general_roman_calendar`: accepted through the migration
window, dropped at one prune.

This answers #955's first acceptance criterion — a decision on the pair, made together with the data
type — and it means the migration window has **one** end state to reach rather than two.

## 6. API changes

| File                                                                                                        | Change                                                                                                                                                                                                                |
|-------------------------------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `src/Services/RiteCalendarObjectIds.php`                                                                    | **new** — per-rite id catalog, validation, human-readable label                                                                                                                                                       |
| `src/Repositories/AccessRequestRepository.php`                                                              | `rite_calendar` into `VALID_OBJECT_TYPES` and `ROLE_OBJECT_TYPES`; new arms in `isValidObjectIdForType()` and `validIdsLabelForType()`; `GRC_OBJECT_IDS` retained as a deprecated alias delegating to the new service |
| `src/Services/ChangeResource.php`                                                                           | `decrees()`, `supportedLocales()` and `missal()` emit `rite_calendar` with a qualified id; the first two gain `Rite $rite = Rite::ROMAN`                                                                              |
| `src/Http/Middleware/OpenFgaAuthorizationMiddleware.php`                                                    | `forRiteCalendar()` replaces `forGeneralRomanCalendar()`; `forMissals()` emits the new pair; **legacy fallback** added (§6.1)                                                                                         |
| `src/Services/ResourceAdminService.php`                                                                     | `rite_calendar` into `ADMIN_OBJECT_TYPES` and `VIEWER_OBJECT_TYPES`; fan-out arithmetic in the budget docblock updated                                                                                                |
| `src/Services/ResourceExistenceChecker.php`                                                                 | `rite_calendar` into `RESOURCE_TYPES`; `exists()` returns `true` for it                                                                                                                                               |
| `authz/openfga-expectations.json`                                                                           | `rite_calendar` into `required_types` and `relation_includes`                                                                                                                                                         |
| `src/Router.php`, `src/Handlers/TestsHandler.php`, `src/Repositories/SourceDataChangeRequestRepository.php` | prose referring to the old type name                                                                                                                                                                                  |

### 6.1 Legacy fallback in the middleware

`OpenFgaAuthorizationMiddleware::process()` performs a single `check()`. It gains an optional legacy
object; when the primary check denies, it re-checks against the legacy object before throwing.

This costs nothing on the allow path — the second call happens only where the request was about to
be refused anyway — and it buys the property that makes the additive posture real: **the API
authorizes writes correctly whether or not the tuple migration has run**, in either deploy order,
and a rollback to pre-#955 code keeps authorizing. Without it, "additive" is an intention rather
than a property of the system.

**The fallback preserves authorization only.** It does not preserve change-request auto-approval or
reviewer-queue visibility, and it is deliberately not extended to them.
`ChangeRequestReview::administers()` → `ResourceAdminService::administersAllResources()` checks the
rite-qualified object with no legacy fallback, and reviewer visibility is driven by the
`resource_type`/`resource_id` stored on the change-request row. So in the window between the API
deploy and `scripts/migrate-rite-calendar-tuples.php --apply`, a user holding only a legacy
`general_roman_calendar` admin tuple is still authorized to write, but their change request is
**queued for a human review rather than auto-approved**; and once `composer db:migrate` has
rewritten the stored resource ids, those batches no longer appear in that user's review queue. Both
behaviours resume as soon as the tuple migration has run. That is the right trade: this path decides
governance rather than access, and silently auto-approving off a legacy tuple during a migration
window would be worse than queueing for a reviewer. The gap is fail-closed and self-heals at §9
step 3.

It is removed at the prune milestone (§9 step 5).

### 6.2 `ResourceExistenceChecker` returns `true`

`exists()` already returns an unconditional `true` for `general_roman_calendar`, and the class
docblock explains why in terms that apply unchanged: this predicate decides what the reconciler
**purges**, so a false negative destroys a live grant while a false positive merely leaves a stale
tuple for the next sweep. `rite_calendar` follows the same rule. In particular it does **not**
validate the `<rite>/<subresource>` shape, for the same reason the scoped test types do not: legacy
unqualified ids are still in the store for the whole migration window.

### 6.3 `ResourceAdminService` fan-out budget

`ADMIN_OBJECT_TYPES` goes 4 → 5 and `VIEWER_OBJECT_TYPES` 5 → 6, so the fan-out goes nine calls to
eleven. The 3-second budget documented for #878 was sized as "roughly 250x the measured cost of the
largest fan-out (~12 ms for nine calls)". The headroom remains overwhelming, but the docblock's
arithmetic becomes wrong and must be updated rather than left to go quietly stale — this file has
already been bitten once by a comment that outlived its facts (see the stale
`general_roman_calendar is deliberately untouched … Roman by construction` note in
`scripts/migrate-rite-data-tuples.php`, false since #953).

## 7. Persisted data

Three tables carry these values; they are treated differently on purpose.

**`sourcedata_change_requests`** — `resource_type` (VARCHAR 64) and `resource_id` (VARCHAR 255) are
both rewritten. Neither column has a CHECK constraint or a PG enum, so this is a plain `UPDATE`.

**`access_requests.permissions`** — a **JSONB array** of `{object_type, object_id, relation}`
tuples, not a scalar column, and missed by #955's blast-radius list. It must be rewritten element-wise
(only elements whose `object_type` matches), because a *pending* grant request naming the old type
would otherwise be approved into a legacy tuple after cutover — re-creating the problem the
migration exists to remove.

**`audit_log`** — **not rewritten.** It records what an operator actually did, under the name in
force at the time; rewriting it would falsify the record, and any archived `details` JSONB
mentioning the old type would then disagree with its own row. The cutover date is documented in the
runbook so a reader of `getByResource()` results can resolve old names. Column widths are not a
constraint either way (`VARCHAR(50)` vs. `rite_calendar`'s 13 characters).

A single Doctrine migration performs both rewrites, with a `down()` that reverses them.

## 8. Tuple migration — `scripts/migrate-rite-calendar-tuples.php`

Third in an established family, and deliberately identical in shape to
`migrate-rite-test-tuples.php` (#767) and `migrate-rite-data-tuples.php` (#786): `--dry-run` by
default, `--apply` to write, `--prune` to delete superseded tuples; copy-then-prune ordering so a
tuple is never deleted before its replacement is confirmed; `TupleAlreadyExistsException` and
`TupleNotFoundException` treated as no-ops; safe to re-run after a partial run.

```text
general_roman_calendar:<sub>                       → rite_calendar:<rite>/<sub>
general_roman_calendar_test:general_roman_calendar → rite_calendar_test:roman   (#767 leftover)
```

Rite inference is not a guess. A missal id's rite is whichever `MissalCatalog` declares it; the
non-missal ids (`temporale`, `decrees`, `supported_locales`) are Roman, being the only rite that had
them under the old type. An id no catalog claims is **reported and skipped** — the script never
guesses which grant was meant, matching both predecessors.

## 9. Sequencing

1. **`cdcf-infra` PR** adds the `rite_calendar` type to `auth/models/LiturgicalCalendar.json`,
   additive, with relations mirroring `general_roman_calendar`. Operator uploads it; re-pin with
   `./scripts/setup-openfga.sh --update-env`. **Nothing else can start**: a tuple on a type the
   model does not carry cannot be written.
2. **API PR** (this design) merges and deploys. Writes stay authorized in either order relative to
   step 3 because of the legacy fallback (§6.1) — but change-request auto-approval and
   reviewer-queue visibility do NOT fall back, so run step 3 immediately afterwards to keep that
   window short.
3. Operator runs `php scripts/migrate-rite-calendar-tuples.php --apply` — copy only, nothing deleted.
4. **Frontend PR** mirrors the vocabulary across its 22 files
   (`riteScopedObjectId.js`, `capabilities.js`, `admin-permissions.js`, `admin-tests.js`,
   `admin-decrees.js`, `change-request-common.js`, `notifications.js` and their Jest suites).
5. **Later, once every deployment runs merged code:** `--prune`; then a follow-up API PR drops the
   legacy types from the allow-lists and removes the fallback; then a `cdcf-infra` model version
   drops `general_roman_calendar` and `general_roman_calendar_test`, and
   `authz/openfga-expectations.json` moves them from `required_types` to `forbidden_types`.

Step 5 should be folded into the **same prune window** as the deferred RBAC final-model change (the
`deleter` drop). Both are waiting on precisely the same condition — every deployment running merged
code — and neither depends on the other, so running them as one operator event costs one
coordination window instead of two. This is the concrete form of #955's "planned alongside that, not
raced against it".

## 10. Published contract (OpenAPI)

`rite_calendar` is **added** to the **eight** `object_type` / `resource_type` enum sites in
`jsondata/schemas/openapi.json`; `general_roman_calendar` and `general_roman_calendar_test` remain,
marked deprecated in prose with the prune milestone named.

All eight currently carry a **byte-identical value list**, which is worth preserving: it means the
object-type vocabulary has exactly one definition in the contract, and a site that drifts is
mechanically detectable. The eight are the `/admin/permissions` and `/admin/permissions/check` query
parameters, and the `Permission`, `PermissionTupleInput`, `ChangeRequestSubmission`,
`ChangeRequestBatch`, `ChangeRequestNotification` and `ChangeRequestReviewedNotification` schemas. Replacing the values outright was
rejected: the API still accepts and still emits the legacy values for the whole window, so a
replaced enum would make the published spec disagree with the running service, and a client
validating against it would reject real responses.

Two consequences to call out rather than bury:

- **This is breaking on the response side only, and only for four of the eight.** Adding a value to
  a *request* enum is compatible — the two `/admin/permissions` query parameters and
  `PermissionTupleInput` / `ChangeRequestSubmission` merely accept more than before. Adding one to a
  *response* enum is not: `Permission`, `ChangeRequestBatch`, `ChangeRequestNotification` and
  `ChangeRequestReviewedNotification` can now carry a value that an exhaustive client-side `switch`
  does not handle. It goes in the release notes as such.
- **The id-shape prose inverts.** Several descriptions currently read "…; `general_roman_calendar`
  ids stay bare (e.g. `temporale`)". These must be rewritten, not appended to; leaving them is worse
  than a stale comment, because the spec is what a client generator reads.

The description at `openapi.json:10766` already promises this work — "(The `general_roman_calendar`
type name is rite-agnostic in practice; #955 tracks renaming it.)" — and closes with it.

**Out of scope, sharing only a spelling:** `jsondata/supportedLocales.json` and
`jsondata/schemas/SupportedLocales.json` have a top-level `general_roman_calendar` key. That is a
data key describing which locales the General Roman Calendar is translated into — not an FGA object
type — and renaming it is a source-data change with a different blast radius (`SupportedLocales`,
`scripts/lint-locales.php`, `composer lint:locales`, the published schema). Tracked as a follow-up.

## 11. Testing

The load-bearing test is the one that proves the additive posture is a property and not an
intention: **a legacy `general_roman_calendar:decrees` tuple still authorizes a decrees write after
the change.** Everything else is ordinary coverage.

- `RiteCalendarObjectIdsTest` — the per-rite catalog, including that the Ambrosian set is derived
  from `MissalCatalog` rather than declared, so adding an edition needs no test edit.
- `ChangeResourceTest` — the three factories emit `rite_calendar` with qualified ids.
- `OpenFgaAuthorizationMiddlewareTest` — the new pair, and the legacy fallback in both directions
  (legacy tuple allows; neither tuple still denies, fail-closed).
- A `RepositoryTestCase` migration test — both rewrites, including the JSONB element-wise rewrite,
  and that `audit_log` is untouched.
- Updates across the ~10 existing suites listed in #955's blast radius.

Per `CLAUDE.md`: `AbstractHandlerTestCase` for handler-level, `RepositoryTestCase` for PG-backed,
plain `TestCase` for pure logic; nothing goes in the `slow` group, which is an exclusion mechanism
rather than a label.

## 12. Risks

| Risk                                                                           | Mitigation                                                                                                                                            |
|--------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------|
| Model change is external (`cdcf-infra`) and gates everything                   | §9 step 1 is explicit and first; the API PR is inert-but-safe without it thanks to the fallback                                                       |
| A grant created between migration and Frontend deploy lands on the legacy type | Harmless: the fallback authorizes it, and re-running the migration is idempotent and picks it up                                                      |
| Response-enum change breaks a strict typed client                              | Called out as breaking in the release notes; legacy values retained so only exhaustive `switch` consumers are affected                                |
| A stale comment survives the rename                                            | `general_roman_calendar` occurrences are enumerated in §6; the already-stale note in `migrate-rite-data-tuples.php` is corrected as part of this work |
