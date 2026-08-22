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
 * The inbound half of the handshake — #806 section F.
 *
 * A client that speaks the self-describing contract says so with `protocol: 1`; a client that predates
 * it sends no `protocol` at all and is handled exactly as before. The interesting cases are neither of
 * those: they are the version this server does not implement, and the version sent as the wrong type.
 *
 * **Why this is checked before anything else interprets the message.** The protocol version says how
 * the rest of the message is to be *read*. Answering `{"action": "bogus", "protocol": 7}` with
 * `unknown_action` would assert something the server cannot know — under protocol 7 that action might
 * be perfectly well defined. `unsupported_protocol` is the only true answer, and
 * {@see testAnUnsupportedProtocolIsAnsweredBeforeTheActionIsJudged()} pins the ordering so a later
 * refactor cannot quietly reverse it.
 *
 * **Why the wrong type is refused here rather than by the schema.** `1.0` decodes to a PHP float, and
 * a float reaching a typed parameter is refused by coercive typing as a `\TypeError` — an `\Error`,
 * which Ratchet's `IoServer::handleData` does not catch, so it takes the WebSocket process down for
 * every connected client. That is the seventh crash shape section G found by probing rather than by
 * reading, and the lesson it paid for is that a value's *type* is checked at the door.
 */
#[CoversClass(Health::class)]
#[CoversClass(WebSocketMessageValidator::class)]
final class HealthProtocolVersionTest extends TestCase
{
    use HealthQueueIsolationTrait;

    /** Same stub convention as HealthCancelRunTest; see the note there. */
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
     * Drive one message through `onMessage()` and return the frames it produced.
     *
     * `onMessage()` echoes the message it received to stdout; captured and discarded so a failure
     * report shows the assertion rather than the server's logging.
     *
     * @param array<string, mixed> $payload
     * @return list<\stdClass>
     */
    private function framesFor(array $payload): array
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection(1);

        // JSON_PRESERVE_ZERO_FRACTION, without which `1.0` is encoded as `1` and the float case below
        // silently tests an integer instead — a green assertion about a message never sent.
        ob_start();
        $health->onMessage($conn, (string) json_encode($payload, JSON_PRESERVE_ZERO_FRACTION));
        ob_end_clean();

