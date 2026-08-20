<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * `cancelRun` — the client telling the server a run was abandoned.
 *
 * Stopping a run used to be purely client-side: the runner dropped incoming frames while the server
 * kept working through the abandoned run's backlog. The server already knew how to discard such
 * requests — `dropSupersededQueuedRequests()` filters queue entries whose `runToken` no longer matches
 * the connection's stored token — but only a *restart* ever advanced that token. `cancelRun` clears it
 * on demand, which is why these tests assert on the queue rather than on any response frame: the
 * action is deliberately silent.
 *
 * See UnitTestInterface#43 and #806 section H.
 */
#[CoversClass(Health::class)]
final class HealthCancelRunTest extends TestCase
{
    // These tests seed the queue directly rather than through cachedGet(), so nothing here is
    // currently dispatched at shutdown — but the protection belongs to anything that builds a
    // Health, not to whichever file last remembered it. See the trait.
    use HealthQueueIsolationTrait;

    /**
     * A minimal Ratchet connection that records every outbound frame. `resourceId` is a dynamic public
     * property Ratchet assigns and is not part of `ConnectionInterface`, so this mirrors the stub
     * convention already used by HealthFolderStepResultTest rather than a PHPUnit mock, which would
     * trigger a dynamic-property deprecation.
     */
    private static function createStubConnection(int $resourceId)
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
     * A queue entry shaped like the ones `cachedGet()` enqueues. The promise callbacks are never
     * invoked here — these tests only ever inspect which entries survive the filter.
     *
     * @return array<string, mixed>
     */
    private static function queueEntry(string $url, ?int $resourceId, ?string $runToken): array
    {
        return [
            'url'        => $url,
            'options'    => [],
            'resolve'    => static function (): void {
            },
            'reject'     => static function (): void {
            },
            'resourceId' => $resourceId,
            'runToken'   => $runToken
        ];
    }

    /** @param list<array<string, mixed>> $queue */
    private static function setQueue(Health $health, array $queue): void
    {
        ( new \ReflectionProperty(Health::class, 'queue') )->setValue($health, $queue);
    }

    /** @return list<array<string, mixed>> */
    private static function getQueue(Health $health): array
    {
        /** @var list<array<string, mixed>> */
        return ( new \ReflectionProperty(Health::class, 'queue') )->getValue($health);
    }

    /** @param array<int, string> $tokens */
    private static function setRunTokens(Health $health, array $tokens): void
    {
        ( new \ReflectionProperty(Health::class, 'runTokens') )->setValue($health, $tokens);
    }

    /** @return array<int, string> */
    private static function getRunTokens(Health $health): array
    {
        /** @var array<int, string> */
        return ( new \ReflectionProperty(Health::class, 'runTokens') )->getValue($health);
    }

    /** @param array<string, string> $payload */
    private static function cancel(Health $health, ConnectionInterface $conn, array $payload): void
    {
        $health->onMessage($conn, (string) json_encode($payload));
    }

    public function testCancellingTheCurrentRunDropsItsQueuedRequestsAndSaysNothing(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection(1);

        self::setRunTokens($health, [1 => 'run-a']);
        self::setQueue($health, [
            self::queueEntry('https://example.test/a', 1, 'run-a'),
            self::queueEntry('https://example.test/b', 1, 'run-a')
        ]);

        self::cancel($health, $conn, ['action' => 'cancelRun', 'runToken' => 'run-a']);

        self::assertSame([], self::getQueue($health), 'the cancelled run keeps no queued work');
        self::assertSame([], self::getRunTokens($health), 'the connection is no longer on any run');
        self::assertSame([], $conn->sent, 'cancelRun is acknowledged by silence, not by a frame');
    }

    public function testUntaggedAndOtherConnectionsRequestsSurvive(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection(1);

        self::setRunTokens($health, [1 => 'run-a', 2 => 'run-b']);
        self::setQueue($health, [
            // The connect-time metadata fetch carries no run token and belongs to no run.
            self::queueEntry('https://example.test/metadata', null, null),
            self::queueEntry('https://example.test/mine', 1, 'run-a'),
            self::queueEntry('https://example.test/theirs', 2, 'run-b')
        ]);

        self::cancel($health, $conn, ['action' => 'cancelRun', 'runToken' => 'run-a']);

        self::assertSame(
            ['https://example.test/metadata', 'https://example.test/theirs'],
            array_column(self::getQueue($health), 'url')
        );
        self::assertSame([2 => 'run-b'], self::getRunTokens($health), 'the other connection keeps its run');
    }

