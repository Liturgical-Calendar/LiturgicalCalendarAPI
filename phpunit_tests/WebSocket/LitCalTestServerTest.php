<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\WebSocket;

use LiturgicalCalendar\Api\Enum\ProtocolErrorCode;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Enum\Status;
use LiturgicalCalendar\Api\Enum\Step;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\WebSocketMessageValidator;
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

    /**
     * `WebSocketMessageValidator` resolves the published schema through `LitSchema`, which reads
     * `Router::$apiFilePath` — a typed static that throws until the paths are initialised. Every
     * other suite that touches the validator does the same; this one did not need to until it began
     * deriving the expected capabilities from the same source the server advertises from.
     */
    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
    }

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

        // Every test in this class reads the wire, so every one of them can be answered by a server
        // that is not this code — the handshake test is not special, it is merely where the drift
        // was visible. Checking here means one stale server produces one clear diagnosis instead of
        // a scatter of unrelated-looking failures, or worse, passes.
        $client = WsTestClient::connect($this->wsHost, $this->wsPort);
        $hello  = $client->hello();
        $client->close();
        self::assertNotNull($hello, 'a connecting client was not sent a hello frame');
        self::assertServerIsRunningThisCheckout($hello);
    }

    /**
     * Refuse to report a pass from a server that is not running this checkout.
     *
     * The hazard this closes is a **false pass, not a skip**. `setUp()` probes the port and skips
     * when nothing answers, which reads as careful and is not: nothing checked that whatever *does*
     * answer is the code under test. The WS server is a long-running PHP CLI process that loads
     * `src/Health.php` once at boot, and under docker the container bind-mounts the checkout — so
     * editing a file changes nothing until the process restarts, and a server started before the
     * change keeps answering with the old behaviour. That happened: the per-action `responseFormats`
     * reshape was asserted here with `assertIsArray`, a stale server returned the previous flat list,
     * and the suite passed locally while CI failed. The loose assertion was the proximate cause; the
     * missing freshness check is why nothing said so.
     *
     * **Every capability is already a fingerprint of the running code.** `helloFrame()` derives each
     * one server-side from whatever defines it — `Rite::cases()`, the inbound schema's actions,
     * `RESPONSE_FORMATS_BY_ACTION`, `Step::cases()`, `Status::cases()` — precisely so an
     * advertisement cannot go stale against the behaviour it describes. Deriving the same values here
     * and comparing exactly turns that property into a staleness detector at no extra cost: no new
     * protocol field, no build stamp, nothing to keep in sync that is not kept in sync already.
     *
     * **Known limit, stated rather than papered over.** This catches staleness that reaches the
     * handshake. A server running an older `Health.php` whose capabilities happen to be identical
     * still passes, and only the suite starting its own server on an ephemeral port would close that
     * — which needs a port argument on `bin/LitCalTestServer.php`, since `WS_PORT` cannot be
     * overridden by environment here (`variables_order=GPCS` plus `Dotenv::createMutable`). Deferred
     * deliberately; this covers the drift that actually bit.
     *
     * A pid-file mtime comparison was considered and rejected: `ws-server.pid` can be stale, and
     * under docker the answering process never writes it at all, so the check would interrogate the
     * wrong process in exactly the configuration where the incident happened.
     */
    private static function assertServerIsRunningThisCheckout(\stdClass $hello): void
    {
        $stale = ' — the server answering on this port is not running this checkout.'
            . ' It is a long-running process that loads src/Health.php once at boot, so restart it'
            . ' (`composer ws:stop && composer ws:start`, or `docker compose restart litcal-api`).';

        self::assertSame(
            max(WebSocketMessageValidator::SUPPORTED_PROTOCOL_VERSIONS),
            $hello->protocol ?? null,
            'protocol' . $stale
        );

        $capabilities = $hello->capabilities ?? null;
        self::assertIsObject($capabilities, 'capabilities' . $stale);

        self::assertSame(array_column(Rite::cases(), 'value'), $capabilities->rites ?? null, 'capabilities.rites' . $stale);
        self::assertSame(array_column(Step::cases(), 'value'), $capabilities->steps ?? null, 'capabilities.steps' . $stale);
        self::assertSame(array_column(Status::cases(), 'value'), $capabilities->statuses ?? null, 'capabilities.statuses' . $stale);

        // `supportedActions()` reads the published inbound schema, which is what the server itself
        // advertises from — so this compares the wire against the contract rather than against a
        // list written out here that would need editing whenever an action is added.
        self::assertSame(
            ( new WebSocketMessageValidator() )->supportedActions(),
            $capabilities->actions ?? null,
            'capabilities.actions' . $stale
        );

        // Keyed by action since API#886. Read off the constant by reflection rather than restated,
        // for the same reason HealthHelloFrameTest does: a literal here would let the advertisement
        // and the behaviour drift together in the one direction nothing else catches.
        /** @var array<string, string[]> $expectedFormats */
        $expectedFormats = ( new \ReflectionClassConstant(Health::class, 'RESPONSE_FORMATS_BY_ACTION') )->getValue();
        self::assertEquals(
            (object) $expectedFormats,
            $capabilities->responseFormats ?? null,
            'capabilities.responseFormats' . $stale
        );
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

        // Compared **exactly** against what this checkout would advertise, not merely type-checked.
        // Every capability is derived server-side from the enums, schema and constants in this very
        // repository, so the handshake is already a fingerprint of the code the server is running —
        // it just was not being read as one. See self::assertServerIsRunningThisCheckout(), which
        // covers `protocol` too: this used to assert a literal `1` beside it, and a literal is the
        // very thing that goes stale — on the next protocol bump it would have failed looking like a
        // protocol regression rather than like the hand-written constant it was. The helper reads
        // `max(WebSocketMessageValidator::SUPPORTED_PROTOCOL_VERSIONS)` and tracks the bump.
        self::assertServerIsRunningThisCheckout($hello);

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
