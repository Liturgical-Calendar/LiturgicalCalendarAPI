<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Best-effort `XADD` announcing that a batch has become publishable.
 *
 * Mirrors {@see \LiturgicalCalendar\Api\Services\Outbox\OutboxNotifier}, and for the same reason:
 * the row is already durable in Postgres and cron is the backstop, so this is an accelerator over
 * a durable queue, never the queue itself. It therefore NEVER throws to the caller — a Redis
 * outage must not fail an approval that has already committed. Losing the message costs latency
 * and nothing else.
 *
 * The message is a HINT, not a work item: the consumer ignores the batch id except for logging and
 * claims from Postgres exactly as cron does. That is what makes a lost, duplicated or out-of-order
 * message harmless.
 *
 * Pass a null `\Redis` to disable — the ordinary state for a self-hoster, since `REDIS_SOCKET` /
 * `REDIS_HOST` are commented out in `.env.example`.
 *
 * Not `final`, unlike most of this namespace: it has one method and no invariant worth protecting,
 * and tests substitute a recording subclass rather than justify an interface for it.
 */
class SourceDataPublishNotifier
{
    /**
     * The stream message field carrying the batch id — NOT `RedisStreamConsumer`'s default
     * ('row_id', the OpenFGA outbox's own field name). `bin/publish-sourcedata-consumer` must
     * construct its `RedisStreamConsumer` with this same value; nothing else ties the two
     * together, and a drift between them makes every message look malformed —
     * `RedisStreamConsumer::readOnce()` logs `bad_message` and ACKs it away silently, so the
     * consumer would run forever, waking on every notification and doing nothing with any of
     * them, while cron quietly did all the real work.
     */
    public const BATCH_ID_FIELD = 'batch_id';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ?\Redis $redis,
        private readonly string $streamName,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function notify(string $batchId): void
    {
        if (null === $this->redis) {
            return;
        }

        try {
            $this->redis->xAdd($this->streamName, '*', [self::BATCH_ID_FIELD => $batchId]);
        } catch (\RedisException $e) {
            $this->logger->warning('sourcedata.redis.notify_failed', [
                'batch_id' => $batchId,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
