<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Thin wrapper around \Redis XREADGROUP + XACK for the consumer loop.
 *
 * Lives in its own class so the consumer's domain logic (look up the
 * outbox row, invoke OutboxProcessor) doesn't get tangled with Redis
 * Streams plumbing, and so we can unit-test by mocking \Redis.
 */
final class RedisStreamConsumer implements StreamConsumerInterface
{
    private const CLAIM_IDLE_MS = 30_000;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly \Redis $redis,
        private readonly string $streamName,
        private readonly string $groupName,
        private readonly string $consumerName,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Idempotent. BUSYGROUP errors mean the group already exists; that's fine.
     */
    public function ensureGroup(): void
    {
        try {
            // Start ID '0' (not '$') so the group can read any pre-existing
            // messages still on the stream when the consumer first starts.
            // The DB outbox is the source of truth — re-delivering an old
            // message is safe (the row is already terminal or already due,
            // and processOne handles both idempotently). '$' would silently
            // drop messages added before the consumer first started.
            $this->redis->xGroup('CREATE', $this->streamName, $this->groupName, '0', true);
        } catch (\RedisException $e) {
            if (str_contains($e->getMessage(), 'BUSYGROUP')) {
                return;
            }
            throw $e;
        }
    }

    /**
     * Read one message (or batch) from the stream and invoke $process
     * with the row_id field. XACK on success.
     *
     * Stale pending entries (idle > CLAIM_IDLE_MS) are reclaimed first
     * via XCLAIM so a new consumer can finish work a previous consumer
     * crashed mid-flight.
     *
     * @param callable(int): void $process
     */
    public function readOnce(int $blockMs, callable $process): void
    {
        // First, try to claim anything stale from another consumer.
        $this->claimStale($process);

        /** @var array<string, array<string, array<string, string>>>|false $batch */
        $batch = $this->redis->xReadGroup(
            $this->groupName,
            $this->consumerName,
            [$this->streamName => '>'],
            1,
            $blockMs,
        );

        $messages = is_array($batch) ? ( $batch[$this->streamName] ?? [] ) : [];
        if (empty($messages)) {
            return;
        }

        $ackIds = [];
        foreach ($messages as $msgId => $payload) {
            $rowId = isset($payload['row_id']) ? (int) $payload['row_id'] : 0;
            if ($rowId <= 0) {
                $this->logger->warning('outbox.consumer.bad_message', ['msg_id' => $msgId, 'payload' => $payload]);
                $ackIds[] = $msgId;
                continue;
            }
            try {
                $process($rowId);
                $ackIds[] = $msgId;
            } catch (\Throwable $e) {
                // Leave the message in the PEL; XCLAIM on the next pass picks it up.
                $this->logger->error('outbox.consumer.process_failed', [
                    'msg_id' => $msgId,
                    'row_id' => $rowId,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        if (!empty($ackIds)) {
            $this->redis->xAck($this->streamName, $this->groupName, $ackIds);
        }
    }

    /**
     * @param callable(int): void $process
     */
    private function claimStale(callable $process): void
    {
        // Summary form: xPending(stream, group) — returns false or summary array.
        // phpstan-ignore-next-line — ext-redis stubs aren't precise enough for the dual-shape return
        $pending = $this->redis->xPending($this->streamName, $this->groupName);
        if ($pending === false || !is_array($pending) || empty($pending)) {
            return;
        }

        // Detail form: xPending(stream, group, start, end, count) — returns per-message list.
        // phpstan-ignore-next-line — ext-redis stubs aren't precise enough for the dual-shape return
        $detail = $this->redis->xPending(
            $this->streamName,
            $this->groupName,
            '-',
            '+',
            100,
        );
        if ($detail === false || !is_array($detail) || !isset($detail[0]) || !is_array($detail[0])) {
            return;
        }

        $staleIds = [];
        foreach ($detail as $entry) {
            if (!is_array($entry) || count($entry) < 4) {
                continue;
            }
            // $entry = [msgId, consumerName, idleMs, deliveryCount] per Redis XPENDING detail format.
            $msgId  = $entry[0];
            $idleMs = $entry[2];
            if (!is_string($msgId) || !is_int($idleMs)) {
                continue;
            }
            if ($idleMs >= self::CLAIM_IDLE_MS) {
                $staleIds[] = $msgId;
            }
        }

        if (empty($staleIds)) {
            return;
        }

        /** @var array<string, array<string, string>>|false $claimed */
        $claimed = $this->redis->xClaim(
            $this->streamName,
            $this->groupName,
            $this->consumerName,
            self::CLAIM_IDLE_MS,
            $staleIds,
        );
        if ($claimed === false || empty($claimed)) {
            return;
        }

        $ackIds = [];
        foreach ($claimed as $msgId => $payload) {
            $rowId = isset($payload['row_id']) ? (int) $payload['row_id'] : 0;
            $this->logger->warning('outbox.consumer.xclaim', [
                'msg_id'  => $msgId,
                'row_id'  => $rowId,
                'idle_ms' => self::CLAIM_IDLE_MS,
            ]);
            if ($rowId <= 0) {
                $ackIds[] = $msgId;
                continue;
            }
            try {
                $process($rowId);
                $ackIds[] = $msgId;
            } catch (\Throwable) {
                // leave it pending; next pass retries.
            }
        }

        if (!empty($ackIds)) {
            $this->redis->xAck($this->streamName, $this->groupName, $ackIds);
        }
    }
}
