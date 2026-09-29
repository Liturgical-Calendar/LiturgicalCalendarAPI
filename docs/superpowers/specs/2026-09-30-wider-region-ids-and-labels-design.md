# Wider region ids and labels — design (#1018)

**Issue:** [#1018](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/1018). The decisions are recorded in
[this comment](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/1018#issuecomment-5901006686).
[#1012](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/1012) (rename route) is closed as superseded.

## 1. Problem

A wider region's display name is also its identifier (`Europe`, `Middle East`). A name containing a space cannot be
used: path segments are never URL-decoded, and OpenFGA rejects whitespace in object ids
(`wider_region:roman/German Language Area` → `validation_error`). The name is also English only.

## 2. Decisions

1. **Identifier.** Lowercase kebab-case, `^[a-z]+(-[a-z]+)*$` (`WiderRegionId`), for every region. It is permanent
   once the region exists, so there is no rename route. What users see changes through the label.
2. **Migration of the three existing regions.** `Americas` → `americas`, `Asia` → `asia`, `Europe` → `europe`:
   - folders and files;
   - every nation's `wider_regions`;
   - OpenFGA grants and `member_nation` tuples;
   - `access_requests.permissions`;
   - `sourcedata_change_requests.resource_id`.
3. **Transition.** A legacy capitalised name is accepted on input and mapped deterministically: lowercase it and
   replace spaces with hyphens, so `Middle East` → `middle-east`. This applies to the path key, a region's
   `metadata.wider_region`, and a nation's `metadata.wider_regions` / `wider_region`. The payload is rewritten to
   the id before it is stored. National write responses add a `warnings` entry.
4. **Labels live in the API, not in gettext/Weblate.** A region file's `metadata.labels` is a map from a label key to
   a non-empty string.
   - A key is a bare language (`it`) or language plus script (`zh_Hans`), and must be `en` or derivable from one of
     the region's declared `metadata.locales`.
   - It is optional. The Frontend's forms edit it, and it is saved by `PUT`/`PATCH` with the rest of the metadata.
5. **`/calendars`.** Each `wider_regions` item gains `id` and `label`, with `label` resolved for the request's
   `Accept-Language`.
   - Resolution: language plus script → language → `en` → words derived from the id (`german-language-area` →
     `German Language Area`).
   - The response gains `Vary: Accept-Language`.
   - `name` stays as a **deprecated** alias of `id`. `wider_regions_keys` lists ids.
6. **Full map.** The full `labels` map is served only by `GET /data/widerregion/{id}` (the region file).
7. **Prospective regions** (not yet created) keep their labels on the Frontend. The Frontend follow-up is a separate PR.

## 3. Label seeding for the existing regions

The existing regions correspond to UN M.49 / CLDR territory codes: `americas` = `019`, `asia` = `142`,
`europe` = `150`. Their seed labels come from ICU (`Locale::getDisplayRegion('und_150', $lang)`) for `en` and for
every label key derived from the region's `locales`. The first letter is upper-cased, because ICU returns the
in-sentence form for some languages (Irish `an Eoraip`). The generated labels are committed as data. Nothing reads
ICU at runtime.

## 4. Live-state migration (per deployment)

- **Postgres:** a Doctrine migration, which runs automatically in the deploy's `_ops/migrate` step.
  - It rewrites `wider_region` `object_id`s in `access_requests.permissions`, element-wise and order-preserving, as
    `Version20260901130000` did. It also rewrites `sourcedata_change_requests.resource_id`.
  - It **aborts** while a non-terminal change request, or a `pending`/`retrying` outbox row, still names a legacy
    region. `audit_log` and change requests' `path`/`branch` are history and are left untouched.
- **OpenFGA:** `scripts/migrate-wider-region-ids.php`, dry run by default.
  - `--apply` copies every tuple whose object, or user, is `wider_region:roman/<Legacy>` to
    `wider_region:roman/<id>`.
  - `--prune` deletes the originals.
- **Safety window.** Until the script runs, grants still sit on the legacy objects: editors of a region are denied
  (fail closed), and no data is lost. `ResourceExistenceChecker` treats a legacy region id as existing when its
  mapped id exists, so the daily `resource-tuple-sweep` job cannot purge a grant before it is copied.

## 5. Out of scope

- The Frontend switch to `id` + `label` and a label editor (follow-up PR in LiturgicalCalendarFrontend).
- Removing the deprecated `name` field and the legacy-name input mapping (a later cleanup, after the Frontend ships).
