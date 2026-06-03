<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Best-effort XADD to the reconcile stream.
 *
 * Never throws to the caller — the outbox row is durable in PG, and the
 * cron backstop is the safety net. Logging at WARNING is sufficient
 * signal that something is off; the system continues to function.
 *
 * Pass null \Redis to disable (e.g. in environments without Redis).
 */
final class OutboxNotifier
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ?\Redis $redis,
        private readonly string $streamName,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function notify(int $outboxId, string $operation): void
    {
        if ($this->redis === null) {
            return;
        }

        try {
            $this->redis->xAdd(
                $this->streamName,
                '*',
                [
                    'row_id' => (string) $outboxId,
                    'op'     => $operation,
                ],
            );
        } catch (\RedisException $e) {
            $this->logger->warning('outbox.redis.notify_failed', [
                'row_id' => $outboxId,
                'op'     => $operation,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
