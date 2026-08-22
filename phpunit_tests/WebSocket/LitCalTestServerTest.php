<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\WebSocket;

use LiturgicalCalendar\Api\Enum\ProtocolErrorCode;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for the WebSocket entry point served by
 * bin/LitCalTestServer.php (Ratchet + LiturgicalCalendar\Api\Health).
 *
 * Drives the server in-process from PHPUnit via a minimal raw-socket WS
 * client (WsTestClient). Lifts the WS handler surface in Health.php
 * (onOpen / onMessage validation / onClose) via the pcov bootstrap hook
 * in bin/LitCalTestServer.php — every message handled by the server
 * contributes to the merged coverage report when PCOV_SERVER_COVERAGE_DIR
 * is set.
 *
 * Scope: validates the request/response shape for non-async actions
 * (protocolError rejections, JSON-validation errors, missing-action errors). The async
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

    /**
     * The `hello` frame, against a server that is actually running — #806 section F.
     *
     * The in-process suites assert what `Health::onOpen()` builds; this asserts what a client
     * receives, which is not the same statement. Sixteen tests in this directory read `hello` where
     * they expected their own answer when the frame was introduced, precisely because the frame is
     * real on the wire and only the live suites see the wire. That the suite skips when no server
     * is reachable is what let it reach CI.
     */
    public function testTheServerAdvertisesItsContractOnConnect(): void
    {
        $client = WsTestClient::connect($this->wsHost, $this->wsPort);

        $hello = $client->hello();
        $this->assertNotNull($hello, 'a connecting client was not sent a hello frame');
        $this->assertSame(1, $hello->protocol ?? null);

        $capabilities = $hello->capabilities ?? null;
        $this->assertIsObject($capabilities);
        foreach (['rites', 'actions', 'responseFormats', 'steps', 'statuses'] as $capability) {
            $this->assertIsArray($capabilities->{$capability} ?? null, "capabilities.{$capability} is missing");
            $this->assertNotEmpty($capabilities->{$capability});
        }
        $this->assertContains('validateSource', $capabilities->actions);
        $this->assertContains('complete', $capabilities->steps);

        // No run correlation, which is what makes the frame invisible to a client that predates it:
        // both shipped runners drop a frame whose runToken does not match the run they are on.
        $this->assertObjectNotHasProperty('runToken', $hello);
        $this->assertObjectNotHasProperty('runId', $hello);

        $client->close();
    }

    public function testHandshakeAndEcho(): void
    {
        $client = WsTestClient::connect($this->wsHost, $this->wsPort);

        // The handler answers any well-formed JSON with an unknown action back
        // as `{"type":"protocolError","errorCode":"unknown_action","text":"Unknown action from
        // connection <id>: <original>"}` — the raw message is still in there, but now alongside a
        // statement of what was wrong with it, not standing in for one. That's enough to exercise
        // onMessage's happy-path JSON decode + the default switch arm.
        $payload = json_encode(['action' => 'this-is-not-a-real-action', 'foo' => 'bar']);
        $this->assertNotFalse($payload);

        $client->sendText($payload);
        $reply = $client->receiveText();

        $decoded = json_decode($reply);
        $this->assertIsObject($decoded, 'WS reply should decode as JSON object; got: ' . $reply);
        $this->assertObjectHasProperty('type', $decoded);
        $this->assertSame('protocolError', $decoded->type);
        $this->assertSame(ProtocolErrorCode::UNKNOWN_ACTION->value, $decoded->errorCode);
        $this->assertObjectHasProperty('text', $decoded);
        $this->assertStringContainsString($payload, (string) $decoded->text, 'the raw message must still be recoverable from the text');
        $this->assertStringContainsString('Unknown action', (string) $decoded->text, 'the text must say what was wrong, not only echo the message');

        $client->close();
    }

    public function testMalformedJsonGetsValidationError(): void
    {
        // Exercises the else branch of onMessage where the JSON decode fails —
        // server replies with a protocolError frame carrying errorCode invalid_json, and the text
        // carries the same json_last_error_msg() reason the server logs, since errorCode alone
        // cannot distinguish "Syntax error" from "Malformed UTF-8 characters".
        $client = WsTestClient::connect($this->wsHost, $this->wsPort);
        $client->sendText('definitely not json');
        $reply = $client->receiveText();

        $decoded = json_decode($reply);
        $this->assertIsObject($decoded);
        $this->assertSame('protocolError', $decoded->type);
        $this->assertSame(ProtocolErrorCode::INVALID_JSON->value, $decoded->errorCode);
        // json_last_error_msg() phrasing isn't guaranteed across PHP versions,
        // but it'll always be non-empty.
        $this->assertNotEmpty($decoded->text);

        $client->close();
    }

    public function testMessageWithoutActionReportsValidationError(): void
    {
        $client = WsTestClient::connect($this->wsHost, $this->wsPort);
        $client->sendText('{"foo":"bar"}');
        $reply = $client->receiveText();

        $decoded = json_decode($reply);
        $this->assertIsObject($decoded);
        $this->assertSame('protocolError', $decoded->type);
        $this->assertSame(ProtocolErrorCode::MISSING_ACTION->value, $decoded->errorCode);
        $this->assertStringContainsString('No action specified', (string) $decoded->text);

        $client->close();
    }
}
