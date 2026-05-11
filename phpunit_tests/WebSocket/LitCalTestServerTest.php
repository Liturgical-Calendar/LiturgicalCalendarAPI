<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\WebSocket;

use PHPUnit\Framework\TestCase;

/**
 * Integration test for the WebSocket entry point served by
 * public/LitCalTestServer.php (Ratchet + LiturgicalCalendar\Api\Health).
 *
 * Drives the server in-process from PHPUnit via a minimal raw-socket WS
 * client (WsTestClient). Lifts the WS handler surface in Health.php
 * (onOpen / onMessage validation / onClose) via the pcov bootstrap hook
 * in public/LitCalTestServer.php — every message handled by the server
 * contributes to the merged coverage report when PCOV_SERVER_COVERAGE_DIR
 * is set.
 *
 * Scope: validates the request/response shape for non-async actions
 * (echobot, JSON-validation errors, missing-action errors). The async
 * actions (executeUnitTest, executeValidation, validateCalendar) involve
 * an internal HTTP call back into the API server + event-loop scheduling
 * and need a more sophisticated client (see the UnitTestInterface repo's
 * JS implementation); covering src/Test/ via this path is a separate
 * follow-up.
 *
 * Skipped automatically when ws://127.0.0.1:8082 isn't reachable, so this
 * file is a no-op in plain `composer test` runs without `composer ws:start`.
 */
final class LitCalTestServerTest extends TestCase
{
    private string $wsHost;
    private int $wsPort;

    protected function setUp(): void
    {
        $this->wsHost = (string) ( $_ENV['WS_HOST'] ?? '127.0.0.1' );
        $this->wsPort = (int) ( $_ENV['WS_PORT'] ?? 8082 );

        $probe = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->wsHost, $this->wsPort),
            $_e,
            $_m,
            1.0
        );
        if ($probe === false) {
            $this->markTestSkipped(
                sprintf('WS server not reachable on %s:%d — start it with `composer ws:start`.', $this->wsHost, $this->wsPort)
            );
        }
        fclose($probe);
    }

    public function testHandshakeAndEcho(): void
    {
        $client = WsTestClient::connect($this->wsHost, $this->wsPort);

        // The handler echoes any well-formed JSON with an unknown action back
        // as `{"type":"echobot","text":<original>}`. That's enough to exercise
        // onMessage's happy-path JSON decode + the default switch arm.
        $payload = json_encode(['action' => 'this-is-not-a-real-action', 'foo' => 'bar']);
        $this->assertNotFalse($payload);

        $client->sendText($payload);
        $reply = $client->receiveText();

        $decoded = json_decode($reply);
        $this->assertIsObject($decoded, 'WS reply should decode as JSON object; got: ' . $reply);
        $this->assertObjectHasProperty('type', $decoded);
        $this->assertSame('echobot', $decoded->type);
        $this->assertObjectHasProperty('text', $decoded);
        $this->assertSame($payload, $decoded->text);

        $client->close();
    }

    public function testMalformedJsonGetsValidationError(): void
    {
        // Exercises the else branch of onMessage where the JSON decode fails —
        // server replies with an echobot frame carrying an errorMsg.
        $client = WsTestClient::connect($this->wsHost, $this->wsPort);
        $client->sendText('definitely not json');
        $reply = $client->receiveText();

        $decoded = json_decode($reply);
        $this->assertIsObject($decoded);
        $this->assertSame('echobot', $decoded->type);
        $this->assertObjectHasProperty('errorMsg', $decoded);
        // json_last_error_msg() phrasing isn't guaranteed across PHP versions,
        // but it'll always be non-empty.
        $this->assertNotEmpty($decoded->errorMsg);

        $client->close();
    }

    public function testMessageWithoutActionReportsValidationError(): void
    {
        $client = WsTestClient::connect($this->wsHost, $this->wsPort);
        $client->sendText('{"foo":"bar"}');
        $reply = $client->receiveText();

        $decoded = json_decode($reply);
        $this->assertIsObject($decoded);
        $this->assertSame('echobot', $decoded->type);
        $this->assertSame('No action specified', $decoded->errorMsg);

        $client->close();
    }
}
