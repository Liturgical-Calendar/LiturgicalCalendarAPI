<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use PDO;

/**
 * One-shot scan of openfga_outbox for the cron backstop.
 *
 * Picks up rows older than the grace window (default 60s — the consumer
 * gets first crack), processes them via OutboxProcessorInterface, and
 * (optionally) invokes CascadeReconcilerInterface on every BENIGN_SUCCESS
 * so the Zitadel role-cascade decision is re-evaluated in the same
 * idempotent way the consumer's hot path does.
 *
 * The grace window is the durability buffer: the consumer's XREADGROUP
 * wake-up is sub-second on the happy path, and the consumer retries due
 * rows itself (forDueRetries), so the backstop should only see rows where
 * Redis lost the XADD or the consumer is dead.
 */
final class BackstopRunner
{
    public function __construct(
        private readonly OutboxRepository $repo,
        private readonly OutboxProcessorInterface $processor,
        private readonly PDO $pdo,
        private readonly int $graceSeconds = 60,
        private readonly ?CascadeReconcilerInterface $cascade = null,
        private readonly bool $retryingOnly = false,
    ) {
    }

    /**
     * The consumer's due-retry pass (#1013), run after every stream read.
     *
     * A retry is never re-announced on the stream — Redis streams have no
     * delayed delivery — so without this pass every retry waited for the
     * backstop. It takes only `retrying` rows, with no grace window: their
     * backoff already is the wait. `pending` rows stay with the handler's
     * sync attempt and the stream message.
     */
    public static function forDueRetries(
        OutboxRepository $repo,
        OutboxProcessorInterface $processor,
        PDO $pdo,
        ?CascadeReconcilerInterface $cascade = null,
    ): self {
        return new self($repo, $processor, $pdo, graceSeconds: 0, cascade: $cascade, retryingOnly: true);
    }

    public function runOnce(int $limit = 100): int
    {
        // FOR UPDATE SKIP LOCKED inside pickupPending only holds locks for
        // the lifetime of the surrounding transaction. Without an explicit
        // tx the locks would be released immediately by PG's autocommit,
        // defeating the SKIP LOCKED guarantee (concurrent runners could
        // double-process). Wrap pickup + processing in one tx so the row
        // locks survive across processOne() for every picked row.
        // Timezone pinned to Europe/Vatican per the project-wide convention.
        $cutoff = ( new \DateTimeImmutable('now', new \DateTimeZone('Europe/Vatican')) )
            ->modify("-{$this->graceSeconds} seconds");

        $this->pdo->beginTransaction();
        try {
            $rows = $this->repo->pickupPending($limit, $cutoff, $this->retryingOnly);
            foreach ($rows as $row) {
                $disposition = $this->processor->processOne($row->id);
                if ($disposition === OutboxDisposition::BENIGN_SUCCESS && $this->cascade !== null) {
                    try {
                        $this->cascade->evaluate($row->id);
                    } catch (\Throwable) {
                        // Never fail the backstop over a cascade decision; same
                        // rationale as ConsumerLoop::tick.
                    }
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return count($rows);
    }
}
