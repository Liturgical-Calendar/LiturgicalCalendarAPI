<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\AbortMigration;

/**
 * Bring persisted resource types onto the generalised `rite_calendar` tier (#955).
 *
 * Two tables are rewritten and one deliberately is not.
 *
 * `sourcedata_change_requests` and `access_requests.permissions` both still drive FUTURE
 * authorization decisions: a queued change request is reviewed against its resource, and a
 * PENDING access request is approved into real OpenFGA tuples. A pending request left naming
 * the legacy type would be approved into a legacy tuple after cutover, re-creating exactly the
 * state this migration exists to remove — which is why the JSONB array is rewritten
 * element-wise rather than being left to the tuple migration script. Element-wise means a
 * non-matching tuple sharing the array survives byte-for-byte, and every element's `relation`
 * is untouched: only `object_type` and `object_id` move. Element ORDER is preserved explicitly,
 * via WITH ORDINALITY and an ORDER BY inside the jsonb_agg — see the comment at the statement.
 *
 * The permissions rewrite is deliberately NOT restricted to `status = 'pending'`, even though it
 * is the pending rows that motivate it. An approved row's permissions describe tuples that exist
 * in OpenFGA, and `scripts/migrate-rite-calendar-tuples.php` (#955) migrates those very
 * tuples to the new type. Leaving the approved row naming the old type would make the record
 * disagree with the store it describes; rewriting it keeps the two in step. That is why this is
 * NOT the same situation as `audit_log`, which describes an ACT rather than a live grant and so
 * has nothing to be kept in step with. A rejected row is rewritten for the same reason its
 * `resubmit()` path needs it to be: it is a draft that can be resubmitted, not a historical fact.
 *
 * The `WHERE permissions @> …` guard is load-bearing, not an optimisation. `jsonb_agg` over zero
 * rows returns NULL, so without the guard a row whose `permissions` is the default `[]` would be
 * set to NULL and violate the column's NOT NULL constraint. (Verified against Postgres, 2026-09-01.)
 *
 * `audit_log` is NOT rewritten. It records what an operator actually did, under the name in
 * force at the time; rewriting it would falsify the record, and any archived `details` JSONB
 * mentioning the old type would then disagree with its own row. The cutover date is documented
 * in `docs/ops/rite-calendar-migration-runbook.md` so a reader can resolve old names.
 *
 * `resource_type` is a plain VARCHAR on both tables — no CHECK constraint, no PG enum — so
 * these are plain UPDATEs with nothing to drop and re-add first.
 *
 * The two statements are deliberately asymmetric, and the asymmetry is not an omission: the
 * `access_requests.permissions` rewrite handles BOTH legacy types, while the
 * `sourcedata_change_requests` rewrite handles only `general_roman_calendar`. That table was
 * created by `Version20260828120000`, long after `TestScopeResolver` stopped emitting
 * `general_roman_calendar_test`, so no `sourcedata_change_requests` row can ever have carried the
 * test type — there is nothing for a second clause to match. `access_requests` predates that
 * change and can, which is why only it needs the second arm.
 *
 * Both statements are idempotent: their WHERE clauses match only unmigrated rows, and the id
 * rewrite is a no-op on an already-qualified id. Running the migration twice changes nothing
 * the second time.
 *
 * # Limits of down()
 *
 * These are real properties of reversing a data migration, stated rather than papered over. The
 * list is deliberately not numbered in its heading: a count in a heading goes stale the moment
 * someone finds another one.
 *
 * **A post-cutover non-Roman row is REFUSED, not silently rewritten.** `down()` strips the rite
 * prefix, so a grant created by post-#955 code AFTER cutover — say `rite_calendar:ambrosian/temporale`
 * — would come back as `general_roman_calendar:temporale`, silently reinterpreting an Ambrosian
 * grant as a Roman one. Nothing in the row records which side of the cutover it was written on, so
 * `down()` cannot spare such a row while still reversing the rest; what it CAN do, and does, is
 * refuse to run at all rather than corrupt it. The guard below counts every value that would not
 * round-trip and aborts naming the count, so an operator gets a stop rather than a silent
 * reinterpretation. `ambrosian/EDITIO_TYPICA_2024` is not among them: `up()` produces that prefix
 * itself, and `down()` returns it to the bare `EDITIO_TYPICA_2024` it came from — the one non-Roman
 * value that does round-trip, so refusing it would make `down()` unable to reverse its own `up()`.
 * (`testDownReversesUp` covers exactly that row.) The targeted manual rollback, for an operator who
 * hits the refusal, is in `docs/ops/rite-calendar-migration-runbook.md`.
 *
 * **An already-qualified legacy row does not round-trip.** `up()` explicitly supports a row that
 * was already `general_roman_calendar` with a qualified `roman/decrees` id, and leaves the id
 * alone. `down()` strips the prefix unconditionally, so that row returns as bare `decrees` — not
 * what it was before `up()` ran. `testAnAlreadyQualifiedIdIsLeftAlone` covers the up() half of
 * this; the down() half is accepted, not fixed, because `down()` cannot distinguish an id it
 * qualified from one that arrived qualified.
 *
 * **It does NOT restore `general_roman_calendar_test` from `rite_calendar_test`.** That type has
 * had two possible provenances since #767, so a `rite_calendar_test:roman` row may predate this
 * migration entirely, and reverting it would corrupt rows this migration never touched.
 * Down-migrating leaves those as they are, which is correct — they were already valid before #955.
 *
 * **The Ambrosian id is HARDCODED in up() rather than derived.** That is deliberate and is how a
 * migration should be written: it is a point-in-time artifact recording the id set as it stood on
 * 2026-09-01. Deriving it from `MissalCatalog` would make an already-applied migration's behaviour
 * change retroactively as the catalog grows, which is precisely what migrations exist to prevent.
 * `RiteCalendarObjectIds` is the live, derived authority; this is the frozen snapshot.
 */