        return array_map(
            static function (string $raw): \stdClass {
                $frame = json_decode($raw);
                self::assertInstanceOf(\stdClass::class, $frame);

                return $frame;
            },
            $conn->sent
        );
    }

    /**
     * `cancelRun` is the probe throughout this file: it is the one action that touches no filesystem
     * and issues no HTTP request, so a message that survives validation produces no frames at all.
     * That makes "was it refused?" answerable by counting frames, with nothing else in the way.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function cancelRun(array $extra = []): array
    {
        return array_merge(['action' => 'cancelRun', 'runToken' => 'run-a'], $extra);
    }

    public function testTheCurrentProtocolVersionIsAccepted(): void
    {
        $frames = $this->framesFor(self::cancelRun(['protocol' => 1]));

        self::assertSame([], $frames, 'a message declaring the protocol this server speaks was refused');
    }

    public function testAMessageWithoutAProtocolIsStillLegacyAndUntouched(): void
    {
        $frames = $this->framesFor(self::cancelRun());

        self::assertSame([], $frames, 'an absent protocol must mean legacy handling, exactly as before');
    }

    public function testAProtocolTheServerDoesNotImplementIsRefused(): void
    {
        $frames = $this->framesFor(self::cancelRun(['protocol' => 7]));

        self::assertCount(1, $frames);
        self::assertSame('protocolError', $frames[0]->type ?? null);
        self::assertSame(ProtocolErrorCode::UNSUPPORTED_PROTOCOL->value, $frames[0]->errorCode ?? null);
    }

    /**
     * A string, a float and a null are each a version this server cannot act on, and none of them may
     * reach a typed parameter. `1.0` is the one worth naming: it is *numerically* the supported
     * version, and accepting it on that basis is how a float reaches PHP's coercive typing.
     */
    public function testAProtocolOfTheWrongTypeIsRefused(): void
    {
        foreach (['1', 1.0, null, true, ['1']] as $version) {
            $frames = $this->framesFor(self::cancelRun(['protocol' => $version]));

            self::assertCount(1, $frames, sprintf('protocol %s was not refused', var_export($version, true)));
            self::assertSame(
                ProtocolErrorCode::UNSUPPORTED_PROTOCOL->value,
                $frames[0]->errorCode ?? null,
                sprintf('protocol %s was refused, but not as a protocol failure', var_export($version, true))
            );
        }
    }

    public function testAnUnsupportedProtocolIsAnsweredBeforeTheActionIsJudged(): void
    {
        $frames = $this->framesFor(['action' => 'notAnAction', 'protocol' => 7]);

        self::assertCount(1, $frames);
        self::assertSame(
            ProtocolErrorCode::UNSUPPORTED_PROTOCOL->value,
            $frames[0]->errorCode ?? null,
            'the server cannot know an action is unknown under a protocol it does not read'
        );
    }

    /**
     * The strict unknown-property gate is armed by `requestId`, so a v2 client sends the two fields
     * that could refuse each other: `protocol`, which the shapes must declare, and `requestId`, which
     * makes any undeclared property fatal. Before the schema declared `protocol`, this exact pair —
     * the ordinary shape of a v2 message — was answered with `INVALID_MESSAGE`.
     */
    public function testAProtocolFieldIsNotItselfAnUndeclaredProperty(): void
    {
        $frames = $this->framesFor(self::cancelRun(['protocol' => 1, 'requestId' => 'req-alpha']));

        self::assertSame(
            [],
            $frames,
            'a v2 message was refused for carrying the very field that marks it as v2'
        );
    }

    /**
     * A message the server has just said it cannot read must not leave a mark on the run.
     *
     * The run-token block installs the connection's current run and, on a token change, rebuilds the
     * checkable inventory. Both used to happen before the protocol was judged, so a message written
     * in a protocol this server does not speak could still decide which run the connection was on —
     * and every later frame would be tagged with a token that arrived in an unreadable message.
     */
    public function testAnUnsupportedProtocolLeavesTheConnectionOnNoRun(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection(1);

        ob_start();
        $health->onMessage($conn, (string) json_encode(self::cancelRun(['protocol' => 7, 'runToken' => 'run-a'])));
        ob_end_clean();

        /** @var array<int, string> $tokens */
        $tokens = ( new \ReflectionProperty(Health::class, 'runTokens') )->getValue($health);
        self::assertSame([], $tokens, 'a message refused for its protocol installed a run token anyway');
    }

    /**
     * …and yet the refusal still names the run, or the client never sees it.
     *
     * Both shipped runners discard any frame whose `runToken` does not match the run they believe
     * they are on. A refusal of a run's *first* message carries no stored token to fall back on — so
     * without the message's own token on the frame, the one error the client most needs to see would
     * be the one it silently drops.
     */
    public function testTheRefusalStillNamesTheRunTheMessageDeclared(): void
    {
        $frames = $this->framesFor(self::cancelRun(['protocol' => 7, 'runToken' => 'run-a']));

        self::assertCount(1, $frames);
        self::assertSame('run-a', $frames[0]->runToken ?? null);
        self::assertSame('run-a', $frames[0]->runId ?? null);
    }

    /**
     * Every shape must accept it, not just the one this file probes with. A client does not send
     * `protocol` on some of its messages.
     */
    public function testEveryPublishedShapeDeclaresTheProtocolField(): void
    {
        $schema = json_decode((string) file_get_contents(
            \LiturgicalCalendar\Api\Enum\LitSchema::WEBSOCKET_MESSAGE->path()
        ));
        self::assertInstanceOf(\stdClass::class, $schema);

        $shapes = 0;
        foreach ((array) $schema->definitions as $name => $definition) {
            if (false === $definition instanceof \stdClass || false === isset($definition->properties->action)) {
                continue;
            }
            ++$shapes;
            self::assertTrue(
                isset($definition->properties->protocol),
                sprintf('%s does not declare protocol, so a v2 client sending it would be refused', (string) $name)
            );
        }

        self::assertGreaterThan(0, $shapes, 'no message shapes were found to check');
    }
}
