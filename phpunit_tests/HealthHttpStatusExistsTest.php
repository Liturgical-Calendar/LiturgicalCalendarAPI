<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\ApcuShimStore;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * #833: a URL-based check must not report `exists = success` for a non-2xx HTTP status.
 *
 * `Health`'s Guzzle client is built with `'http_errors' => false`, so a 4xx or 5xx resolves
 * {@see Health::cachedGet()}'s promise instead of rejecting it, and before this fix the status was
 * discarded entirely: every consumer's fulfil handler had nothing to check and reported `exists` as
 * passed unconditionally. Observed live on Ambrosian years 1970-1975, which the API correctly refuses
 * with a 400 whose body is an RFC 9457 problem document explaining exactly why — none of it reached
 * the client.
 *
 * Every arm here drives {@see Health::onMessage()} end-to-end and settles the queued HTTP request by
 * hand, exactly as {@see HealthTerminalFrameTest} does — nothing here drives the ReactPHP loop.
 *
 * @see HealthHelpersTest for the codec-level coverage of the cached-path trap (point 2 of the issue):
 *      {@see Health::encodeHttpCacheEntry()} and {@see Health::decodeHttpCacheEntry()} are unit-tested
 *      there without a real cache backend, since the status only survives a second run if the value
 *      written to the cache on the first one can be decoded back correctly.
 */
#[CoversClass(Health::class)]
final class HealthHttpStatusExistsTest extends TestCase
{
    use HealthQueueIsolationTrait;

    /** The exact 400 the issue quotes, refusing an Ambrosian year before the rite's lower bound. */
    private const AMBROSIAN_400_BODY = '{"type":"https://www.rfc-editor.org/rfc/rfc9110.html#name-400-bad-request","title":"Bad Request","status":400,"detail":"The Ambrosian rite is only available from 1976 onward (the first reformed Ambrosian Missal); requested year 1970."}';