    public function testAStaleCancelDoesNotTouchTheRunThatReplacedIt(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection(1);

        // The user stopped and restarted faster than the cancel frame travelled: the connection is
        // already on run-b when the cancel naming run-a arrives.
        self::setRunTokens($health, [1 => 'run-b']);
        self::setQueue($health, [self::queueEntry('https://example.test/new-run', 1, 'run-b')]);

        self::cancel($health, $conn, ['action' => 'cancelRun', 'runToken' => 'run-a']);

        self::assertCount(1, self::getQueue($health), 'the new run keeps its queued work');
        self::assertSame([1 => 'run-b'], self::getRunTokens($health), 'the new run keeps its token');
        self::assertSame([], $conn->sent);
    }

    public function testACancelWithoutARunTokenIsRejectedAndChangesNothing(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection(1);

        self::setRunTokens($health, [1 => 'run-a']);
        self::setQueue($health, [self::queueEntry('https://example.test/a', 1, 'run-a')]);

        self::cancel($health, $conn, ['action' => 'cancelRun']);

        self::assertCount(1, self::getQueue($health));
        self::assertSame([1 => 'run-a'], self::getRunTokens($health));

        self::assertCount(1, $conn->sent, 'a malformed cancel is a protocol error, and those are visible');
        $frame = json_decode($conn->sent[0]);
        self::assertSame('echobot', $frame->type);
        self::assertSame('Invalid message properties', $frame->errorMsg);
    }

    /**
     * Regression test for the `$messageReceived->action !== 'cancelRun'` exemption on the ambient
     * run-token store at the top of `onMessage()`. If that comparison were ever accidentally inverted
     * to `===`, every *non*-cancelRun message would stop storing its token — `cancelRun` would keep
     * working, but every ordinary run would silently lose response correlation, and a green suite
     * would not notice, because all the other `runToken` coverage in this file seeds `$runTokens` by
     * reflection and calls private handlers directly rather than driving them through `onMessage()`.
     *
     * `validateCalendar` is used as the "ordinary message" here because, sent with only `runToken`, it
     * is missing every other required property (`category`, `calendar`, `year`, `responsetype`), so
     * `validateMessageProperties()` rejects it and `onMessage()` falls straight to the protocol-error
     * path — confirmed by the asserted echoed frame below — without dispatching an HTTP request or file
     * read. That is what makes it safe to drive through the real entry point in a unit test.
     */
    public function testAnOrdinaryMessageStillStoresItsRunToken(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection(1);

        $health->onMessage($conn, (string) json_encode(['action' => 'validateCalendar', 'runToken' => 'run-a']));

        self::assertSame([1 => 'run-a'], self::getRunTokens($health), 'an ordinary message still stores its run token');

        self::assertCount(1, $conn->sent, 'confirms the message short-circuited on the protocol-error path, not real work');
        $frame = json_decode($conn->sent[0]);
        self::assertSame('Invalid message properties', $frame->errorMsg, 'confirms it never reached the validateCalendar dispatch');
    }

    /**
     * `validateMessageProperties()` checks only that `runToken` is *present*, not that it is a string —
     * `{"action":"cancelRun","runToken":null}` (same for an array or object) passes validation and
     * reaches `cancelRun()`. Before its `is_string()` guard, PHP's weak-mode parameter coercion throws
     * `TypeError` for a non-string argument against a `string` parameter; Ratchet's `IoServer::handleData`
     * only catches `\Exception`, so that `\Error` would escape uncaught and take the whole WebSocket
     * process down over one malformed cancel. A cancel the server cannot act on is already a documented
     * no-op for a stale token (see `cancelRun()`'s docblock); this asserts a non-string token folds into
     * that same no-op instead of crashing.
     */
    public function testACancelWithANonStringRunTokenIsASilentNoOp(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection(1);

        self::setRunTokens($health, [1 => 'run-a']);
        self::setQueue($health, [self::queueEntry('https://example.test/a', 1, 'run-a')]);

        $health->onMessage($conn, (string) json_encode(['action' => 'cancelRun', 'runToken' => null]));

        self::assertCount(1, self::getQueue($health), 'the queued request survives an unusable cancel');
        self::assertSame([1 => 'run-a'], self::getRunTokens($health), 'the stored token is untouched');
        self::assertSame([], $conn->sent, 'a cancel the server cannot act on stays silent, same as a stale token');
    }
}
