<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Tests\Support\AuthorizedHealthTrait;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * A queued request whose run is abandoned still terminates — and says it was cancelled (#837).
 *
 * `dropSupersededQueuedRequests()` used to filter superseded entries out of the queue with
 * `array_filter`, discarding each entry's deferred along with it. The promise handed to the caller
 * was then never settled — not resolved, not rejected — so neither the fulfil handler nor the
 * sibling `onRejected` ever ran, and a v2 client that stops on the terminal frame, exactly as the
 * design tells it to, waited forever.
 *
 * **This is not {@see HealthFulfilHandlerThrowTest} with different names, nor
 * {@see HealthTerminalFrameTest}.** Those two drive requests that *were* settled — successfully and
 * then thrown from, or rejected outright. Nothing is settled here at all: the work is taken off the
 * queue before it is ever dispatched, which is why neither of the tail handlers #823 attached can
 * reach it.
 *
 * **The two call sites are driven separately, because they are reached differently.** `cancelRun()`
 * is the explicit one — the client says stop. `processQueue()` is the one that is easy to miss: it
 * drops superseded entries on *every* queue pass, so an ordinary run-token change supersedes queued
 * work with no cancellation anywhere in sight.
 *
 * **What termination looks like here, and why it is not an error frame.** A cancelled request emits
 * its terminal frame and nothing else: no `exists(fail)`, no error text. The check never ran, so a
 * step frame reporting its outcome would be an untruth of exactly the kind #822/#833/#834/#835 were
 * filed to remove — and `validateCalendar()`'s rejection arm would have said the calendar "does not
 * exist at the URL", which is a statement about the API, not about a run the client stopped. The
 * terminal frame carries `cancelled: true` instead, so a client can tell a run it abandoned from one
 * that failed without a new frame being added to a stream a v1 client sizes as `checks * 3`.
 *
 * Nothing here drives the ReactPHP loop: a queued entry is an inert record until something settles
 * it, which is what makes it assertable.
 */
#[CoversClass(Health::class)]
final class HealthSupersededRequestTest extends TestCase
{
    use HealthQueueIsolationTrait;

    // This suite exercises behaviour downstream of the #894 permission gate; it drives
    // onMessage() without a handshake, so without this every message would be refused.
    use AuthorizedHealthTrait;

    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
        CheckableInventory::reset();
    }

    public static function tearDownAfterClass(): void
    {
        CheckableInventory::reset();
    }

    // ---------------------------------------------------------------- call site 1: cancelRun()

    /**
     * The explicit cancellation: `cancelRun()` clears the connection's stored token and drops the
     * backlog that belonged to it. The resource check queued below had emitted nothing yet — it was
     * still waiting for its turn — so the terminal frame is the only frame this request ever sends,
     * and the client stops on it instead of waiting for a fetch that will never happen.
     */
    public function testAnExplicitCancelTerminatesItsQueuedRequest(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'     => 'executeValidation',
            'category'   => 'resourceDataCheck',
            'validate'   => 'national-calendar-IT',
            'sourceFile' => 'https://example.test/data/nation/IT',
            'runToken'   => 'run-a',
            'requestId'  => 'req-alpha'
        ]);
        self::assertCount(1, self::queuedRequests($health), 'precondition: the request is queued, not yet dispatched');
        self::assertSame([], $conn->sent, 'precondition: a queued request has emitted nothing, so what follows is all of it');

        self::send($health, $conn, ['action' => 'cancelRun', 'runToken' => 'run-a']);

        self::assertSame([], self::queuedRequests($health), 'the cancelled run keeps no queued work');
        self::assertCancelled($conn, 'req-alpha', 'run-a');
    }

    // ---------------------------------------------------------------- call site 2: processQueue()

    /**
     * The unobvious one: no cancellation is sent at all. The client simply starts a new run, the
     * connection's stored token advances, and the next queue pass finds the previous run's queued
     * work superseded. `processQueue()` runs that pass on every tick, so this is the common path, not
     * the exotic one.
     *
     * The second message is a `validateCalendar` missing every property but `runToken`: it stores the
     * new token and is then refused by `WebSocketMessageValidator`, so it advances the run without
     * queueing work of its own — the same technique `HealthCancelRunTest` uses to drive a token change
     * through the real entry point.
     */
    public function testARunTokenChangeTerminatesTheSupersededQueuedRequest(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'           => 2026,
            'responseFormat' => 'JSON',
            'runToken'       => 'run-a',
            'requestId'      => 'req-alpha'
        ]);
        self::assertCount(1, self::queuedRequests($health), 'precondition: the calendar fetch is queued');

        // The run advances: same connection, new token, no cancelRun anywhere.
        self::send($health, $conn, ['action' => 'validateCalendar', 'runToken' => 'run-b']);
        self::processQueue($health);

        self::assertSame([], self::queuedRequests($health), 'the superseded run keeps no queued work');
        self::assertCancelled($conn, 'req-alpha', 'run-a');
    }

    /**
     * The third and last consumer of `cachedGet()` that reports to a client: a test run. It carries
     * one result frame rather than three steps, so a dropped one leaves the client with nothing at
     * all unless the terminal frame is sent.
     */
    public function testASupersededTestRunTerminatesToo(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'    => 'runTest',
            'test'      => 'TerminalHarnessAlpha',
            'calendar'  => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'      => 2026,
            'runToken'  => 'run-a',
            'requestId' => 'req-alpha'
        ]);
        self::assertCount(1, self::queuedRequests($health), 'precondition: the test run\'s calendar fetch is queued');

        self::send($health, $conn, ['action' => 'cancelRun', 'runToken' => 'run-a']);

        self::assertCancelled($conn, 'req-alpha', 'run-a');
    }

    // ---------------------------------------------------------------- and what termination is not

    /**
     * **A cancelled request does not report a failed check.** The queued work never ran, so nothing
     * can be said about whether the resource exists, parses or validates — and the rejection arm this
     * fix deliberately does not route through would have said the calendar "does not exist at the
     * URL", a statement about the API rather than about a run the client stopped.
     *
     * Asserted as the absence of any `status`-bearing frame rather than by matching text, so it keeps
     * holding if the wording of those frames changes.
     */
    public function testACancelledRequestReportsNoStepOutcomeAtAll(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'           => 2026,
            'responseFormat' => 'JSON',
            'runToken'       => 'run-a',
            'requestId'      => 'req-alpha'
        ]);
        self::send($health, $conn, ['action' => 'cancelRun', 'runToken' => 'run-a']);

        $frames = self::framesOf($conn);
        self::assertNotEmpty($frames, 'precondition: the request was answered, so what is asserted below is not vacuous');
        foreach ($frames as $frame) {
            self::assertFalse(property_exists($frame, 'status'), 'a request that never ran reports no step outcome');
            self::assertNotSame('error', $frame->type ?? null, 'a cancelled run is not a failed one');
        }
    }

    /**
     * **The `requestId` gate holds here too.** A client that did not correlate receives nothing when
     * its queued work is dropped, exactly as it receives nothing when a handler throws (#823) and
     * nothing when a request completes normally.
     *
     * This is the one thing a fix here could break that nothing else would catch: `resources.js`
     * sizes a phase as `checks * 3` and advances on `>=`, so a terminal frame reaching a v1 client
     * finishes the *following* phase early too — a cascading miscount with nothing visibly failing.
     */
    public function testAClientThatDidNotCorrelateHearsNothingWhenItsWorkIsDropped(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'     => 'executeValidation',
            'category'   => 'resourceDataCheck',
            'validate'   => 'national-calendar-IT',
            'sourceFile' => 'https://example.test/data/nation/IT',
            'runToken'   => 'run-a'
        ]);
        self::assertCount(1, self::queuedRequests($health), 'precondition: the request was started, so the silence below means something');

        self::send($health, $conn, ['action' => 'cancelRun', 'runToken' => 'run-a']);

        self::assertSame([], self::queuedRequests($health), 'precondition: the queued work really was dropped');
        self::assertSame([], $conn->sent, 'a v1 stream is exactly what it was: no terminal frame, cancelled or otherwise');
    }

    /**
     * **One request's termination must not take the rest of the batch with it.** A terminator sends a
     * frame, and a frame's journey out ends in Ratchet and then in a socket — a `send()` on a
     * connection that has just gone away throws, and it would otherwise do so partway through a queue
     * pass, leaving the remaining superseded requests unterminated and taking `processQueue()` down
     * with it.
     *
     * The queue is seeded directly here rather than through `cachedGet()`: what is under test is the
     * drop site's own containment, and a terminator that throws on demand is the whole point.
     */
    public function testATerminatorThatThrowsDoesNotStopTheRestOfTheBatch(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        $terminated = [];
        self::setRunTokens($health, [1 => 'run-a', 2 => 'run-b']);
        self::setQueue($health, [
            self::queueEntry('https://example.test/throws', 1, 'run-a', static function (): void {
                throw new \RuntimeException('the client went away mid-cancellation');
            }),
            self::queueEntry('https://example.test/after', 1, 'run-a', static function () use (&$terminated): void {
                $terminated[] = 'after';
            }),
            self::queueEntry('https://example.test/other-connection', 2, 'run-b', static function () use (&$terminated): void {
                $terminated[] = 'other-connection';
            })
        ]);

        self::send($health, $conn, ['action' => 'cancelRun', 'runToken' => 'run-a']);

        self::assertSame(['after'], $terminated, 'the entry behind the throwing one is still terminated, and no surviving entry is');
        self::assertSame(
            ['https://example.test/other-connection'],
            array_column(self::queuedRequests($health), 'url'),
            'the other connection keeps the work it is still running'
        );
    }

    // ---------------------------------------------------------------- harness

    /**
     * The client heard exactly one frame about this request, it was the terminal one, and it says the
     * request was cancelled rather than finished.
     *
     * "Exactly one" is the point of the assertion, not a bonus: `sendComplete()` has no idempotency
     * guard, by design, so a fix that both terminated the dropped entry *and* let its promise arms
     * run would double-complete and a client counting nothing would still be told twice.
     */
    private static function assertCancelled(ConnectionInterface $conn, string $requestId, string $runToken): void
    {
        $frames    = self::framesOf($conn);
        $terminals = array_values(array_filter(
            $frames,
            static fn (\stdClass $frame): bool => 'complete' === ( $frame->step ?? null ) && $requestId === ( $frame->requestId ?? null )
        ));

        self::assertCount(
            1,
            $terminals,
            'a superseded request terminates exactly once; the stream was: [' . implode(', ', array_map(
                static fn (\stdClass $f): string => is_string($f->step ?? null) ? $f->step : ( is_string($f->type ?? null) ? $f->type : '?' ),
                $frames
            )) . ']'
        );

        $terminal = $terminals[0];
        self::assertTrue(property_exists($terminal, 'cancelled'), 'the terminal frame of a cancelled request says so');
        self::assertTrue($terminal->cancelled, 'a cancelled request is marked cancelled, not merely complete');
        self::assertSame($runToken, $terminal->runToken, 'the frame belongs to the run that was abandoned, not to whatever replaced it');
        self::assertSame('complete', $terminal->step);
    }

    /**
     * A minimal Ratchet connection that records every outbound frame. `resourceId` is a dynamic
     * public property Ratchet assigns and is not part of `ConnectionInterface`, so this mirrors the
     * stub convention the other Health tests use rather than a PHPUnit mock, which would trigger a
     * dynamic-property deprecation.
     */
    private static function createStubConnection(int $resourceId = 1)
    {
        return new class ($resourceId) implements ConnectionInterface {
            /** @var list<string> */
            public array $sent = [];

            public function __construct(public int $resourceId)
            {
            }

            public function send($data)
            {
                $this->sent[] = (string) $data;

                return $this;
            }

            public function close()
            {
            }
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function send(Health $health, ConnectionInterface $conn, array $payload): void
    {
        ob_start();
        try {
            $health->onMessage($conn, (string) json_encode($payload));
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Run one queue pass, which is what the event loop does on every tick. Nothing is dispatched: the
     * only queued entry belongs to a superseded run and is dropped before the dispatch loop is
     * reached, so no HTTP request leaves this test.
     */
    private static function processQueue(Health $health): void
    {
        $method = new \ReflectionMethod(Health::class, 'processQueue');
        ob_start();
        try {
            $method->invoke($health);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * A queue entry shaped like the ones `cachedGet()` enqueues, for the one test that seeds the
     * queue by hand rather than by sending a message. `resolve` and `reject` are never reached: a
     * dropped entry's promise is deliberately left unsettled.
     *
     * @return array<string, mixed>
     */
    private static function queueEntry(string $url, ?int $resourceId, ?string $runToken, \Closure $onSuperseded): array
    {
        return [
            'url'          => $url,
            'options'      => [],
            'resolve'      => static function (): void {
            },
            'reject'       => static function (): void {
            },
            'resourceId'   => $resourceId,
            'runToken'     => $runToken,
            'onSuperseded' => $onSuperseded
        ];
    }

    /** @param list<array<string, mixed>> $queue */
    private static function setQueue(Health $health, array $queue): void
    {
        ( new \ReflectionProperty(Health::class, 'queue') )->setValue($health, $queue);
    }

    /** @param array<int, string> $tokens */
    private static function setRunTokens(Health $health, array $tokens): void
    {
        ( new \ReflectionProperty(Health::class, 'runTokens') )->setValue($health, $tokens);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function queuedRequests(Health $health): array
    {
        /** @var list<array<string, mixed>> */
        return ( new \ReflectionProperty(Health::class, 'queue') )->getValue($health);
    }

    /**
     * @return list<\stdClass>
     */
    private static function framesOf(ConnectionInterface $conn): array
    {
        /** @var object{sent: list<string>} $conn */
        return array_map(
            /** @return \stdClass */
            static fn (string $raw): object => (object) json_decode($raw),
            $conn->sent
        );
    }
}
