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
 * # Nothing here may kill the consumer — and this catch is load-bearing, not decorative
 *
 * `PublishRunner` and `MergePollRunner` catch everything they can from their OWN collaborators
 * (repository, publisher, GitHub client, purge service, audit log) and report it in their result
 * objects. That is NOT the same as "an exception can never reach this loop": both classes take an
 * ordinary, non-`final` `?LoggerInterface $logger` through their constructors, and their own
 * `catch (\Throwable)` blocks call that logger from INSIDE themselves — a write that itself
 * throws is not caught by the block it is inside of. `MergePollRunner::unpollableCountSafely()`
 * is the clearest case: its `warning()` call on a non-zero unpollable count sits entirely outside
 * any `try`/`catch`, at the very top of `runOnce()`, before that method's own first `try` block
 * even opens — so a throwing logger escapes immediately and completely.
 *
 * This is not hypothetical for this codebase: phase 2 shipped a cron entry point wired to
 * `LoggerFactory::create()`'s default processors, which threw on any record whose context lacked
 * `type => request|response` — meaning every log line either runner wrote, including the ones
 * inside their own defensive catch blocks, would have thrown in production. Note what this
 * class's OWN catch below does NOT protect against, though: both of its own `catch` blocks
 * report through that SAME `$this->logger`, so a logger that throws unconditionally for every
 * record would make this class's own `error()` call throw too, right back out of the catch that
 * was supposed to contain it. The actual fix for that specific, unconditional-throw scenario is
 * upstream, not here: {@see SourceDataPublisherFactory::logger()}'s `includeProcessors: false`
 * is what keeps the throwing processor out of every logger this feature constructs in the first
 * place. What THIS class's `try`/`catch` protects against is narrower but still real: a logger
 * that throws only for SOME records (the runners' own successful-path log calls carry different
 * context than their catch-block ones, so a processor keyed on record shape could throw for one
 * and not the other) and any other unexpected escape from a collaborator's logging call that
 * this class did not anticipate. Either way, the `try`/`catch` around each `runOnce()` call below
 * is what stands between that narrower failure mode and a crashed long-lived process; systemd
 * would restart it, but a crash loop against a failing logger is the hammering both runners
 * exist to avoid, reached one layer up instead of down. See
 * {@see \LiturgicalCalendar\Tests\Services\SourceData\PublishConsumerLoopTest::testAPublishRunFailureDoesNotKillTheConsumer()}
 * and
 * {@see \LiturgicalCalendar\Tests\Services\SourceData\PublishConsumerLoopTest::testAMergePollFailureDoesNotKillTheConsumer()},
 * which drive a real `PublishRunner` / `MergePollRunner` with a throwing `LoggerInterface` double
 * to pin exactly this path, and were confirmed to fail (with the escaped exception) when this
 * class's own catch is removed.
 *
 * A deliberate departure from {@see \LiturgicalCalendar\Api\Services\Outbox\ConsumerLoop},
 * which does NOT wrap its own `processor->processOne()` call and relies on systemd alone — not a
 * habit copied without thought, but a stronger guarantee this class chooses to provide because the
 * failure mode above is real here.
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
 *
 * # The recovery tick, and why cron is no longer the retry mechanism
 *
 * A message wakes a publish run, which is latency. It is not, and must not be, what GUARANTEES a
 * run happens: three jobs need doing when no new approval is arriving, and until the recovery tick
 * existed only cron did them.
 *
 * - **Stale-claim reclamation.** {@see PublishRunner::runOnce()} reclaims at the top of every run.
 *   A consumer killed mid-publish (a restart, an OOM kill, a deploy) leaves its batch `queued`;
 *   with no subsequent message, nothing reclaims it.
 * - **Retry of a failed batch.** A release returns the batch to `none` and spends an attempt. The
 *   next run is the retry.
 * - **Draining after a failure.** An earlier revision of this class ACKed and DISCARDED messages
 *   that arrived inside a coarse post-failure window, on the stated basis that cron was the
 *   backstop — so the batches those messages announced waited for cron regardless.
 *
 * Events should wake the worker for latency; durable state plus periodic reconciliation should
 * guarantee eventual execution. Cron is one implementation of reconciliation, not something using
 * Redis obliges. So the idle branch runs a publish as well as a merge poll, and cron drops to what
 * it should always have been: an operational safety net for "the worker is dead", which is a
 * different question from "this batch is due".
 *
 * # The trap this walked into, and where the pacing actually lives
 *
 * Calling `runOnce()` from the idle branch is only safe BECAUSE the scheduling landed first. Left
 * unpaced, a deterministically-failing batch is re-attempted on every tick and exhausts
 * {@see \LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository::MAX_PUBLISH_ATTEMPTS}
 * in five ticks rather than five cron intervals — the same failure the whole-branch review of the
 * merge-detection work found on the MESSAGE path, mitigated there with a coarse suppression window
 * that this class no longer needs and no longer has.
 *
 * The pacing is now per batch and lives in the database: `next_attempt_at`, written by
 * `releaseClaim()` from
 * {@see \LiturgicalCalendar\Api\Services\SourceData\PublishBackoff} and read by the claim
 * predicate. A batch that just failed is simply not claimable yet, so a recovery tick that fires
 * every few seconds finds nothing to do and costs one indexed query — while a batch whose failure
 * was transient still recovers without waiting for a human. That is strictly better than the
 * window it replaces, which paused the whole publisher (including batches that had never failed)
 * for a fixed interval keyed on one batch's bad luck.
 *
 * The tick is still rate-limited, at `$recoveryTickIntervalSeconds`, for a different and much
 * smaller reason than the window was: `runOnce()` opens a transaction and reclaims stale claims
 * before it looks at anything, so running it on every 5-second block would be steady write traffic
 * against Postgres in exchange for latency nothing is waiting on. The message path is what makes
 * an approval fast; this only has to be faster than cron.
 */