    /** A 404 with no problem-document `detail`, so the fallback-to-bare-status wording is exercised too. */
    private const PLAIN_404_BODY = '{"type":"about:blank","status":404}';

    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
        CheckableInventory::reset();
    }

    public static function tearDownAfterClass(): void
    {
        CheckableInventory::reset();
    }

    private ?string $appEnvBackup = null;

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

    // ---------------------------------------------------------------- harness

    /**
     * A minimal Ratchet connection that records every outbound frame, matching the stub convention
     * used across the Health suite rather than a PHPUnit mock, which would trigger a
     * dynamic-property deprecation over `resourceId`.
     */
    private static function stubConnection(int $resourceId = 1)
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
     * @param array<string, mixed> $payload
     */
    private static function send(Health $health, ConnectionInterface $conn, array $payload): void
    {
        $health->onMessage($conn, (string) json_encode($payload));
    }

    /**
     * @return list<\stdClass>
     */
    private static function framesOf(ConnectionInterface $conn): array
    {
        /** @var object{sent: list<string>} $conn */
        return array_map(
            static fn (string $raw): \stdClass => json_decode($raw),
            $conn->sent
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function queuedRequests(Health $health): array
    {
        /** @var list<array<string, mixed>> */
        return ( new \ReflectionProperty(Health::class, 'queue') )->getValue($health);
    }

    /**
     * Settle one outstanding request with the given status and body — the shape a real upstream
     * refusal takes, since `'http_errors' => false` means Guzzle resolves rather than rejects for
     * every status this test drives.
     */
    private static function fulfilQueuedRequestWithStatus(Health $health, int $index, int $status, string $body): void
    {
        $queued = self::queuedRequests($health);
        self::assertArrayHasKey($index, $queued, "expected a queued request at index {$index}");
        /** @var \Closure(ResponseInterface):void $resolve */
        $resolve = $queued[$index]['resolve'];

        ob_start();
        $resolve(new Response($status, ['Content-Type' => 'application/problem+json'], $body));
        ob_end_clean();
    }

    // ---------------------------------------------------------------- validateCalendar

    /**
     * The arm the issue is named after: a calendar year the API refuses with a 400. `exists` must
     * fail, quoting the problem document's `detail` verbatim, and `parses`/`validates` must still be
     * reported — failed, not skipped — per #821's one-frame-per-step contract.
     */
    public function testACalendarRefusedWithA400FailsExistsAndQuotesTheProblemDetail(): void
    {
        $health = $this->newHealth();
        $conn   = self::stubConnection();

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'rite', 'id' => 'ambrosian', 'rite' => 'ambrosian'],
            'year'           => 1970,
            'responseFormat' => 'JSON',
            'requestId'      => 'req-alpha'
        ]);
        self::fulfilQueuedRequestWithStatus($health, 0, 400, self::AMBROSIAN_400_BODY);

        $frames = self::framesOf($conn);
        self::assertSame(
            ['exists', 'parses', 'validates', 'complete'],
            array_map(static fn (\stdClass $f): ?string => property_exists($f, 'step') && is_string($f->step) ? $f->step : null, $frames),
            'a refused calendar still reports every published step, then terminates'
        );

        [$exists, $parses, $validates, $complete] = $frames;

        self::assertSame('fail', $exists->status, 'the defect: exists must not pass for a 400');
        self::assertStringContainsString('HTTP 400', $exists->text);
        self::assertStringContainsString(
            'The Ambrosian rite is only available from 1976 onward (the first reformed Ambrosian Missal); requested year 1970.',
            $exists->text,
            'the API\'s own problem-document detail must reach the client verbatim'
        );

        self::assertSame('fail', $parses->status, 'a refused request was never parsed, so this must not be a silent skip');
        self::assertSame('fail', $validates->status, 'a refused request was never schema-checked either');

        self::assertSame('req-alpha', $complete->requestId);
    }

    /**
     * A 404 with no `detail` in its body still fails `exists`, falling back to the bare status rather
     * than fabricating an explanation the response never gave.
     */
    public function testACalendarRefusedWithA404WithNoDetailFallsBackToTheBareStatus(): void
    {
        $health = $this->newHealth();
        $conn   = self::stubConnection();

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'national', 'id' => 'ZZ', 'rite' => 'roman'],
            'year'           => 2026,
            'responseFormat' => 'JSON',
            'requestId'      => 'req-beta'
        ]);
        self::fulfilQueuedRequestWithStatus($health, 0, 404, self::PLAIN_404_BODY);

        $frames = self::framesOf($conn);
        self::assertSame('fail', $frames[0]->status);
        self::assertStringContainsString('HTTP 404', $frames[0]->text);
        self::assertStringNotContainsString(': ', explode('HTTP 404', $frames[0]->text)[1] ?? '', 'no detail was available, so none is invented');
    }

    /**
     * A 200 is unaffected: the happy path this fix must not have broken.
     */
    public function testACalendarThatExistsStillPassesOnA200(): void
    {
        $health = $this->newHealth();
        $conn   = self::stubConnection();

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'           => 2026,
            'responseFormat' => 'JSON',
            'requestId'      => 'req-gamma'
        ]);
        self::fulfilQueuedRequestWithStatus($health, 0, 200, '{"litcal":[],"settings":{},"metadata":{},"messages":[]}');

        $frames = self::framesOf($conn);
        self::assertSame('pass', $frames[0]->status, 'precondition: a 200 must still be reported as existing');
    }

    // ---------------------------------------------------------------- resourceDataCheck (executeValidation)

    /**
     * The scope the issue calls out explicitly: `executeValidation` with `category: "resourceDataCheck"`,
     * whose `sourceFile` is a URL. It reaches {@see \LiturgicalCalendar\Api\Health::processValidationData()}
     * on a 2xx and {@see \LiturgicalCalendar\Api\Health::handleValidationHttpFailure()} otherwise — the
     * URL-based sibling of the unreadable-file arm {@see HealthValidationDataErrorTest} pins.
     */
    public function testAResourceUrlRefusedWithA400FailsAllThreePublishedSteps(): void
    {
        $health = $this->newHealth();
        $conn   = self::stubConnection();

        self::send($health, $conn, [
            'action'     => 'executeValidation',
            'category'   => 'resourceDataCheck',
            'validate'   => 'calendar-ambrosian-IT',
            'sourceFile' => 'https://example.test/calendar/ambrosian/IT/1970',
            'requestId'  => 'req-alpha'
        ]);
        self::fulfilQueuedRequestWithStatus($health, 0, 400, self::AMBROSIAN_400_BODY);

        $frames = self::framesOf($conn);
        self::assertSame(
            ['exists', 'parses', 'validates', 'complete'],
            array_map(static fn (\stdClass $f): ?string => property_exists($f, 'step') && is_string($f->step) ? $f->step : null, $frames)
        );

        [$exists, $parses, $validates] = $frames;
        self::assertSame('fail', $exists->status);
        self::assertStringContainsString('HTTP 400', $exists->text);
        self::assertStringContainsString(
            'The Ambrosian rite is only available from 1976 onward (the first reformed Ambrosian Missal); requested year 1970.',
            $exists->text
        );
        self::assertSame('fail', $parses->status, 'not attempted must be reported the same as failed, per #821');
        self::assertSame('fail', $validates->status);
    }

    /**
     * A 2xx resource is unaffected and still reaches `processValidationData()`, which is what
     * actually reports `exists` as passed for it.
     */
    public function testAResourceUrlThatExistsStillPassesOnA200(): void
    {
        $health = $this->newHealth();
        $conn   = self::stubConnection();

        self::send($health, $conn, [
            'action'     => 'executeValidation',
            'category'   => 'resourceDataCheck',
            'validate'   => 'calendar-roman-IT',
            'sourceFile' => 'https://example.test/calendar/nation/IT/2026',
            'requestId'  => 'req-beta'
        ]);
        self::fulfilQueuedRequestWithStatus($health, 0, 200, '{"litcal":[]}');

        $frames = self::framesOf($conn);
        self::assertSame('pass', $frames[0]->status);
    }

    // ---------------------------------------------------------------- runTest

    /**
     * A test run carries one step (`validates`), not three — but a refused calendar must still fail
     * it rather than be handed to `LitTestRunner` as if the problem document were calendar data.
     */
    public function testARunTestAgainstARefusedCalendarFailsRatherThanRunningOverAProblemDocument(): void
    {
        $health = $this->newHealth();
        $conn   = self::stubConnection();

        self::send($health, $conn, [
            'action'    => 'runTest',
            'test'      => 'SomeAmbrosianTest',
            'calendar'  => ['kind' => 'rite', 'id' => 'ambrosian', 'rite' => 'ambrosian'],
            'year'      => 1970,
            'requestId' => 'req-alpha'
        ]);
        self::fulfilQueuedRequestWithStatus($health, 0, 400, self::AMBROSIAN_400_BODY);

        $frames = self::framesOf($conn);
        self::assertSame(
            ['validates', 'complete'],
            array_map(static fn (\stdClass $f): ?string => property_exists($f, 'step') && is_string($f->step) ? $f->step : null, $frames)
        );
        self::assertSame('fail', $frames[0]->status);
        self::assertStringContainsString('HTTP 400', $frames[0]->text);
        self::assertStringContainsString(
            'The Ambrosian rite is only available from 1976 onward (the first reformed Ambrosian Missal); requested year 1970.',
            $frames[0]->text
        );
    }

    // ---------------------------------------------------------------- #834 review round 1

    /**
     * F2: a 429 is no longer rejected on its own — it reaches the same `exists` gate as every other
     * non-2xx, and its `Retry-After` hint is folded into the failure text rather than only visible on
     * a rejection's exception message (which nothing downstream of `cachedGet()` ever saw again).
     */
    public function testARateLimitedCalendarFailsExistsAndCarriesTheRetryAfterHint(): void
    {
        $health = $this->newHealth();
        $conn   = self::stubConnection();

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'           => 2026,
            'responseFormat' => 'JSON',
            'requestId'      => 'req-alpha'
        ]);

        $queued = self::queuedRequests($health);
        self::assertCount(1, $queued);
        /** @var \Closure(ResponseInterface):void $resolve */
        $resolve = $queued[0]['resolve'];
        ob_start();
        $resolve(new Response(429, ['Retry-After' => '30'], '{"type":"about:blank","status":429,"title":"Too Many Requests"}', '1.1', 'Too Many Requests'));
        ob_end_clean();

        $frames = self::framesOf($conn);
        self::assertSame('fail', $frames[0]->status, 'a 429 must not pass exists, same as any other non-2xx');
        self::assertStringContainsString('HTTP 429', $frames[0]->text);
        self::assertStringContainsString('Retry-After: 30', $frames[0]->text, 'the hint must survive the move off the rejection path');
    }

    /**
     * F1: `cachedGet()`'s cache-hit branch must treat an entry it cannot decode as a miss, not as a
     * rejection. A rejection there would fail every check whose cache entry predates a deploy — the
     * bare-body shape every entry had before #833 is exactly that shape — for the whole of its TTL,
     * which is the false-failure mirror of the bug #833 fixes, introduced by the fix itself.
     *
     * Driven through the real `cachedGet()` against a real (if minimal) APCu-shaped backend — see
     * `phpunit_tests/Support/ApcuShim.php` — rather than reasoning about the control flow, because
     * "it falls through" is exactly the kind of claim that is easy to get wrong by inspection alone.
     */
    public function testAMalformedLegacyCacheEntryFallsThroughToALiveRequestRatherThanFailing(): void
    {
        require_once __DIR__ . '/Support/ApcuShim.php';

        $health  = $this->newHealth();
        $url     = 'https://example.test/calendar/nation/IT/2026';
        $options = [];

        $cacheEnabledProp = new \ReflectionProperty(Health::class, 'cacheEnabled');
        $cacheBackendProp = new \ReflectionProperty(Health::class, 'cacheBackend');
        $enabledBefore    = $cacheEnabledProp->getValue();
        $backendBefore    = $cacheBackendProp->getValue();
        $cacheEnabledProp->setValue(null, true);
        $cacheBackendProp->setValue(null, 'apcu');

        $key = 'http_' . md5($url . serialize($options));

        try {
            // The bare-body shape every entry had before #833: no {"status":…,"body":…} envelope.
            ApcuShimStore::store($key, '{"litcal":[],"settings":{},"metadata":{},"messages":[]}', 300);
            self::assertTrue(ApcuShimStore::exists($key), 'precondition: the stale entry is actually in the cache');

            $cachedGet = new \ReflectionMethod(Health::class, 'cachedGet');
            ob_start();
            /** @var \React\Promise\PromiseInterface<array<string, mixed>> $promise */
            $promise = $cachedGet->invoke($health, $url, $options, 300, null);
            ob_end_clean();

            $resolved = null;
            $rejected = null;
            $promise->then(
                function (mixed $value) use (&$resolved): void {
                    $resolved = $value;
                },
                function (\Throwable $e) use (&$rejected): void {
                    $rejected = $e;
                }
            );

            self::assertNull($rejected, 'F1: a malformed cache entry must not surface as a rejection');
            self::assertNull($resolved, 'nothing has answered yet — a live request should have been queued instead of a synchronous answer');

            $queue = ( new \ReflectionProperty(Health::class, 'queue') )->getValue($health);
            self::assertCount(1, $queue, 'the malformed entry must fall through to a live request being queued, not dead-end at rejection');
            self::assertSame($url, $queue[0]['url']);

            self::assertFalse(ApcuShimStore::exists($key), 'the stale entry must be deleted, not left to reject the next request too');

            // Prove the round trip actually completes: fulfilling the queued request resolves the
            // very promise cachedGet() returned, exactly as an uncached first request would.
            /** @var \Closure(ResponseInterface):void $resolve */
            $resolve = $queue[0]['resolve'];
            ob_start();
            $resolve(new Response(200, [], '{"litcal":[]}'));
            ob_end_clean();

            self::assertNull($rejected);
            self::assertIsArray($resolved);
            self::assertSame(200, $resolved['status'] ?? null, 'the check must succeed on the live re-fetch, not stay broken');
            self::assertFalse($resolved['fromCache'] ?? null);
        } finally {
            $cacheEnabledProp->setValue(null, $enabledBefore);
            $cacheBackendProp->setValue(null, $backendBefore);
            ApcuShimStore::delete($key);
        }
    }

    /**
     * A cached status outside the range HTTP defines is a corrupt entry, not a refusal.
     *
     * `is_int()` alone accepts `0`, `-1` and `99999`, and any of those would reach the `exists`
     * gate as "not a 2xx" and fail a check that may be perfectly healthy — the same false failure
     * the malformed-entry fall-through exists to prevent, arriving through a weaker type check
     * rather than a missing one. Range-validating turns it back into a miss.
     */
    public function testACachedStatusOutsideTheHttpRangeIsAMissRatherThanARefusal(): void
    {
        require_once __DIR__ . '/Support/ApcuShim.php';

        $health  = $this->newHealth();
        $url     = 'https://example.test/calendar/nation/IT/2027';
        $options = [];

        $cacheEnabledProp = new \ReflectionProperty(Health::class, 'cacheEnabled');
        $cacheBackendProp = new \ReflectionProperty(Health::class, 'cacheBackend');
        $enabledBefore    = $cacheEnabledProp->getValue();
        $backendBefore    = $cacheBackendProp->getValue();
        $cacheEnabledProp->setValue(null, true);
        $cacheBackendProp->setValue(null, 'apcu');

        $key = 'http_' . md5($url . serialize($options));

        try {
            // Well-formed envelope, impossible status.
            ApcuShimStore::store($key, (string) json_encode(['status' => 0, 'body' => '{"litcal":[]}']), 300);
            self::assertTrue(ApcuShimStore::exists($key), 'precondition: the corrupt entry is actually in the cache');

            $cachedGet = new \ReflectionMethod(Health::class, 'cachedGet');
            ob_start();
            /** @var \React\Promise\PromiseInterface<array<string, mixed>> $promise */
            $promise = $cachedGet->invoke($health, $url, $options, 300, null);
            ob_end_clean();

            $rejected = null;
            $resolved = null;
            $promise->then(
                function (mixed $value) use (&$resolved): void {
                    $resolved = $value;
                },
                function (\Throwable $e) use (&$rejected): void {
                    $rejected = $e;
                }
            );

            self::assertNull($rejected, 'a corrupt cached status must not surface as a rejection');
            self::assertNull($resolved, 'nor as a synchronous answer carrying the impossible status');

            $queue = ( new \ReflectionProperty(Health::class, 'queue') )->getValue($health);
            self::assertCount(1, $queue, 'an out-of-range status must fall through to a live request');
            self::assertSame($url, $queue[0]['url']);
            self::assertFalse(ApcuShimStore::exists($key), 'the corrupt entry must be deleted, not left to be re-read');
        } finally {
            ApcuShimStore::delete($key);
            $cacheEnabledProp->setValue(null, $enabledBefore);
            $cacheBackendProp->setValue(null, $backendBefore);
        }
    }
}
