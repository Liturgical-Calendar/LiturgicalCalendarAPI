<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\ProtocolErrorCode;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Services\WebSocketMessageValidator;
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
 * on demand, which is why most of these tests assert on the queue rather than on any response frame:
 * a cancel the server *can* act on, or a stale one it deliberately ignores, is silent. A cancel the
 * server cannot even parse — a non-string `runToken` — is a different case and is now refused with a
 * frame; see {@see testACancelWithANonStringRunTokenIsRejectedByTheSchema()}.
 *
 * See UnitTestInterface#43 and #806 section H.
 */
#[CoversClass(Health::class)]
#[CoversClass(WebSocketMessageValidator::class)]
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
     * `onSuperseded` is the terminator a dropped entry is ended by (#837) and is a no-op here for the
     * same reason the other two callbacks are: what these tests assert is which entries survive, not
     * what a dropped one says. That it is present at all still matters — an entry without the key is
     * one `cachedGet()` cannot produce, and the drop site reports such an entry on stdout as a caller
     * that forgot to supply one. The one test in this file that asserts on the frames a cancel
     * produces ({@see testCancellingTheCurrentRunDropsItsQueuedRequestsAndSaysNothing()}) is asserting
     * that `cancelRun` itself acknowledges nothing; the terminal frames a real dropped request emits
     * are covered by `HealthSupersededRequestTest`, which queues through `cachedGet()` rather than by
     * hand.
     *
     * @return array<string, mixed>
     */
    private static function queueEntry(string $url, ?int $resourceId, ?string $runToken): array
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
            'onSuperseded' => static function (): void {
            }
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
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $frame->errorCode);
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
     * `WebSocketMessageValidator` rejects it and `onMessage()` falls straight to the protocol-error
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
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $frame->errorCode, 'confirms it never reached the validateCalendar dispatch');
    }

    /**
     * `{"action":"cancelRun","runToken":null}` (same for an array or object) is now refused by
     * `WebSocketMessageValidator` before dispatch ever reaches `cancelRun()`: `cancelRun`'s schema
     * entry types `runToken` via `#/definitions/correlationId`, so a non-string value fails schema
     * validation and is answered with a typed `protocolError` instead. `cancelRun()`'s own
     * `is_string()` guard — which kept a `TypeError` a v1 client would otherwise have caused from
     * escaping Ratchet's `IoServer::handleData`, which only catches `\Exception` — is therefore no
     * longer reachable through this path; it stays in place as a backstop, per its own docblock.
     *
     * The queue and the stored token being untouched is still worth asserting: the rejection must
     * happen before any dispatch, not merely produce the right frame alongside unrelated side effects.
     */
    public function testACancelWithANonStringRunTokenIsRejectedByTheSchema(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection(1);

        self::setRunTokens($health, [1 => 'run-a']);
        self::setQueue($health, [self::queueEntry('https://example.test/a', 1, 'run-a')]);

        $health->onMessage($conn, (string) json_encode(['action' => 'cancelRun', 'runToken' => null]));

        self::assertCount(1, self::getQueue($health), 'the queued request survives an unusable cancel');
        self::assertSame([1 => 'run-a'], self::getRunTokens($health), 'the stored token is untouched');
        self::assertCount(1, $conn->sent, 'an unusable cancel is now refused rather than silently dropped');
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $frame->errorCode);
    }
}