final class PublishConsumerLoop
{
    private bool $groupEnsured = false;

    private ?int $lastMergePollAt = null;

    private ?int $lastRecoveryTickAt = null;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly StreamConsumerInterface $consumer,
        private readonly PublishRunner $publisher,
        /** Null disables the idle poll; the cron entry point still runs it. */
        private readonly ?MergePollRunner $mergePoller = null,
        private readonly int $blockMs = 5000,
        private readonly int $mergePollIntervalSeconds = 60,
        /** Its own knob, not the merge poll's: these two pace different collaborators. */
        private readonly int $recoveryTickIntervalSeconds = 60,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function tick(): void
    {
        $woken = false;

        try {
            if (!$this->groupEnsured) {
                $this->consumer->ensureGroup();
                $this->groupEnsured = true;
            }

            $this->consumer->readOnce(
                $this->blockMs,
                function (string $batchId) use (&$woken): void {
                    $woken = true;

                    $this->logger->info(
                        'Woken by an approved source-data batch; claiming from the database.',
                        ['batch_id' => $batchId]
                    );

                    // A batch that just failed is held back by its own `next_attempt_at`, so this
                    // needs no window of its own — see the class docblock's "The trap this walked
                    // into". The message is ACKed either way; nothing here is lost.
                    $this->publishSafely('Stream-driven', ['batch_id' => $batchId]);

                    // A stream-driven run is a publish run like any other, so it also satisfies
                    // the recovery tick — otherwise a busy stream would keep firing an extra,
                    // redundant idle run the moment traffic paused.
                    $this->lastRecoveryTickAt = time();
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

            $this->logger->error('Stream read failed; the consumer stays up.', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);

            // readOnce()'s BLOCK is what normally paces this loop; when it throws instead of
            // blocking, tick() would otherwise return immediately and run() would spin hot
            // against a still-failing Redis. usleep() here replaces exactly the wait the block
            // would have provided — and stays instant in tests that pass blockMs: 0.
            usleep($this->blockMs * 1000);
        }

        // Runs on every path where the stream did not hand over a message, deliberately
        // including the failure path above: the merge poll depends on Postgres and GitHub, not
        // Redis, so a stream outage is not a reason to stop finding merged pull requests — it is
        // still rate-limited to $mergePollIntervalSeconds by pollMergesIfDue() itself, so a
        // hot-spinning stream failure cannot turn this into hammering GitHub either.
        if (!$woken) {
            $this->runPublishRecoveryIfDue();
            $this->pollMergesIfDue();
        }
    }

    /**
     * One publish run, with the catch that keeps a long-lived process alive.
     *
     * Shared by the message path and the recovery tick so the two cannot drift in how they
     * report, or in whether they survive a throwing collaborator — see the class docblock's
     * "Nothing here may kill the consumer".
     *
     * @param array<string, mixed> $context Extra log context identifying the caller's trigger.
     */
    private function publishSafely(string $trigger, array $context = []): void
    {
        try {
            $result = $this->publisher->runOnce();
            $this->logger->info($trigger . ' publish run finished.', $context + [
                'published'          => $result->published,
                'stopped_on_failure' => $result->stoppedOnFailure,
                'parked'             => $result->parkedBatches,
            ]);
        } catch (\Throwable $e) {
            // Reachable: PublishRunner's own catch blocks call its logger, and a logger whose
            // write throws escapes from inside them. See the class docblock's "Nothing here may
            // kill the consumer" section — this is load-bearing, not defensive-in-depth for an
            // impossible case.
            $this->logger->error($trigger . ' publish run threw; the consumer stays up.', $context + [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * The idle-branch publish run: reclaims stale claims, and retries batches whose backoff has
     * elapsed, with no message required. See the class docblock's "The recovery tick".
     */
    private function runPublishRecoveryIfDue(): void
    {
        $now = time();
        if (null !== $this->lastRecoveryTickAt && ( $now - $this->lastRecoveryTickAt ) < $this->recoveryTickIntervalSeconds) {
            return;
        }
        $this->lastRecoveryTickAt = $now;

        $this->publishSafely('Recovery-tick');
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
            // Reachable: MergePollRunner::unpollableCountSafely()'s warning() call sits outside
            // any try/catch at the very top of runOnce(), so a throwing logger escapes cleanly.
            // See the class docblock's "Nothing here may kill the consumer" section.
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
