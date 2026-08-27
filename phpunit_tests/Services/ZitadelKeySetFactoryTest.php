<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use Firebase\JWT\CachedKeySet;
use LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware;
use LiturgicalCalendar\Api\Services\ZitadelKeySetFactory;
use PHPUnit\Framework\TestCase;

/**
 * The shared JWKS key set — #894.
 *
 * The memoization is the point rather than an optimisation detail: the WebSocket server verifies
 * tokens from inside a single-threaded ReactPHP loop, where a fetch stalls every other connection,
 * so a key set rebuilt per call would reintroduce exactly the cost the sharing exists to remove.
 */
final class ZitadelKeySetFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        ZitadelKeySetFactory::reset();
    }

    protected function tearDown(): void
    {
        ZitadelKeySetFactory::reset();
        parent::tearDown();
    }

    public function testTheSameIssuerIsMemoized(): void
    {
        $first  = ZitadelKeySetFactory::for('https://issuer.example.test');
        $second = ZitadelKeySetFactory::for('https://issuer.example.test');

        $this->assertInstanceOf(CachedKeySet::class, $first);
        $this->assertSame($first, $second);
    }

    /**
     * A trailing slash is a spelling, not a different provider — and `OidcAuthMiddleware` already
     * rtrims it when it stores the issuer, so the factory must agree or the two callers would build
     * separate key sets for the same server.
     */
    public function testATrailingSlashNamesTheSameIssuer(): void
    {
        $bare    = ZitadelKeySetFactory::for('https://issuer.example.test');
        $slashed = ZitadelKeySetFactory::for('https://issuer.example.test/');

        $this->assertSame($bare, $slashed);
    }

    public function testDifferentIssuersGetDifferentKeySets(): void
    {
        $one = ZitadelKeySetFactory::for('https://one.example.test');
        $two = ZitadelKeySetFactory::for('https://two.example.test');

        $this->assertNotSame($one, $two);
    }

    public function testResetDropsTheMemo(): void
    {
        $before = ZitadelKeySetFactory::for('https://issuer.example.test');
        ZitadelKeySetFactory::reset();
        $after = ZitadelKeySetFactory::for('https://issuer.example.test');

        $this->assertNotSame($before, $after);
    }

    public function testTheInternalUrlVariantStillProducesAKeySet(): void
    {
        $keySet = ZitadelKeySetFactory::for('https://issuer.example.test', 'http://zitadel:8080');

        $this->assertInstanceOf(CachedKeySet::class, $keySet);
    }

    /**
     * The JWKS fetch must be bounded, and this is the guard against the bound being dropped.
     *
     * `CachedKeySet` fetches synchronously from inside `JWT::decode()` on a cold cache or an unknown
     * `kid`, and Guzzle's default for both options is 0 — wait forever. In the HTTP API that costs one
     * php-fpm worker; in the WebSocket server it stalls the single Ratchet event loop for every
     * connected client at once. A provider that accepts the connection and then goes quiet is
     * indistinguishable from a healthy one without a timeout.
     */
    public function testTheJwksFetchIsBounded(): void
    {
        $options = ZitadelKeySetFactory::HTTP_OPTIONS;

        $this->assertArrayHasKey('connect_timeout', $options);
        $this->assertArrayHasKey('timeout', $options);
        $this->assertGreaterThan(0, $options['connect_timeout'], 'zero means wait indefinitely');
        $this->assertGreaterThan(0, $options['timeout'], 'zero means wait indefinitely');
        $this->assertLessThanOrEqual(10, $options['timeout'], 'a fetch this slow will not produce a useful answer');
    }

    /**
     * `OidcAuthMiddleware::resetKeySetCache()` is public API that predates the factory; it now
     * delegates, and this pins that it still clears what callers expect it to clear.
     */
    public function testTheMiddlewareResetHelperStillClearsTheSharedMemo(): void
    {
        $before = ZitadelKeySetFactory::for('https://issuer.example.test');
        OidcAuthMiddleware::resetKeySetCache();
        $after = ZitadelKeySetFactory::for('https://issuer.example.test');

        $this->assertNotSame($before, $after);
    }
}
