<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Tests\Http\Server;

use LiturgicalCalendar\Api\Http\Server\LargeHeaderHttpServer;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServerInterface;

/**
 * The WebSocket handshake limit, which exists because this server shares a registrable domain with sites
 * that authenticate against Zitadel: their `COOKIE_DOMAIN`-scoped session is sent on every handshake, and
 * Ratchet's 4096-byte default answered 413 once the JWTs grew past it.
 *
 * These assert the behaviour rather than the wiring wherever possible — an oversized handshake is refused,
 * a merely large one is accepted — because the fragile part is that a subclass can still reach Ratchet's
 * protected parser. If a future Ratchet renames it, the boundary tests fail rather than the limit silently
 * reverting to 4096 and taking every logged-in user's WebSocket with it.
 */
final class LargeHeaderHttpServerTest extends TestCase
{
    /**
     * A connection that records what the server sent and whether it was closed.
     *
     * @return array{0: ConnectionInterface, 1: \stdClass}
     */
    private function connection(): array
    {
        $log = new \stdClass();
        /** @var string $log->sent */
        $log->sent   = '';
        $log->closed = false;

        $conn = new class ($log) implements ConnectionInterface {
            public \stdClass $log;

            // Both are declared rather than left dynamic: Ratchet sets them on the connection object it
            // is given, and PHP 8.2 deprecates creating properties that way. A real ReactPHP connection
            // carries them too.
            public bool $httpHeadersReceived = false;
            public string $httpBuffer        = '';

            public function __construct(\stdClass $log)
            {
                $this->log = $log;
            }

            public function send($data): ConnectionInterface
            {
                $this->log->sent .= (string) $data;
                return $this;
            }

            public function close(): void
            {
                $this->log->closed = true;
            }
        };

        return [$conn, $log];
    }

    /**
     * A syntactically complete handshake whose Cookie header is $cookieBytes long, mimicking the
     * COOKIE_DOMAIN-scoped Zitadel session a browser attaches here without being asked.
     */
    private function handshake(int $cookieBytes): string
    {
        $headers = [
            'GET / HTTP/1.1',
            'Host: litcal-test.example.org:9443',
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: x3JJHMbDL1EzLkh9GBhXDw==',
            'Sec-WebSocket-Version: 13',
        ];

        if ($cookieBytes > 0) {
            $headers[] = 'Cookie: litcal_access_token=' . str_repeat('A', $cookieBytes);
        }

        return implode("\r\n", $headers) . "\r\n\r\n";
    }

    private function server(?int $maxSize = null): LargeHeaderHttpServer
    {
        // A stub, not a mock: nothing here asserts on the wrapped component's calls, and PHPUnit
        // rightly notices an expectation-free mock.
        $component = $this->createStub(HttpServerInterface::class);

        return null === $maxSize
            ? new LargeHeaderHttpServer($component)
            : new LargeHeaderHttpServer($component, $maxSize);
    }

    public function testAcceptsAHandshakeThatRatchetsDefaultWouldHaveRefused(): void
    {
        // ~5 KB: over Ratchet's 4096 default, comfortably under this class's limit. This is the case that
        // was breaking in production — a logged-in user's cookies pushing the handshake past the default.
        [$conn, $log] = $this->connection();
        $server       = $this->server();

        $server->onOpen($conn);
        $server->onMessage($conn, $this->handshake(5000));

        $this->assertFalse($log->closed, 'A ~5 KB handshake must not be refused.');
        $this->assertStringNotContainsString('413', $log->sent);
    }

    public function testStillRefusesAHandshakeBeyondTheRaisedLimit(): void
    {
        // Raised, not removed: the cap is a denial-of-service guard, and an unauthenticated client must
        // still not be able to make this server buffer without bound.
        [$conn, $log] = $this->connection();
        $server       = $this->server();

        $server->onOpen($conn);
        $server->onMessage($conn, $this->handshake(LargeHeaderHttpServer::MAX_HANDSHAKE_BYTES + 1000));

        $this->assertTrue($log->closed, 'A handshake beyond the limit must be refused.');
        $this->assertStringContainsString('413', $log->sent);
    }

    public function testHonoursAnExplicitLimit(): void
    {
        [$conn, $log] = $this->connection();
        $server       = $this->server(2048);

        $server->onOpen($conn);
        $server->onMessage($conn, $this->handshake(4000));

        $this->assertTrue($log->closed, 'An explicit limit must be applied instead of the default.');
        $this->assertStringContainsString('413', $log->sent);
    }

    public function testDefaultLimitIsWellClearOfARealZitadelSession(): void
    {
        // Measured on a live session: litcal_access_token ~1082 B and litcal_id_token ~1586 B, before a
        // refresh token or anything else the parent domain carries. The default has to clear that with
        // room to spare, or this fix only postpones the outage.
        $this->assertGreaterThan(
            8192,
            LargeHeaderHttpServer::MAX_HANDSHAKE_BYTES,
            'The limit must leave headroom above a full Zitadel session plus ordinary browser headers.'
        );
    }
}
