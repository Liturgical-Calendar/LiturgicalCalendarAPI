<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use PDO;

/**
 * One-shot scan of openfga_outbox for the cron backstop.
 *
 * Picks up rows older than the grace window (default 60s — the consumer
 * gets first crack), processes them via OutboxProcessorInterface, exits.
 *
 * The grace window is the durability buffer: the consumer's XREADGROUP
 * wake-up is sub-second on the happy path, so the backstop should only
 * see rows where Redis lost the XADD or the consumer is dead.
 */
final class BackstopRunner
{
    public function __construct(
        private readonly OutboxRepository $repo,
        private readonly OutboxProcessorInterface $processor,
        private readonly PDO $pdo,
        private readonly int $graceSeconds = 60,
    ) {
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
            $rows = $this->repo->pickupPending($limit, $cutoff);
            foreach ($rows as $row) {
                $this->processor->processOne($row->id);
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