final class Version20260901130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retype persisted general_roman_calendar resources onto the rite-qualified rite_calendar tier (#955)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql(<<<'SQL'
            UPDATE sourcedata_change_requests
               SET resource_type = 'rite_calendar',
                   resource_id   = CASE
                       WHEN resource_id LIKE 'roman/%' OR resource_id LIKE 'ambrosian/%' THEN resource_id
                       WHEN resource_id = 'EDITIO_TYPICA_2024' THEN 'ambrosian/' || resource_id
                       ELSE 'roman/' || resource_id
                   END
             WHERE resource_type = 'general_roman_calendar'
            SQL);

        // WITH ORDINALITY + ORDER BY is not decoration, and must not be "simplified" away.
        // `permissions` is a JSON LIST that AccessRequestRepository decodes straight into a PHP
        // list, so element order is part of the stored value and every consumer inherits it.
        // jsonb_agg has NO defined input order without an explicit ORDER BY — the fact that
        // jsonb_array_elements happens to emit rows in array order today is an implementation
        // detail, not a guarantee. Leaving it implicit would make a production data migration
        // silently nondeterministic, able to reorder a pending user's request as a side effect
        // of a rewrite that was only ever meant to rename two fields. The ordinality is what
        // carries the original order through the aggregate.
        $this->addSql(<<<'SQL'
            UPDATE access_requests
               SET permissions = (
                     SELECT jsonb_agg(
                         CASE
                             WHEN elem->>'object_type' = 'general_roman_calendar' THEN
                                 jsonb_set(
                                     jsonb_set(elem, '{object_type}', '"rite_calendar"'),
                                     '{object_id}',
                                     to_jsonb(
                                         CASE
                                             WHEN elem->>'object_id' LIKE 'roman/%' OR elem->>'object_id' LIKE 'ambrosian/%'
                                                 THEN elem->>'object_id'
                                             WHEN elem->>'object_id' = 'EDITIO_TYPICA_2024'
                                                 THEN 'ambrosian/' || (elem->>'object_id')
                                             ELSE 'roman/' || (elem->>'object_id')
                                         END
                                     )
                                 )
                             WHEN elem->>'object_type' = 'general_roman_calendar_test' THEN
                                 jsonb_set(
                                     jsonb_set(elem, '{object_type}', '"rite_calendar_test"'),
                                     '{object_id}',
                                     '"roman"'
                                 )
                             ELSE elem
                         END
                         ORDER BY t.ord
                     )
                     FROM jsonb_array_elements(permissions) WITH ORDINALITY AS t(elem, ord)
                   )
             WHERE permissions @> '[{"object_type": "general_roman_calendar"}]'
                OR permissions @> '[{"object_type": "general_roman_calendar_test"}]'
            SQL);
    }

    /**
     * The one non-Roman id `up()` itself produces, and therefore the one that round-trips.
     *
     * Hardcoded for the same reason the id is hardcoded in `up()`: a migration is a point-in-time
     * artifact, and deriving this from `MissalCatalog` would change an already-applied migration's
     * behaviour as the catalog grows.
     */
    private const ROUND_TRIPPING_NON_ROMAN_ID = 'ambrosian/EDITIO_TYPICA_2024';

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        // Refuse rather than corrupt. The rewrite below strips the rite prefix unconditionally, so
        // any `rite_calendar` value whose rite is not `roman` — a row written by post-#955 code
        // AFTER cutover — would come back as a Roman `general_roman_calendar` id, silently
        // reinterpreting another rite's resource. Count those first and stop if there are any; see
        // "Limits of down()" above for why the sole Ambrosian id up() emits is exempt.
        $unsafeValue = $this->connection->fetchOne(
            <<<'SQL'
                SELECT (
                    SELECT count(*)
                      FROM sourcedata_change_requests
                     WHERE resource_type = 'rite_calendar'
                       AND position('/' in resource_id) > 0
                       AND resource_id NOT LIKE 'roman/%'
                       AND resource_id <> :exempt_resource_id
                ) + (
                    SELECT count(*)
                      FROM access_requests ar,
                           LATERAL jsonb_array_elements(ar.permissions) AS elem
                     WHERE elem->>'object_type' = 'rite_calendar'
                       AND position('/' in elem->>'object_id') > 0
                       AND elem->>'object_id' NOT LIKE 'roman/%'
                       AND elem->>'object_id' <> :exempt_object_id
                )
                SQL,
            [
                'exempt_resource_id' => self::ROUND_TRIPPING_NON_ROMAN_ID,
                'exempt_object_id'   => self::ROUND_TRIPPING_NON_ROMAN_ID,
            ]
        );

        // A `count(*)` that did not come back as a number means the guard did not run, and an
        // unverified rollback is exactly what this check exists to prevent — so fail closed rather
        // than defaulting the count to zero and proceeding. Thrown directly rather than through
        // abortIf() so the narrowing below is a fact about the code and not an assumption.
        if (!is_numeric($unsafeValue)) {
            throw new AbortMigration(
                'Refusing to reverse this migration: the pre-flight safety count did not return a number, '
                . 'so it is not known whether any persisted rite_calendar value names a non-Roman rite. '
                . 'See docs/ops/rite-calendar-migration-runbook.md.'
            );
        }

        $unsafeCount = (int) $unsafeValue;

        $this->abortIf(
            $unsafeCount > 0,
            sprintf(
                'Refusing to reverse this migration: %d persisted rite_calendar value(s) name a rite other than "roman". '
                    . 'down() strips the rite prefix, so reversing would silently retype those as Roman '
                    . 'general_roman_calendar resources — a rollback that corrupts data rather than restoring it. '
                    . 'See docs/ops/rite-calendar-migration-runbook.md for the targeted manual rollback.',
                $unsafeCount
            )
        );

        $this->addSql(<<<'SQL'
            UPDATE sourcedata_change_requests
               SET resource_type = 'general_roman_calendar',
                   resource_id   = regexp_replace(resource_id, '^(roman|ambrosian)/', '')
             WHERE resource_type = 'rite_calendar'
            SQL);

        // Same reason as up(), and the same exposure: jsonb_agg has no defined input order without
        // an explicit ORDER BY, and a permissions array's element order is part of its value.
        $this->addSql(<<<'SQL'
            UPDATE access_requests
               SET permissions = (
                     SELECT jsonb_agg(
                         CASE
                             WHEN elem->>'object_type' = 'rite_calendar' THEN
                                 jsonb_set(
                                     jsonb_set(elem, '{object_type}', '"general_roman_calendar"'),
                                     '{object_id}',
                                     to_jsonb(regexp_replace(elem->>'object_id', '^(roman|ambrosian)/', ''))
                                 )
                             ELSE elem
                         END
                         ORDER BY t.ord
                     )
                     FROM jsonb_array_elements(permissions) WITH ORDINALITY AS t(elem, ord)
                   )
             WHERE permissions @> '[{"object_type": "rite_calendar"}]'
            SQL);
    }
}
