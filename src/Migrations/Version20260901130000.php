<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

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
 * is untouched: only `object_type` and `object_id` move.
 *
 * `audit_log` is NOT rewritten. It records what an operator actually did, under the name in
 * force at the time; rewriting it would falsify the record, and any archived `details` JSONB
 * mentioning the old type would then disagree with its own row. The cutover date is documented
 * in `docs/ops/rite-calendar-migration-runbook.md` so a reader can resolve old names.
 *
 * `resource_type` is a plain VARCHAR on both tables — no CHECK constraint, no PG enum — so
 * these are plain UPDATEs with nothing to drop and re-add first.
 *
 * Both statements are idempotent: their WHERE clauses match only unmigrated rows, and the id
 * rewrite is a no-op on an already-qualified id. Running the migration twice changes nothing
 * the second time.
 *
 * # Two honest limits of down()
 *
 * It does NOT restore `general_roman_calendar_test` from `rite_calendar_test`. That type has had
 * two possible provenances since #767, so a `rite_calendar_test:roman` row may predate this
 * migration entirely, and reverting it would corrupt rows this migration never touched.
 * Down-migrating leaves those as they are, which is correct — they were already valid before #955.
 *
 * The Ambrosian id is HARDCODED in up() rather than derived. That is deliberate and is how a
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
                     )
                     FROM jsonb_array_elements(permissions) AS elem
                   )
             WHERE permissions @> '[{"object_type": "general_roman_calendar"}]'
                OR permissions @> '[{"object_type": "general_roman_calendar_test"}]'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql(<<<'SQL'
            UPDATE sourcedata_change_requests
               SET resource_type = 'general_roman_calendar',
                   resource_id   = regexp_replace(resource_id, '^(roman|ambrosian)/', '')
             WHERE resource_type = 'rite_calendar'
            SQL);

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
                     )
                     FROM jsonb_array_elements(permissions) AS elem
                   )
             WHERE permissions @> '[{"object_type": "rite_calendar"}]'
            SQL);
    }
}
