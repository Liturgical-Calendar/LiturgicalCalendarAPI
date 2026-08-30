<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-batch publish scheduling: the column that lets the publisher pace its own retries.
 *
 * `sourcedata_change_requests` already carried the whole job state — `publication_status`,
 * `publish_attempts`, `publish_claim_token`, `updated_at`, `publication_settled_at` — except for
 * *when a failed batch may be tried again*. Phase 3 borrowed the outbox subsystem's transport (the
 * Redis stream, `StreamConsumerInterface`, the consumer-loop shape) and left its scheduler behind,
 * so cron's fixed interval was supplying the pacing instead: `PublishRunner`'s docblock said, in
 * as many words, that `OutboxBackoff` was unnecessary "because there is no in-process retry loop
 * to pace, only a single straight-line pass per tick".
 *
 * That reasoning holds exactly as long as the only thing invoking a run is cron. It stops holding
 * the moment the consumer schedules its own recovery tick, which is what this column exists to
 * make safe: without it, a deterministically-failing batch is re-claimed on every tick and burns
 * `MAX_PUBLISH_ATTEMPTS` in five ticks rather than five cron intervals.
 *
 * Mirrors `openfga_outbox.next_attempt_at` and its `idx_outbox_pickup` partial index
 * ({@see Version20260602202504}) in shape, but NOT in schedule — see
 * {@see \LiturgicalCalendar\Api\Services\SourceData\PublishBackoff} for why five attempts here
 * cannot use the ten-attempt outbox curve.
 *
 * Written by `releaseClaim()` only — NOT by `reclaimStaleClaims()`, which spends an attempt but leaves
 * the batch due immediately, because the grace period it already waited out is that path's spacing and is
 * far coarser than any backoff step. See that method's docblock.
 *
 * `NOT NULL DEFAULT NOW()` matches the outbox column and makes the backfill trivial and correct:
 * every row that exists when this migration runs becomes due immediately, which is the state it
 * was already in before the column existed.
 */
final class Version20260831120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sourcedata_change_requests.next_attempt_at so publish retries are paced per batch, not per cron tick';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql(
            'ALTER TABLE sourcedata_change_requests '
            . 'ADD COLUMN next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT NOW()'
        );

        $this->addSql(
            'COMMENT ON COLUMN sourcedata_change_requests.next_attempt_at IS '
            . "'Earliest time this batch may be claimed again; set by releaseClaim() from "
            . "PublishBackoff, reset to NOW() when a batch settles'"
        );

        // The claim path's own predicate, indexed. Partial on the only status a claimable batch
        // can hold, exactly as idx_outbox_pickup is partial on ('pending', 'retrying').
        $this->addSql(
            'CREATE INDEX idx_scr_publish_pickup ON sourcedata_change_requests '
            . "(publication_status, next_attempt_at) WHERE publication_status = 'none'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql('DROP INDEX IF EXISTS idx_scr_publish_pickup');
        $this->addSql('ALTER TABLE sourcedata_change_requests DROP COLUMN IF EXISTS next_attempt_at');
    }
}
