# Wider region ids and labels (#1018)

Wider regions were named by a capitalised display name (`Europe`, `Middle East`). They are now identified by a
lowercase kebab-case **id** (`europe`, `middle-east`), and carry localized **labels** in `metadata.labels`.
`GET /calendars` lists each region with `id`, `label` and a deprecated `name` (equal to `id`). The capitalised names
are still accepted on input (paths, payloads) and mapped to ids.

**Date of change: 2026-09-30.** `audit_log` entries written before the deploy of this change name regions as
`roman/Europe`, `roman/Americas`, `roman/Asia`; later entries use `roman/europe`, `roman/americas`, `roman/asia`.
The migration deliberately does not rewrite `audit_log` (a record of acts under the name then in force), so a reader
resolves an old name by lowercasing it and replacing spaces with hyphens.

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
   them, since it is idempotent.

3. **Smoke-test the tuple script against a staging-like OpenFGA** before touching production. The script has not yet
   been run against a live OpenFGA. Run the dry run, review every `-`/`+` line, then `--apply` on staging and check
   the result with the verification step below.

   ```bash
   php scripts/migrate-wider-region-ids.php
   php scripts/migrate-wider-region-ids.php --apply
   ```

4. **Quiesce writes before the migrate step.** The migration's guard counts live rows without locking, so a change
   request or outbox row naming a legacy region could appear between the check and the UPDATE. Stop `litcal-jobs`
   intake (`systemctl stop litcal-jobs`), or hold admin writes, until the migration has run.

## Deploy

1. The deploy runs the Doctrine migration, which aborts if step 1 above was skipped, and restarts `litcal-jobs`.
2. Right after the deploy, run the tuple migration. It is a dry run by default; review the `-`/`+` lines, then apply.
   Until it has run, region editors are denied.

   ```bash
   php scripts/migrate-wider-region-ids.php
   php scripts/migrate-wider-region-ids.php --apply
   ```

3. Verify:
   - `GET /calendars` lists `americas`, `asia` and `europe`, each with a `label` (send `Accept-Language` to see it
     localized);
   - an editor's `PUT` to `/data/widerregion/europe/{locale}` succeeds;
   - `/health` reports `wider_region_membership` green.
4. Once every deployment runs this code, remove the legacy tuples:

   ```bash
   php scripts/migrate-wider-region-ids.php --apply --prune
   ```

   Do not prune earlier: until then the old tuples keep authorizing a rollback.

## Rollback

The Doctrine migration is irreversible. Roll back the code only, leaving the data as ids. Old code cannot read ids,
so treat a rollback as a restore from the pre-deploy database backup, and keep the legacy tuples (do not prune) until
you are sure you will not need to.

## Local development

The migration ran against your local database when you applied this branch. If the main checkout uses the same
database, its `doctrine:migrations:status` shows an executed-but-unregistered migration until this branch is merged.
That is expected and harmless.
