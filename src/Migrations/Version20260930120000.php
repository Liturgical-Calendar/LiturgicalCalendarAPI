<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\AbortMigration;

/**
 * Wider region ids become lowercase kebab-case (#1018): `roman/Europe` → `roman/europe`, `roman/Middle East` →
 * `roman/middle-east`. The mapping is the one WiderRegionId::normalize() applies, lowercase plus spaces to hyphens,
 * restricted to values that start with a capital, which is what makes it idempotent.
 *
 * Rewritten: `access_requests.permissions` (element-wise, order kept, as Version20260901130000 does and for the
 * same reasons: approved rows describe live tuples and pending rows become tuples) and
 * `sourcedata_change_requests.resource_id`, so a region's history is found under its id.
 *
 * Not rewritten: `audit_log` (a record of acts under the name then in force), and change requests' `path` and
 * `branch` (the files and branches that actually existed).
 *
 * Refused: running while a change request on a legacy region is still under review or unpublished, or while the
 * outbox still has a pending or retrying row naming one. Their files and tuples would land on the legacy name
 * after cutover. Drain them first; see docs/ops/wider-region-ids-runbook.md.
 */
final class Version20260930120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rewrite persisted wider region ids to lowercase kebab-case (#1018)';
    }

    public function preUp(Schema $schema): void
    {
        $this->abortIf(!( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ), 'This migration targets PostgreSQL only.');

        $liveChangeRequests = $this->count(<<<'SQL'
            SELECT COUNT(*) FROM sourcedata_change_requests
             WHERE resource_type = 'wider_region' AND resource_id ~ '^roman/[A-Z]'
               AND (review_status = 'submitted' OR (review_status = 'approved' AND publication_status IN ('none', 'queued', 'open')))
            SQL);
        $this->abortIf($liveChangeRequests > 0, "{$liveChangeRequests} change request(s) on a legacy-named wider region are still live; settle them first (docs/ops/wider-region-ids-runbook.md).");

        $liveOutbox = $this->count(<<<'SQL'
            SELECT COUNT(*) FROM openfga_outbox
             WHERE status IN ('pending', 'retrying')
               AND (fga_object ~ '^wider_region:roman/[A-Z]' OR fga_user ~ '^wider_region:roman/[A-Z]')
            SQL);
        $this->abortIf($liveOutbox > 0, "{$liveOutbox} outbox row(s) naming a legacy wider region are still pending; let them drain first.");
    }

    /** A COUNT(*) that fails closed: a non-numeric answer means the guard cannot vouch for anything. */
    private function count(string $sql): int
    {
        $value = $this->connection->fetchOne($sql);
        if (!is_numeric($value)) {
            throw new AbortMigration('A pre-flight safety count did not return a number; refusing to continue.');
        }

        return (int) $value;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE sourcedata_change_requests
               SET resource_id = lower(replace(resource_id, ' ', '-'))
             WHERE resource_type = 'wider_region' AND resource_id ~ '^roman/[A-Z]'
            SQL);

        // WITH ORDINALITY + ORDER BY: `permissions` is a list and its order is part of the value. See
        // Version20260901130000 for the full reasoning; the WHERE guard also keeps a `[]` row from becoming NULL.
        $this->addSql(<<<'SQL'
            UPDATE access_requests
               SET permissions = (
                     SELECT jsonb_agg(
                         CASE
                             WHEN elem->>'object_type' = 'wider_region' AND elem->>'object_id' ~ '^roman/[A-Z]'
                                 THEN jsonb_set(elem, '{object_id}', to_jsonb(lower(replace(elem->>'object_id', ' ', '-'))))
                             ELSE elem
                         END
                         ORDER BY ord
                     )
                     FROM jsonb_array_elements(permissions) WITH ORDINALITY AS t(elem, ord)
                   )
             WHERE EXISTS (
                     SELECT 1 FROM jsonb_array_elements(permissions) AS e(elem)
                      WHERE elem->>'object_type' = 'wider_region' AND elem->>'object_id' ~ '^roman/[A-Z]'
                   )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Irreversible by design: lowercase loses the original capitalisation (`EastIndies` vs `Eastindies`).
        $this->throwIrreversibleMigrationException('Wider region ids cannot be mapped back to their capitalised names.');
    }
}
