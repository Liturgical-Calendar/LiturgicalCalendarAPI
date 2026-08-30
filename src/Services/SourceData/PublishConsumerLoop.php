<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Services\Outbox\StreamConsumerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Long-lived consumer for the source-data publish stream.
 *
 * # The message is a hint, not a work item
 *
 * A message says WHEN to look; Postgres says WHAT is claimable and by whom. So the batch id
 * carried by a message is used only for logging, and {@see PublishRunner::runOnce()} does the
 * ordinary claim, exactly as cron does — the message is never handed to the publisher, because
 * `runOnce()` takes no batch id at all. Three consequences, all of them the point:
 *
 * - A lost `XADD` costs latency, never correctness — the cron backstop finds the batch.
 * - A duplicate or out-of-order message costs one wasted claim against an empty queue.
 * - This class inherits every guarantee phase 2 built (the claim protocol, bounded attempts,
 *   parking, stop-don't-hammer) without reimplementing any of it.
 *
 * # Nothing here may kill the consumer
 *
 * `PublishRunner` and `MergePollRunner` already catch everything they can and report it in
 * their result objects, so an exception reaching this loop is unexpected by construction —
 * which is exactly why it must be caught rather than allowed to end a process that is meant to
 * stay up. systemd would restart it, but a crash loop against a failing database is the
 * hammering both runners exist to avoid.
 *
 * # The idle tick, and why it is rate-limited
 *
 * Merge detection has no event to wake on: nothing at this deployment knows a reviewer clicked
 * Merge. It runs on the idle tick instead — a `readOnce()` that blocked and returned nothing.
 * With `blockMs` at 5000 that is every five seconds, or 720 GitHub polls an hour for a
 * transition nobody is waiting on, so it is rate-limited to `$mergePollIntervalSeconds`. Cron
 * polls it too; this only shortens the wait.
 *
 * Not a reuse of {@see \LiturgicalCalendar\Api\Services\Outbox\ConsumerLoop}, which is
 * constructor-typed to `OutboxProcessorInterface`. Its sibling, sharing the `tick()` / `run()`
 * split that keeps the loop body unit-testable.
 */
final class PublishConsumerLoop
{
    private bool $groupEnsured = false;

    private ?int $lastMergePollAt = null;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly StreamConsumerInterface $consumer,
        private readonly PublishRunner $publisher,
        /** Null disables the idle poll; the cron entry point still runs it. */
        private readonly ?MergePollRunner $mergePoller = null,
        private readonly int $blockMs = 5000,
        private readonly int $mergePollIntervalSeconds = 60,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function tick(): void
    {
        if (!$this->groupEnsured) {
            $this->consumer->ensureGroup();
            $this->groupEnsured = true;
        }

        $woken = false;

        $this->consumer->readOnce(
            $this->blockMs,
            function (string $batchId) use (&$woken): void {
                $woken = true;
                $this->logger->info(
                    'Woken by an approved source-data batch; claiming from the database.',
                    ['batch_id' => $batchId]
                );

                try {
                    $result = $this->publisher->runOnce();
                    $this->logger->info('Stream-driven publish run finished.', [
                        'published'          => $result->published,
                        'stopped_on_failure' => $result->stoppedOnFailure,
                        'parked'             => $result->parkedBatches,
                    ]);
                } catch (\Throwable $e) {
                    // PublishRunner catches everything it can, so reaching here is unexpected —
                    // which is why it must not end the process. See the class docblock.
                    $this->logger->error('Stream-driven publish run threw; the consumer stays up.', [
                        'batch_id'  => $batchId,
                        'exception' => $e::class,
                        'message'   => $e->getMessage(),
                    ]);
                }
            },
        );

        if (!$woken) {
            $this->pollMergesIfDue();
        }
    }

    private function pollMergesIfDue(): void
    {
        if (null === $this->mergePoller) {
            return;
        }

        $now = time();
        if (null !== $this->lastMergePollAt && ( $now - $this->lastMergePollAt ) < $this->mergePollIntervalSeconds) {
            return;
        }
        $this->lastMergePollAt = $now;

        try {
            $result = $this->mergePoller->runOnce();
            if ($result->merged > 0 || $result->closed > 0 || $result->reset > 0) {
                $this->logger->info('Idle-tick merge poll settled some batches.', [
                    'merged' => $result->merged,
                    'closed' => $result->closed,
                    'reset'  => $result->reset,
                ]);
            }
        } catch (\Throwable $e) {
            // MergePollRunner catches everything it can, so reaching here is unexpected —
            // which is why it must not end the process. See the class docblock.
            $this->logger->error('Idle-tick merge poll threw; the consumer stays up.', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Forever. systemd restarts on crash.
     *
     * @codeCoverageIgnore
     */
    public function run(): never
    {
        while (true) {
            $this->tick();
        }
    }
}
