<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Services\Outbox\StreamConsumerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Long-lived consumer for the source-data publish stream, run as the job runner's `publish-consumer` job.
 *
 * # The message is a hint, not a work item
 *
 * A message says WHEN to look; Postgres says WHAT is claimable and by whom. So the batch id
 * carried by a message is used only for logging, and {@see PublishRunner::runOnce()} does the
 * ordinary claim — the message is never handed to the publisher, because `runOnce()` takes no
 * batch id at all. Three consequences, all of them the point:
 *
 * - A lost `XADD` costs latency, never correctness — the `publish-backstop` job finds the batch.
 * - A duplicate or out-of-order message costs one wasted claim against an empty queue.
 * - This class inherits every guarantee phase 2 built (the claim protocol, bounded attempts,
 *   parking, stop-don't-hammer) without reimplementing any of it.
 *
 * # This loop only consumes
 *
 * It used to keep its own in-memory schedule on the idle tick: a publish "recovery" run (reclaiming
 * a batch stranded `queued` by a killed publisher, retrying one whose backoff had elapsed) and a
 * rate-limited merge poll. Both are now registered jobs of the job runner (#1008) — `publish-backstop`
 * and `merge-poll` — whose schedule and last run live in `job_schedule` and show in `/health`. So an
 * idle tick does nothing here. Per-batch pacing still lives in the database (`next_attempt_at`, from
 * {@see PublishBackoff}), which is why running those jobs every minute is safe.
 *
 * # Nothing here may kill the consumer — and this catch is load-bearing, not decorative
 *
 * `PublishRunner` catches everything it can from its OWN collaborators (repository, publisher,
 * audit log) and reports it in its result. That is NOT the same as "an exception can never reach
 * this loop": it takes an ordinary `?LoggerInterface $logger`, and its own `catch (\Throwable)`
 * blocks call that logger from INSIDE themselves — a write that itself throws is not caught by the
 * block it is inside of. Phase 2 shipped an entry point wired to `LoggerFactory::create()`'s default
 * processors, which threw on any record whose context lacked `type => request|response`, so this is
 * not hypothetical. Every logger call here goes through {@see logSafely()}, which falls back to
 * `error_log()`, and the `try`/`catch` around `runOnce()` stands between a throwing logger and a
 * crashed process. See
 * {@see \LiturgicalCalendar\Tests\Services\SourceData\PublishConsumerLoopTest::testALoggerThatThrowsOnEveryWriteCannotKillTheConsumer()}
 * and {@see \LiturgicalCalendar\Tests\Services\SourceData\PublishConsumerLoopTest::testAPublishRunFailureDoesNotKillTheConsumer()}.
 *
 * A deliberate departure from {@see \LiturgicalCalendar\Api\Services\Outbox\ConsumerLoop},
 * which does NOT wrap its own `processor->processOne()` call and relies on its supervisor alone.
 */
final class PublishConsumerLoop
{
    private bool $groupEnsured = false;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly StreamConsumerInterface $consumer,
        private readonly PublishRunner $publisher,
        private readonly int $blockMs = 5000,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function tick(): void
    {
        try {
            if (!$this->groupEnsured) {
                $this->consumer->ensureGroup();
                $this->groupEnsured = true;
            }

            $this->consumer->readOnce(
                $this->blockMs,
                function (string $batchId): void {
                    $this->logSafely(
                        'info',
                        'Woken by an approved source-data batch; claiming from the database.',
                        ['batch_id' => $batchId]
                    );

                    // A batch that just failed is held back by its own `next_attempt_at`, so this
                    // needs no window of its own — see the class docblock's "The trap this walked
                    // into". The message is ACKed either way; nothing here is lost.
                    $this->publishSafely('Stream-driven', ['batch_id' => $batchId]);
                },
            );
        } catch (\Throwable $e) {
            // ensureGroup() and readOnce() sit OUTSIDE the try/catch above them on purpose — that
            // one only ever guards the publisher's own runOnce() call. A \RedisException from
            // xPending/xClaim/xReadGroup/xAck (a dropped connection, a Redis restart) would
            // otherwise propagate out of tick(), out of run(), and kill this long-lived process —
            // exactly the crash loop this class's own docblock ("Nothing here may kill the
            // consumer") argues against, reached one collaborator further down than the cases
            // that docblock already covers.
            //
            // groupEnsured resets to false so the NEXT tick re-runs ensureGroup() — the
            // connection may have dropped and a fresh one starts with no consumer group.
            $this->groupEnsured = false;

            $this->logSafely('error', 'Stream read failed; the consumer stays up.', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);

            // readOnce()'s BLOCK is what normally paces this loop; when it throws instead of
            // blocking, tick() would otherwise return immediately and run() would spin hot
            // against a still-failing Redis. usleep() here replaces exactly the wait the block
            // would have provided — and stays instant in tests that pass blockMs: 0.
            usleep($this->blockMs * 1000);
        }
    }

    /**
     * One publish run, with the catch that keeps a long-lived process alive.
     *
     * Keeps a throwing collaborator from taking the process down — see the class docblock's
     * "Nothing here may kill the consumer".
     *
     * @param array<string, mixed> $context Extra log context identifying the caller's trigger.
     */
    private function publishSafely(string $trigger, array $context = []): void
    {
        try {
            $result = $this->publisher->runOnce();
            $this->logSafely('info', $trigger . ' publish run finished.', $context + [
                'published'          => $result->published,
                'stopped_on_failure' => $result->stoppedOnFailure,
                'parked'             => $result->parkedBatches,
            ]);
        } catch (\Throwable $e) {
            // Reachable: PublishRunner's own catch blocks call its logger, and a logger whose
            // write throws escapes from inside them. See the class docblock's "Nothing here may
            // kill the consumer" section — this is load-bearing, not defensive-in-depth for an
            // impossible case.
            $this->logSafely('error', $trigger . ' publish run threw; the consumer stays up.', $context + [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log without ever letting the logger take the process down.
     *
     * The class docblock's "Nothing here may kill the consumer" argues that a `try`/`catch` around
     * each `runOnce()` call is load-bearing. It also conceded a hole: every one of those catch
     * blocks reports through this same logger, so a logger that throws for the record shape a
     * catch block writes would throw right back out of the block meant to contain it, and a
     * `never`-returning loop would die reporting the failure it had just survived. The upstream
     * mitigation ({@see SourceDataPublisherFactory::logger()}'s `includeProcessors: false`) keeps
     * the known-throwing processor out of the loggers this feature builds, but it cannot speak for
     * a logger someone else injects.
     *
     * So the last write is guarded too. `error_log()` is the fallback rather than a second
     * PSR-3 call: it is the one sink that cannot itself be the thing that is broken.
     *
     * @param 'info'|'error'       $level
     * @param array<string, mixed> $context
     */
    private function logSafely(string $level, string $message, array $context = []): void
    {
        try {
            $this->logger->{$level}($message, $context);
        } catch (\Throwable $loggingFailure) {
            error_log(sprintf(
                'PublishConsumerLoop: logger threw while reporting "%s" (%s: %s)',
                $message,
                $loggingFailure::class,
                $loggingFailure->getMessage()
            ));
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
