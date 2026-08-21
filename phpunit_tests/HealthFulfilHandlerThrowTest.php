<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * A throw raised *inside* a fulfil handler still terminates the request (#823).
 *
 * ReactPHP does not invoke a `then()`'s sibling `onRejected` when its own `onFulfilled` throws: it
 * rejects the promise *derived* from that `then()` instead. So an exception raised inside a
 * fulfilment handler skipped the `sendComplete()` that followed it in the same handler, and a v2
 * client that stops on the terminal frame waited forever — the very wedge the frame exists to
 * prevent, arrived at from inside rather than outside.
 *
 * **This file is not {@see HealthTerminalFrameTest} with different names.** Every arm driven there
 * is settled by *rejecting* the outstanding request, which takes the sibling `onRejected` — the path
 * that has always worked. What is driven here is the other one: the request is settled
 * **successfully**, and the throw happens afterwards, in the handler that was invoked to report the
 * success. Copying a rejection test would have asserted nothing new.
 *
 * **How the throw is injected.** Everything a fulfil handler does with the outside world, it does
 * through the connection — the step emitters all funnel into `Health::sendMessage()`, which calls
 * `ConnectionInterface::send()`. So a connection that throws on one nominated frame puts the
 * exception exactly where the issue describes: partway through the fulfil handler, after some of
 * its frames have gone out and before it reaches its `sendComplete()`. It throws **once** and then
 * behaves normally, so the terminal frame that the recovery arm sends is observable rather than
 * lost to a second throw.
 *
 * The assertion in each case is the same and is deliberately about the *stream*, not a count: the
 * last frame the client receives says `complete`, and it says it once.
 *
 * Nothing here drives the ReactPHP loop. Queued HTTP requests are settled by hand exactly as
 * {@see HealthTerminalFrameTest} settles them, and the filesystem reads resolve synchronously
 * through `react/filesystem`'s Fallback adapter.
 */
#[CoversClass(Health::class)]
final class HealthFulfilHandlerThrowTest extends TestCase
{
    use HealthQueueIsolationTrait;

    /** A calendar body that decodes but lacks the four keys the JSON branch requires. */
    private const TRUNCATED_CALENDAR_BODY = '{"litcal":[]}';

    /** A calendar body complete enough for {@see \LiturgicalCalendar\Api\Test\LitTestRunner} to report on. */
    private const CALENDAR_BODY = '{"settings":{"year":2026,"national_calendar":"IT"},"litcal":[]}';

    private ?string $appEnvBackup = null;

    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
        CheckableInventory::reset();
    }

    public static function tearDownAfterClass(): void
    {
        CheckableInventory::reset();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Keeps handleHttpResponse() out of its development-only debug-logging branch.
        $this->appEnvBackup = isset($_ENV['APP_ENV']) && is_string($_ENV['APP_ENV']) ? $_ENV['APP_ENV'] : null;
        $_ENV['APP_ENV']    = 'test';
    }

    protected function tearDown(): void
    {
        if (null === $this->appEnvBackup) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $this->appEnvBackup;
        }
        parent::tearDown();
    }

    // ---------------------------------------------------------------- the five fulfil handlers

    /**
     * `runValidationSteps()`, the URL arm: a resource the API answered, whose reporting then threw.
     *
     * The fetch succeeded — this is the arm that reports a 200 — so the sibling `onRejected` is not
     * reachable from here at all, which is precisely why the terminal frame had nowhere else to come
     * from.
     */
    public function testAThrowInsideTheUrlFulfilHandlerStillTerminates(): void
    {
        $health = $this->newHealth();
        $conn   = self::createThrowingConnection(1);

        self::send($health, $conn, [
            'action'     => 'executeValidation',
            'category'   => 'resourceDataCheck',
            'validate'   => 'national-calendar-IT',
            'sourceFile' => 'https://example.test/data/nation/IT',
            'requestId'  => 'req-alpha'
        ]);
        self::fulfilQueuedRequest($health, 0, self::CALENDAR_BODY);

        self::assertTerminated($conn, 'req-alpha');
    }

    /**
     * `runValidationSteps()`, the filesystem arm: a source file that was read, whose reporting then
     * threw. The read resolves synchronously, so the throw lands in the fulfil handler while the
     * message that started the work is still on the stack.
     */
    public function testAThrowInsideTheFilesystemFulfilHandlerStillTerminates(): void
    {
        $conn = self::createThrowingConnection(1);

        self::send($this->newHealth(), $conn, [
            'action'    => 'validateSource',
            'target'    => ['id' => 'temporale:roman'],
            'requestId' => 'req-alpha'
        ]);

        self::assertTerminated($conn, 'req-alpha');
    }

    /**
     * `runValidationSteps()`, the i18n folder arm: the handler that runs once `Promise\all()` has
     * resolved every per-file read.
     *
     * This is the site the original report of #823 missed. Its per-file promises each handle their
     * own rejection, so the folder's own `onRejected` is reached only by something nothing
     * anticipated — and a throw in the summarising handler is not it: React rejects the derived
     * promise, not this one.
     */
    public function testAThrowInsideTheI18nFolderFulfilHandlerStillTerminates(): void
    {
        $conn = self::createThrowingConnection(1);

        self::send($this->newHealth(), $conn, [
            'action'    => 'validateSource',
            'target'    => ['id' => 'nation:roman:US:i18n'],
            'requestId' => 'req-alpha'
        ]);

        self::assertTerminated($conn, 'req-alpha');
    }

    /**
     * `validateCalendar()`: the largest cluster in `Health`, whose fulfil handler reaches its end
     * through a `switch` with an arm per response format. A throw anywhere in it — here in the
     * middle, after `exists` has been reported — skipped the terminal frame that sits after the
     * switch for exactly the opposite reason.
     */
    public function testAThrowInsideTheCalendarFulfilHandlerStillTerminates(): void
    {
        $health = $this->newHealth();
        $conn   = self::createThrowingConnection(1);

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'           => 2026,
            'responseFormat' => 'JSON',
            'requestId'      => 'req-alpha'
        ]);
        self::fulfilQueuedRequest($health, 0, self::TRUNCATED_CALENDAR_BODY);

        self::assertTerminated($conn, 'req-alpha');
    }

    /**
     * `executeUnitTest()`: a test run carries one result frame rather than three steps, so a throw
     * while emitting that one frame leaves the client with nothing at all — no result and, before
     * this fix, no terminal frame either.
     */
    public function testAThrowInsideTheTestRunFulfilHandlerStillTerminates(): void
    {
        $health = $this->newHealth();
        $conn   = self::createThrowingConnection(0);

        self::send($health, $conn, [
            'action'    => 'runTest',
            'test'      => 'TerminalHarnessAlpha',
            'calendar'  => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'      => 2026,
            'requestId' => 'req-alpha'
        ]);
        self::fulfilQueuedRequest($health, 0, self::CALENDAR_BODY);

        self::assertTerminated($conn, 'req-alpha');
    }

    // ---------------------------------------------------------------- and what the recovery is not

    /**
     * **The gate still holds on the recovery arm.** A client that did not correlate receives no
     * terminal frame when a handler throws, exactly as it receives none when one does not.
     *
     * The recovery would otherwise be a way for the frame to reach a v1 client after all — and a v1
     * `resources.js` sizes a phase as `checks * 3` and advances on `>=`, so a stray terminal frame
     * is counted toward whichever phase happens to be active: a cascading miscount with nothing
     * visibly failing. Asserted as an absence rather than as a count, because a count that happens
     * to be unchanged would also pass if the frame were arriving somewhere unexpected.
     */
    public function testNoTerminalFrameReachesAClientThatDidNotCorrelateEvenWhenAHandlerThrows(): void
    {
        $conn = self::createThrowingConnection(1);

        self::send($this->newHealth(), $conn, [
            'action' => 'validateSource',
            'target' => ['id' => 'temporale:roman']
        ]);

        $frames = self::framesOf($conn);
        self::assertNotEmpty($frames, 'precondition: the request was answered, so the absence below means something');
        self::assertNotContains('complete', self::stepsOf($frames), 'a v1 stream must be exactly what it was');
    }

    // ---------------------------------------------------------------- harness

    /**
     * The stream ends with exactly one terminal frame, carrying the id of the request that threw.
     *
     * "Exactly one" is asserted rather than "at least one": the recovery arm must not re-terminate a
     * request whose handler had already terminated it, and `sendComplete()` has no idempotency guard
     * of its own — by design, since one added in the wrong place is what would double-complete.
     */
    private static function assertTerminated(ConnectionInterface $conn, string $requestId): void
    {
        $frames = self::framesOf($conn);
        $steps  = self::stepsOf($frames);

        self::assertSame(
            1,
            count(array_filter($steps, static fn (?string $step): bool => 'complete' === $step)),
            'a request terminates once, and the stream it terminates was: ['
                . implode(', ', array_map(static fn (?string $s): string => $s ?? 'null', $steps)) . ']'
        );
        self::assertSame('complete', $steps[array_key_last($steps)], 'the terminal frame is the last thing the client hears');
        self::assertSame($requestId, $frames[array_key_last($frames)]->requestId);
    }

    /**
     * A Ratchet connection that records every outbound frame and throws on one nominated `send()`.
     *
     * `resourceId` is a dynamic public property Ratchet assigns and is not part of
     * `ConnectionInterface`, so this mirrors the stub convention the other Health tests use rather
     * than a PHPUnit mock, which would trigger a dynamic-property deprecation.
     *
     * @param int $throwOnSend The zero-based index of the `send()` call that throws. The frame it
     *        was given is *not* recorded, since it never reached the wire.
     */
    private static function createThrowingConnection(int $throwOnSend, int $resourceId = 1)
    {
        return new class ($throwOnSend, $resourceId) implements ConnectionInterface {
            /** @var list<string> */
            public array $sent = [];

            private int $sends = 0;

            public function __construct(private int $throwOnSend, public int $resourceId)
            {
            }

            public function send($data)
            {
                if ($this->sends++ === $this->throwOnSend) {
                    throw new \RuntimeException('a frame emitter threw inside the fulfil handler');
                }
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

    /**
     * The `step` of each frame in order, with a frame that carries none reported as `null` rather
     * than dropped, so a stream is compared as a whole.
     *
     * @param list<\stdClass> $frames
     * @return list<string|null>
     */
    private static function stepsOf(array $frames): array
    {
        return array_map(
            static fn (\stdClass $frame): ?string => property_exists($frame, 'step') && is_string($frame->step) ? $frame->step : null,
            $frames
        );
    }

    /**
     * Settle one outstanding request with a 200 carrying the given body, without dispatching it. The
     * queued entry holds the very `resolve` closure `cachedGet()` built for it, so calling it does
     * what the event loop would have done later.
     */
    private static function fulfilQueuedRequest(Health $health, int $index, string $body): void
    {
        /** @var list<array<string, mixed>> $queued */
        $queued = ( new \ReflectionProperty(Health::class, 'queue') )->getValue($health);
        self::assertArrayHasKey($index, $queued, "expected a queued request at index {$index}");
        /** @var \Closure(\Psr\Http\Message\ResponseInterface):void $resolve */
        $resolve = $queued[$index]['resolve'];

        ob_start();
        try {
            $resolve(new Response(200, ['Content-Type' => 'application/json'], $body));
        } finally {
            ob_end_clean();
        }
    }
}
