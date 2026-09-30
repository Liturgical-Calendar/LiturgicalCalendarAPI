<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Long-lived consumer loop body.
 *
 * tick() does one readOnce + process cycle (the unit-testable part).
 * run() is the outer while (true), excluded from coverage. Splitting
 * keeps the test pyramid honest.
 *
 * Optionally wires a CascadeReconcilerInterface that's invoked after
 * every BENIGN_SUCCESS to bridge the outbox row's success into the
 * Zitadel role-cascade decision. The reconciler is constructor-optional
 * so existing tests and tooling that don't need cascade can still
 * construct a ConsumerLoop with the original 3-arg signature.
 *
 * Every tick ends with the due-retry pass (#1013), when one is wired: a
 * row whose OpenFGA call failed is never announced on the stream again,
 * so this is what retries it on its backoff schedule rather than at the
 * backstop's next run. A retry therefore fires within one blocking read
 * (blockMs) of falling due.
 */
final class ConsumerLoop
{
    private bool $groupEnsured = false;

    private readonly LoggerInterface $logger;

    /** @var (\Closure(): int)|null */
    private readonly ?\Closure $dueRetries;

    /**
     * @param (\Closure(): int)|null $dueRetries One due-retry pass (BackstopRunner::forDueRetries); returns how
     *                                          many rows it took.
     */
    public function __construct(
        private readonly StreamConsumerInterface $consumer,
        private readonly OutboxProcessorInterface $processor,
        private readonly int $blockMs = 5000,
        private readonly ?CascadeReconcilerInterface $cascade = null,
        ?LoggerInterface $logger = null,
        ?\Closure $dueRetries = null,
    ) {
        $this->logger     = $logger ?? new NullLogger();
        $this->dueRetries = $dueRetries;
    }

    public function tick(): void
    {
        if (!$this->groupEnsured) {
            $this->consumer->ensureGroup();
            $this->groupEnsured = true;
        }
        $this->consumer->readOnce(
            $this->blockMs,
            function (string $id): void {
                // The stream layer hands over a raw string, because the publish stream on the other
                // side of the same interface carries UUIDs. The outbox's unit of work is an integer
                // row id, so narrowing it — and rejecting anything that is not a positive integer —
                // is this layer's job now. This validation used to live in RedisStreamConsumer
                // itself, which logged 'outbox.consumer.bad_message' before discarding; moving the
                // check up here must not silently drop that observability along with it — a
                // non-numeric or non-positive id is exactly as malformed as the "no id at all" case
                // RedisStreamConsumer::readOnce() still logs and ACKs on its own.
                if (!ctype_digit($id) || (int) $id <= 0) {
                    $this->logger->warning('outbox.consumer.bad_message', ['id' => $id]);

                    return;
                }

                $rowId       = (int) $id;
                $disposition = $this->processor->processOne($rowId);
                if ($disposition === OutboxDisposition::BENIGN_SUCCESS && $this->cascade !== null) {
                    try {
                        $this->cascade->evaluate($rowId);
                    } catch (\Throwable) {
                        // Never fail the consumer over a cascade decision — the row
                        // is already in succeeded state and a future sibling success
                        // (or admin re-revoke) will trigger evaluate() again.
                    }
                }
            },
        );

        if ($this->dueRetries !== null) {
            try {
                ( $this->dueRetries )();
            } catch (\Throwable $e) {
                // Keep the stream flowing. The pass rolled back its own transaction, and the backstop still
                // takes any retry it could not.
                $this->logger->error('outbox.consumer.due_retries_failed', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Ticks until `$shouldStop` returns true — the job runner wires SIGTERM to it — or forever when it is
     * null. The 5-second blocking read bounds how long a stop takes.
     *
     * @param (callable(): bool)|null $shouldStop
     */
    public function run(?callable $shouldStop = null): void
    {
        while (!( $shouldStop !== null && $shouldStop() )) {
            $this->tick();
        }
    }
}
