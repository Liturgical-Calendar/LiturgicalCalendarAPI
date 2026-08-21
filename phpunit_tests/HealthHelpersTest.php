<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Services\WebSocketMessageValidator;
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
#[CoversClass(WebSocketMessageValidator::class)]
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
        /** @var Deferred<array{data: string, status: int, fromCache: bool}> $deferred */
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
        $this->assertSame(['data' => $body, 'status' => 200, 'fromCache' => false], $result['resolved']);
    }

    /**
     * The status is on the resolved value, not just in a log line (#833): `'http_errors' => false` on
     * the Guzzle client means a 4xx/5xx resolves this promise rather than rejecting it, so a consumer
     * gating `exists` on success has nothing else to look at. Before #833, this resolved value carried
     * only `data` and `fromCache` — a 404's body flowed through indistinguishably from a 200's.
     */
    public function testHandleHttpResponseResolvesNonFailureErrorBody(): void
    {
        // A 404 (unknown calendar) is not an upstream failure: its body flows through so the
        // per-format validation can report it at the json-valid phase.
        $body   = '{"type":"about:blank","status":404}';
        $result = self::runHandleHttpResponse(new Response(404, [], $body, '1.1', 'Not Found'));

        $this->assertNull($result['rejected']);
        $this->assertSame(['data' => $body, 'status' => 404, 'fromCache' => false], $result['resolved']);
    }

    /**
     * The RFC 9110 rite-boundary refusal #833 was filed about: a 400 whose body is a problem
     * document. It is not an upstream failure (only 429/5xx are), so it resolves rather than rejects
     * — and the whole point of #833 is that the status on that resolution must be the real one.
     */
    public function testHandleHttpResponseResolvesBadRequestWithItsStatus(): void
    {
        $body   = '{"type":"…rfc9110#name-400-bad-request","title":"Bad Request","status":400,"detail":"The Ambrosian rite is only available from 1976 onward (the first reformed Ambrosian Missal); requested year 1970."}';
        $result = self::runHandleHttpResponse(new Response(400, [], $body, '1.1', 'Bad Request'));

        $this->assertNull($result['rejected']);
        $this->assertSame(['data' => $body, 'status' => 400, 'fromCache' => false], $result['resolved']);
    }

    // ---------------------------------------------------------------- the cached-path trap (#833)

    /**
     * `cachedGet()`'s cache-hit branch resolves from whatever {@see Health::decodeHttpCacheEntry()}
     * hands back, which is whatever {@see Health::encodeHttpCacheEntry()} wrote — extracted out of
     * `handleHttpResponse()` and `cachedGet()` respectively so this round trip is testable without a
     * real cache backend and without driving the ReactPHP loop `cachedGet()` schedules resolution
     * through.
     *
     * This is the trap the issue is about: a 400 that survives to a *second* request only if the
     * status written to the cache is the status read back from it. Before #833 there was no status in
     * the cached entry at all — a cache hit always meant `fromCache: true` and nothing else, so a
     * refused request that got cached (as 4xx already were, per the comment on the non-upstream-failure
     * branch below `isUpstreamFailureStatus()`) would replay as a pass on every request after the first.
     */
    public function testCacheEntryRoundTripsTheRealStatusNotJustTheBody(): void
    {
        $body = '{"status":400,"detail":"The Ambrosian rite is only available from 1976 onward; requested year 1970."}';

        $encodeMethod = new \ReflectionMethod(Health::class, 'encodeHttpCacheEntry');
        $encoded      = $encodeMethod->invoke(null, 400, $body);
        self::assertIsString($encoded, 'precondition: a JSON-safe body encodes');

        $decodeMethod = new \ReflectionMethod(Health::class, 'decodeHttpCacheEntry');
        /** @var array{status: int, body: string}|null $decoded */
        $decoded = $decodeMethod->invoke(null, $encoded);

        self::assertSame(['status' => 400, 'body' => $body], $decoded, 'the second resolution must see the same status the first one did, not a silent 200');
    }

    /**
     * A 200 round-trips too — the codec is not special-cased to success or failure, only to the shape.
     */
    public function testCacheEntryRoundTripsA200Status(): void
    {
        $body = '{"litcal":[],"settings":{},"metadata":{},"messages":[]}';

        $encodeMethod = new \ReflectionMethod(Health::class, 'encodeHttpCacheEntry');
        $encoded      = $encodeMethod->invoke(null, 200, $body);
        self::assertIsString($encoded);

        $decodeMethod = new \ReflectionMethod(Health::class, 'decodeHttpCacheEntry');
        /** @var array{status: int, body: string}|null $decoded */
        $decoded = $decodeMethod->invoke(null, $encoded);

        self::assertSame(['status' => 200, 'body' => $body], $decoded);
    }

    /**
     * A cache entry that is not the `{"status":…,"body":…}` envelope — the shape every entry had
     * *before* #833 — must not be guessed at. Defaulting a bare body to status 200 would silently
     * reintroduce the exact bug #833 fixed, the moment a pre-fix cache entry outlived a deploy: decode
     * treats it as absent instead, which sends the caller back to `reject()` and a real re-fetch.
     */
    public function testDecodeRejectsAPreFixBareBodyCacheEntryRatherThanGuessingAStatus(): void
    {
        $decodeMethod = new \ReflectionMethod(Health::class, 'decodeHttpCacheEntry');

        self::assertNull($decodeMethod->invoke(null, '{"litcal":[],"settings":{},"metadata":{},"messages":[]}'), 'a bare JSON body, with no envelope, must not decode to a guessed status');
        self::assertNull($decodeMethod->invoke(null, 'not json at all'), 'garbage must not decode either');
        self::assertNull($decodeMethod->invoke(null, '{"status":"400","body":"x"}'), 'a non-integer status must not decode');
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

    // ---------------------------------------------------------------- isTypedCalendarMessage() vs shapeOf()

    private static function isTypedCalendarMessage(\stdClass $message): bool
    {
        $method = new \ReflectionMethod(Health::class, 'isTypedCalendarMessage');
        /** @var bool $result */
        $result = $method->invoke(null, $message);

        return $result;
    }

    /**
     * Every shape `Health::isTypedCalendarMessage()` and `WebSocketMessageValidator::shapeOf()`
     * disagreeing about would matter: a v2 `validateCalendar` mistaken for v1 (or the reverse) picks
     * the wrong dispatch path, or the wrong schema arm.
     *
     * @return array<string, array{string}>
     */
    public static function typedCalendarDiscriminatorProvider(): array
    {
        return [
            'typed calendar object'  => ['{"action":"validateCalendar","calendar":{"kind":"national","id":"IT","rite":"roman"}}'],
            'legacy calendar string' => ['{"action":"validateCalendar","calendar":"IT"}'],
            'calendar missing'       => ['{"action":"validateCalendar"}'],
            // A JSON array decodes to a PHP array, not \stdClass — neither predicate must treat it
            // as an object calendar.
            'calendar is an array'   => ['{"action":"validateCalendar","calendar":[1,2,3]}'],
            'calendar is null'       => ['{"action":"validateCalendar","calendar":null}'],
            'action missing'         => ['{"calendar":{"kind":"national","id":"IT","rite":"roman"}}'],
            'action not a string'    => ['{"action":42,"calendar":{"kind":"national","id":"IT","rite":"roman"}}'],
            'a different action'     => ['{"action":"runTest","calendar":{"kind":"national","id":"IT","rite":"roman"}}'],
        ];
    }

    /**
     * The I1 fix: `Health::isTypedCalendarMessage()` is now a thin wrapper over
     * `WebSocketMessageValidator::shapeOf()` rather than a second literal copy of the same
     * predicate. This pins the promise the docblock makes — that the two cannot drift apart —
     * rather than merely restating it.
     */
    #[DataProvider('typedCalendarDiscriminatorProvider')]
    public function testIsTypedCalendarMessageAgreesWithShapeOf(string $json): void
    {
        /** @var \stdClass $message */
        $message = json_decode($json);

        $this->assertSame(
            'validateCalendarTyped' === WebSocketMessageValidator::shapeOf($message),
            self::isTypedCalendarMessage($message),
            'Health::isTypedCalendarMessage() must agree with WebSocketMessageValidator::shapeOf(), its single source of truth'
        );
    }
}
