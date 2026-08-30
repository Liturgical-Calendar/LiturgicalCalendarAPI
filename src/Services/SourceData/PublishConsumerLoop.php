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
 * # Publish-failure backoff — the stream removes the interval the attempt bound assumed
 *
 * {@see \LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository::MAX_PUBLISH_ATTEMPTS}
 * was sized against cron's fixed interval: a handful of consecutive failures spread over a
 * handful of cron ticks before a batch parks. The stream deletes that spacing. `readOnce()`'s
 * `COUNT` is 1, so a backlog of queued `XADD`s drains one message per wake with no block in
 * between — during a GitHub outage, every queued approval wakes `tick()` straight into
 * `runOnce()` again, and each failing call increments `publish_attempts` (via
 * `releaseClaim()`) with none of the spacing the bound assumed. A handful of approvals that
 * arrive while GitHub is down can therefore park the SAME oldest batch in the time a handful
 * of failed HTTP calls take, rather than the time a handful of cron intervals take.
 *
 * The fix mirrors this class's own idle-tick rate limit immediately above: a `runOnce()` that
 * comes back with {@see PublishRunResult::$stoppedOnFailure} suppresses further stream-driven
 * publish attempts for `$mergePollIntervalSeconds` — the SAME interval as the idle merge poll,
 * not a dedicated one, because both exist for the same reason: to keep a bounded, rate-limited
 * resource (a GitHub API budget) from being spent faster than cron itself would spend it, and
 * one configuration knob for "how patient is this consumer with a wounded GitHub" is easier to
 * reason about than two. A suppressed wake still ACKs its message normally — nothing is lost,
 * because cron remains the backstop throughout — and logs at info so an operator can see the
 * backoff engage rather than mistake a quiet consumer for a stuck one.
 */
final class PublishConsumerLoop
{
    private bool $groupEnsured = false;

    private ?int $lastMergePollAt = null;

    /** Set to `time()` when a stream-driven publish run stops on failure; see class docblock. */
    private ?int $lastPublishFailureAt = null;

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

                    if ($this->publishBackoffActive()) {
                        $this->logger->info(
                            'Stream-driven publish run suppressed by the post-failure backoff; '
                                . 'the message is acknowledged and cron remains the backstop.',
                            ['batch_id' => $batchId, 'backoff_seconds' => $this->mergePollIntervalSeconds]
                        );

                        return;
                    }

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

                        // See class docblock's "Publish-failure backoff" section: a failed run
                        // starts the backoff window; a successful one clears it.
                        $this->lastPublishFailureAt = $result->stoppedOnFailure ? time() : null;
                    } catch (\Throwable $e) {
                        // Reachable: PublishRunner's own catch blocks call its logger, and a logger
                        // whose write throws escapes from inside them. See the class docblock's
                        // "Nothing here may kill the consumer" section — this is load-bearing, not
                        // defensive-in-depth for an impossible case.
                        $this->logger->error('Stream-driven publish run threw; the consumer stays up.', [
                            'batch_id'  => $batchId,
                            'exception' => $e::class,
                            'message'   => $e->getMessage(),
                        ]);

                        $this->lastPublishFailureAt = time();
                    }
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
            $this->pollMergesIfDue();
        }
    }

    /** True while a stream-driven publish run stays suppressed after a prior failure. */
    private function publishBackoffActive(): bool
    {
        if (null === $this->lastPublishFailureAt) {
            return false;
        }

        return ( time() - $this->lastPublishFailureAt ) < $this->mergePollIntervalSeconds;
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
