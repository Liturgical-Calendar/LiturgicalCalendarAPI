<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

use LiturgicalCalendar\Api\Repositories\OutboxRepository;

/**
 * One-shot scan of openfga_outbox for the cron backstop.
 *
 * Picks up rows older than the grace window (default 60s — the consumer
 * gets first crack), processes them via OutboxProcessor, exits.
 *
 * The grace window is the durability buffer: the consumer's XREADGROUP
 * wake-up is sub-second on the happy path, so the backstop should only
 * see rows where Redis lost the XADD or the consumer is dead.
 */
final class BackstopRunner
{
    public function __construct(
        private readonly OutboxRepository $repo,
        private readonly OutboxProcessor $processor,
        private readonly int $graceSeconds = 60,
    ) {
    }

    public function runOnce(int $limit = 100): int
    {
        $cutoff = ( new \DateTimeImmutable() )->modify("-{$this->graceSeconds} seconds");
        $rows   = $this->repo->pickupPending($limit, $cutoff);

        foreach ($rows as $row) {
            $this->processor->processOne($row->id);
        }

        return count($rows);
    }
}
