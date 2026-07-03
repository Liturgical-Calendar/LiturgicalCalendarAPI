<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use React\Promise\Deferred;

/**
 * Targeted coverage for two pure static helpers on the WebSocket Health component:
 *  - isInternalApiUrl(): the host check that keeps the first-party WS_API_KEY from leaking to an
 *    arbitrary absolute URL validated via executeValidation.
 *  - isUpstreamFailureStatus(): which upstream HTTP statuses (429, 5xx) are rejected versus flowed
 *    through to per-format validation (e.g. a 404 for an unknown calendar).
 *
 * Both are dependency-free of the Ratchet/Guzzle machinery, so they are exercised directly via
 * reflection without standing up the WebSocket server.
 */
#[CoversClass(Health::class)]
final class HealthHelpersTest extends TestCase
{
    private ?string $apiHostBackup  = null;
    private ?string $appEnvBackup   = null;
    private ?string $wsApiKeyBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiHostBackup  = isset($_ENV['API_HOST']) && is_string($_ENV['API_HOST']) ? $_ENV['API_HOST'] : null;
        $this->appEnvBackup   = isset($_ENV['APP_ENV']) && is_string($_ENV['APP_ENV']) ? $_ENV['APP_ENV'] : null;
        $this->wsApiKeyBackup = isset($_ENV['WS_API_KEY']) && is_string($_ENV['WS_API_KEY']) ? $_ENV['WS_API_KEY'] : null;
        // Keep handleHttpResponse() out of its development-only debug-logging branch, and start
        // each test with no ambient WS_API_KEY so the header-injection assertions are deterministic.
        $_ENV['APP_ENV'] = 'test';
        unset($_ENV['WS_API_KEY']);
    }

    protected function tearDown(): void
    {
        foreach (
            [
                'API_HOST'   => $this->apiHostBackup,
                'APP_ENV'    => $this->appEnvBackup,
                'WS_API_KEY' => $this->wsApiKeyBackup,
            ] as $name => $backup
        ) {
            if ($backup === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $backup;
            }
        }
        parent::tearDown();
    }

    private static function isInternalApiUrl(string $url): bool
    {
        $method = new \ReflectionMethod(Health::class, 'isInternalApiUrl');
        /** @var bool $result */
        $result = $method->invoke(null, $url);

        return $result;
    }

    private static function isUpstreamFailureStatus(int $statusCode): bool
    {
        $method = new \ReflectionMethod(Health::class, 'isUpstreamFailureStatus');
        /** @var bool $result */
        $result = $method->invoke(null, $statusCode);

        return $result;
    }

    public function testRelativeUrlIsInternal(): void
    {
        $_ENV['API_HOST'] = 'litcal.example.org';
        $this->assertTrue(self::isInternalApiUrl('/calendar/2020?year_type=CIVIL'));
    }

    public function testMatchingHostIsInternalCaseInsensitive(): void
    {
        $_ENV['API_HOST'] = 'litcal.example.org';
        $this->assertTrue(self::isInternalApiUrl('https://litcal.example.org/api/dev/calendar/2020'));
        $this->assertTrue(self::isInternalApiUrl('https://LITCAL.EXAMPLE.ORG/api/dev/calendar/2020'));
    }

    public function testForeignHostIsNotInternal(): void
    {
        $_ENV['API_HOST'] = 'litcal.example.org';
        $this->assertFalse(self::isInternalApiUrl('https://evil.example.com/api/dev/calendar/2020'));
    }

    public function testDefaultsToLocalhostWhenApiHostUnset(): void
    {
        unset($_ENV['API_HOST']);
        $this->assertTrue(self::isInternalApiUrl('http://localhost:8000/api/dev/calendar/2020'));
        $this->assertFalse(self::isInternalApiUrl('https://litcal.example.org/api/dev/calendar/2020'));
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function statusProvider(): array
    {
        return [
            '200 ok'           => [200, false],
            '301 moved'        => [301, false],
            '404 not found'    => [404, false],
            '418 teapot'       => [418, false],
            '429 rate limited' => [429, true],
            '500 server error' => [500, true],
            '503 unavailable'  => [503, true],
        ];
    }

    #[DataProvider('statusProvider')]
    public function testUpstreamFailureStatus(int $statusCode, bool $expected): void
    {
        $this->assertSame($expected, self::isUpstreamFailureStatus($statusCode));
    }

    /**
     * Invoke the private static handleHttpResponse() with the given response and report how the
     * deferred settled. React promises settle synchronously, so no event loop is needed.
     *
     * @return array{resolved: mixed, rejected: ?\Throwable}
     */
    private static function runHandleHttpResponse(ResponseInterface $response): array
    {
        /** @var Deferred<array{data: string, fromCache: bool}> $deferred */
        $deferred = new Deferred();
        $resolved = null;
        $rejected = null;
        $deferred->promise()->then(
            function (mixed $value) use (&$resolved): void {
                $resolved = $value;
            },
            function (\Throwable $error) use (&$rejected): void {
                $rejected = $error;
            }
        );

        $method = new \ReflectionMethod(Health::class, 'handleHttpResponse');
        ob_start();
        try {
            $method->invoke(null, $response, $deferred, 'cache-key', 300, 'http://localhost:8000/api/dev/calendar/2020');
        } finally {
            ob_end_clean();
        }

        return ['resolved' => $resolved, 'rejected' => $rejected];
    }

    public function testHandleHttpResponseRejectsRateLimited(): void
    {
        $response = new Response(429, ['Retry-After' => '30'], '{"status":429}', '1.1', 'Too Many Requests');
        $result   = self::runHandleHttpResponse($response);

        $this->assertNull($result['resolved']);
        $rejected = $result['rejected'];
        if (!$rejected instanceof \RuntimeException) {
            self::fail('Expected a RuntimeException rejection.');
        }
        $this->assertStringContainsString('HTTP 429', $rejected->getMessage());
        $this->assertStringContainsString('Retry-After: 30', $rejected->getMessage());
    }

    public function testHandleHttpResponseRejectsServerError(): void
    {
        $response = new Response(503, [], 'service unavailable', '1.1', 'Service Unavailable');
        $result   = self::runHandleHttpResponse($response);

        $this->assertNull($result['resolved']);
        $rejected = $result['rejected'];
        if (!$rejected instanceof \RuntimeException) {
            self::fail('Expected a RuntimeException rejection.');
        }
        $this->assertStringContainsString('HTTP 503', $rejected->getMessage());
    }

    public function testHandleHttpResponseResolvesSuccessBody(): void
    {
        $body   = '{"litcal":[],"settings":{},"metadata":{},"messages":[]}';
        $result = self::runHandleHttpResponse(new Response(200, [], $body));

        $this->assertNull($result['rejected']);
        $this->assertSame(['data' => $body, 'fromCache' => false], $result['resolved']);
    }

    public function testHandleHttpResponseResolvesNonFailureErrorBody(): void
    {
        // A 404 (unknown calendar) is not an upstream failure: its body flows through so the
        // per-format validation can report it at the json-valid phase.
        $body   = '{"type":"about:blank","status":404}';
        $result = self::runHandleHttpResponse(new Response(404, [], $body, '1.1', 'Not Found'));

        $this->assertNull($result['rejected']);
        $this->assertSame(['data' => $body, 'fromCache' => false], $result['resolved']);
    }

    /**
     * @param array{headers?: array<string, string>, stream?: bool} $options
     * @return array{headers?: array<string, string>, stream?: bool}
     */
    private static function withApiKeyHeader(array $options, string $url): array
    {
        $method = new \ReflectionMethod(Health::class, 'withApiKeyHeader');
        /** @var array{headers?: array<string, string>, stream?: bool} $result */
        $result = $method->invoke(null, $options, $url);

        return $result;
    }

    public function testWithApiKeyHeaderAttachesForInternalHostAndPreservesExisting(): void
    {
        $_ENV['API_HOST']   = 'litcal.example.org';
        $_ENV['WS_API_KEY'] = 'litcal_test_secret';

        $options = self::withApiKeyHeader(
            ['headers' => ['Accept' => 'application/json'], 'stream' => true],
            'https://litcal.example.org/api/dev/calendar/2020'
        );

        $this->assertSame('litcal_test_secret', $options['headers']['X-Api-Key'] ?? null);
        $this->assertSame('application/json', $options['headers']['Accept'] ?? null);
        $this->assertTrue($options['stream'] ?? false);
    }

    public function testWithApiKeyHeaderDoesNotLeakToForeignHost(): void
    {
        $_ENV['API_HOST']   = 'litcal.example.org';
        $_ENV['WS_API_KEY'] = 'litcal_test_secret';

        $options = self::withApiKeyHeader(
            ['headers' => ['Accept' => 'application/json']],
            'https://evil.example.com/x'
        );

        $this->assertArrayNotHasKey('X-Api-Key', $options['headers'] ?? []);
    }

    public function testWithApiKeyHeaderNoOpWhenKeyUnset(): void
    {
        $_ENV['API_HOST'] = 'litcal.example.org';
        unset($_ENV['WS_API_KEY']);

        $options = self::withApiKeyHeader(
            ['headers' => ['Accept' => 'application/json']],
            'https://litcal.example.org/api/dev/calendar/2020'
        );

        $this->assertArrayNotHasKey('X-Api-Key', $options['headers'] ?? []);
    }

    public function testWithApiKeyHeaderNoOpWhenKeyEmpty(): void
    {
        $_ENV['API_HOST']   = 'litcal.example.org';
        $_ENV['WS_API_KEY'] = '';

        $options = self::withApiKeyHeader(
            ['headers' => ['Accept' => 'application/json']],
            'https://litcal.example.org/api/dev/calendar/2020'
        );

        $this->assertArrayNotHasKey('X-Api-Key', $options['headers'] ?? []);
    }

    /**
     * Invoke the private, non-static sendMessage() on a fresh Health instance and return the raw
     * payload captured by the connection stub's send(). sendMessage() may echo, so the reflected
     * call is wrapped in an output buffer. When $runToken is omitted the reflected default (null)
     * is used, exercising the per-connection stored-token fallback.
     */
    private static function invokeSendMessage(
        Health $health,
        \Ratchet\ConnectionInterface $from,
        \stdClass $msg,
        ?string $runToken = null
    ): void {
        $method = new \ReflectionMethod(Health::class, 'sendMessage');
        ob_start();
        try {
            if ($runToken === null) {
                $method->invoke($health, $from, $msg);
            } else {
                $method->invoke($health, $from, $msg, $runToken);
            }
        } finally {
            ob_end_clean();
        }
    }

    /**
     * A minimal Ratchet connection whose send() captures the outbound payload. resourceId is a
     * dynamic public property Ratchet assigns (not part of ConnectionInterface).
     */
    private static function createStubConnection(int $resourceId)
    {
        return new class ($resourceId) implements \Ratchet\ConnectionInterface {
            /** @var mixed */
            public $sent = null;

            public function __construct(public int $resourceId)
            {
            }

            public function send($data)
            {
                $this->sent = $data;

                return $this;
            }

            public function close()
            {
            }
        };
    }

    /**
     * Regression guard for the stop/restart miscount: an async response must be stamped with the
     * ORIGINATING request's run token, not whatever token the connection currently has stored. A
     * newer run (token-NEW) has already overwritten runTokens for this connection, but a response
     * that belongs to the previous run (token-OLD) still in flight must carry token-OLD so the
     * client can discard it against the active run.
     */
    public function testSendMessageStampsOriginatingRunTokenOverStoredToken(): void
    {
        $conn = self::createStubConnection(7);

        $health   = new Health();
        $property = new \ReflectionProperty(Health::class, 'runTokens');
        $property->setValue($health, [$conn->resourceId => 'token-NEW']);

        $msg       = new \stdClass();
        $msg->type = 'success';
        $msg->text = 'validation complete';

        self::invokeSendMessage($health, $conn, $msg, 'token-OLD');

        $this->assertIsString($conn->sent);
        $decoded = json_decode($conn->sent);
        $this->assertInstanceOf(\stdClass::class, $decoded);
        $this->assertSame('token-OLD', $decoded->runToken);
    }

    /**
     * Backward-compatible fallback: callers that do not pass an originating run token still get the
     * per-connection stored token stamped onto the outgoing message.
     */
    public function testSendMessageFallsBackToStoredTokenWhenRunTokenOmitted(): void
    {
        $conn = self::createStubConnection(11);

        $health   = new Health();
        $property = new \ReflectionProperty(Health::class, 'runTokens');
        $property->setValue($health, [$conn->resourceId => 'stored-token']);

        $msg       = new \stdClass();
        $msg->type = 'success';
        $msg->text = 'validation complete';

        self::invokeSendMessage($health, $conn, $msg);

        $this->assertIsString($conn->sent);
        $decoded = json_decode($conn->sent);
        $this->assertInstanceOf(\stdClass::class, $decoded);
        $this->assertSame('stored-token', $decoded->runToken);
    }

    /**
     * Restart responsiveness: queued requests from a superseded run (their connection's stored token
     * has advanced) are dropped, while requests for the current run and untagged requests (e.g. the
     * metadata fetch on connect) are kept — so a restarted run dispatches immediately instead of
     * waiting for the stopped run's backlog to drain.
     */
    public function testDropSupersededQueuedRequestsKeepsCurrentAndUntaggedDropsStale(): void
    {
        $health = new Health();
        $noop   = static function (): void {
        };

        // Connection 7 has moved on to run 'B'; connection 9 is on run 'X'.
        ( new \ReflectionProperty(Health::class, 'runTokens') )->setValue($health, [7 => 'B', 9 => 'X']);

        $queueProp = new \ReflectionProperty(Health::class, 'queue');
        $queueProp->setValue($health, [
            ['url' => 'stale',    'options' => [], 'resolve' => $noop, 'reject' => $noop, 'resourceId' => 7,    'runToken' => 'A'],
            ['url' => 'current',  'options' => [], 'resolve' => $noop, 'reject' => $noop, 'resourceId' => 7,    'runToken' => 'B'],
            ['url' => 'other',    'options' => [], 'resolve' => $noop, 'reject' => $noop, 'resourceId' => 9,    'runToken' => 'X'],
            ['url' => 'untagged', 'options' => [], 'resolve' => $noop, 'reject' => $noop, 'resourceId' => null, 'runToken' => null],
        ]);

        ( new \ReflectionMethod(Health::class, 'dropSupersededQueuedRequests') )->invoke($health);

        $remaining = $queueProp->getValue($health);
        $this->assertIsArray($remaining);
        $this->assertSame(['current', 'other', 'untagged'], array_column($remaining, 'url'));
    }
}
