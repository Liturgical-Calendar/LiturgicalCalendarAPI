# Wider region ids and labels (#1018)

Wider regions were named by a capitalised display name (`Europe`, `Middle East`). They are now identified by a
lowercase kebab-case **id** (`europe`, `middle-east`), and carry localized **labels** in `metadata.labels`.
`GET /calendars` lists each region with `id`, `label` and a deprecated `name` (equal to `id`). The capitalised names
are still accepted on input (paths, payloads) and mapped to ids.

**Date of change: 2026-09-30.** `audit_log` entries written before the deploy of this change name regions as
`roman/Europe`, `roman/Americas`, `roman/Asia`; later entries use `roman/europe`, `roman/americas`, `roman/asia`.
The migration deliberately does not rewrite `audit_log` (a record of acts under the name then in force), so a reader
resolves an old name by lowercasing it and replacing spaces with hyphens.

## Prerequisite: the Frontend follow-up ships with this change

**Do not merge this change on its own.** Merging it to `development` deploys staging automatically, and the current
Frontend then breaks on region ids:

- it cannot open an existing region, because its `WIDER_REGION_NAME_PATTERN` rejects `europe`;
- it cannot save the IT, NL, HR, US or CA national calendars, because `NationalCalendarPayload.js` rejects the ids
  in their `wider_regions`.

The Frontend follow-up, which accepts region ids (at least both shapes, ids and legacy names), must merge together with
this change or before it, and production must deploy both together. The Frontend touchpoints are:

- `assets/js/prospectiveWiderRegions.js`: `WIDER_REGION_NAME_PATTERN` and `isValidWiderRegionName()`, which the next
  three use;
- `assets/js/extending.js`: the key trap on the region name input;
- `assets/js/extending.js`: `regionalNationalCalendarNameChanged`;
- `assets/js/NationalCalendarPayload.js`: the `wider_regions` validation;
- `src/ProspectiveWiderRegions.php` and `assets/data/ProspectiveWiderRegions.json`: the capitalised region names;
- the region payload rebuild, which sends `{locales, wider_region}` and so drops `metadata.labels`. The API now keeps
  the stored labels when a region PATCH sends none, but the Frontend should send and edit them.

Two things change at deploy time and both must be handled: rows in Postgres (the Doctrine migration
`Version20260930120000`) and tuples in OpenFGA (`scripts/migrate-wider-region-ids.php`). Until the tuples are moved,
region editors are denied: the system fails closed.

## Before the deploy

1. **Check that no change request or outbox row names a legacy region.** The migration aborts, failing the deploy, if
   any does, so settle them first.

   ```sql
   SELECT id, review_status, publication_status FROM sourcedata_change_requests
    WHERE resource_type = 'wider_region' AND resource_id ~ '^roman/[A-Z]'
      AND (review_status = 'submitted'
           OR (review_status = 'approved' AND publication_status IN ('none', 'queued', 'open')));
   SELECT id, status FROM openfga_outbox
    WHERE status IN ('pending', 'retrying')
      AND (fga_object ~ '^wider_region:roman/[A-Z]' OR fga_user ~ '^wider_region:roman/[A-Z]');
   ```

   Also look for live national calendar change requests. The migration does not check these, but settle them before
   the deploy too: a payload written before it can still name its regions the old way (`"wider_regions": ["Europe"]`).
   Membership and `GET /calendars` map such a name to its id, so a late merge is harmless to access, but the merged
   file would still carry the old name.

   ```sql
   SELECT id, resource_id, review_status, publication_status FROM sourcedata_change_requests
    WHERE resource_type = 'national_calendar'
      AND (review_status = 'submitted'
           OR (review_status = 'approved' AND publication_status IN ('none', 'queued', 'open')));
   ```

2. **Check for pre-#786 unqualified ids.** The migration only rewrites `roman/`-qualified ids. Older
   `access_requests.permissions` elements may carry a bare region name with no `roman/` prefix, such as `Europe`.

   ```sql
   SELECT id, elem
     FROM access_requests, jsonb_array_elements(permissions) AS elem
    WHERE elem->>'object_type' = 'wider_region'
      AND elem->>'object_id' NOT LIKE 'roman/%';
   ```

   If any row is returned, the migration will leave it alone. Qualify those ids by hand (`Europe` becomes
   `roman/europe`) before the deploy, or fix them by hand afterwards; the migration can be re-run after qualifying
   them, since it is idempotent. "By hand" means: for an unqualified element such as `Europe`, rewrite its
   `object_id` to `roman/europe` with an UPDATE on `access_requests.permissions` modelled on the migration's
   element-wise statement (`Version20260930120000`, which keeps element order), or ask for help.

3. **Smoke-test the tuple script against a staging-like OpenFGA** before touching production. The script has not yet
   been run against a live OpenFGA. Run the dry run, review every `-`/`+` line, then `--apply` on staging and check
   the result with the verification step below.

   ```bash
   php scripts/migrate-wider-region-ids.php
   php scripts/migrate-wider-region-ids.php --apply
   ```

4. **Quiesce writes before the migrate step.** The migration's guard counts live rows without locking, so a change
   request or outbox row naming a legacy region could appear between the check and the UPDATE. Hold every admin
   write: no calendar-data, permission or access-request writes through the API or the Frontend admin. This is the
   required quiesce. Do **not** stop `litcal-jobs`: it must keep running so the outbox drains and pending change
   requests can settle. Stopping it would leave `pending`/`retrying` rows that never drain, and those are exactly
   what the migration aborts on.
5. **Re-run the queries of step 1** once writes are held, because a check made before the quiesce can be stale.
   Continue only when both return no rows; otherwise wait for the jobs to drain them and check again.

## Deploy

1. The deploy runs the Doctrine migration, which aborts if step 1 above was skipped, and restarts `litcal-jobs`.
2. Right after the deploy, run the tuple migration. It is a dry run by default; review the `-`/`+` lines, then apply.
   Until it has run, region editors are denied.
   Keep admin writes held until this `--apply` has finished.

   ```bash
   php scripts/migrate-wider-region-ids.php
   php scripts/migrate-wider-region-ids.php --apply
   ```

3. Resume admin writes now: only after the deploy and the `--apply` have both completed. Then verify:
   - `GET /calendars` lists `americas`, `asia` and `europe`, each with a `label` (send `Accept-Language` to see it
     localized);
   - an editor's `PUT` to `/data/widerregion/europe/{locale}` succeeds;
   - `/health` reports `wider_region_membership` green.
4. Once every deployment runs this code, remove the legacy tuples:

   ```bash
   php scripts/migrate-wider-region-ids.php --apply --prune
   ```

   Do not prune earlier: until then the old tuples keep authorizing a rollback. That does not hold for the
   `admin from member_nation` path: the daily membership reconciler moves `member_nation` tuples to ids on its first
   run, deleting the legacy ones before any `--prune`, and after a rollback the old code's reconciler restores them
   within a day.

## Rollback

The Doctrine migration cannot be undone. Rolling back means restoring the pre-deploy database backup **and**
redeploying the previous code; any writes made since the backup are lost. Keep the legacy OpenFGA tuples (do not
prune) until you are sure you will not need to roll back.

If the deploy is aborted before the migrate step (for example, the guard refused), resume admin writes; nothing else
needs undoing.

## Local development

The migration ran against your local database when you applied this branch. If the main checkout uses the same
database, its `doctrine:migrations:status` shows an executed-but-unregistered migration until this branch is merged.
That is expected and harmless.
