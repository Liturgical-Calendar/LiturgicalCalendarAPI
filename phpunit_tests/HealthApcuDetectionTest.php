<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\ApcuShimStore;
use LiturgicalCalendar\Api\Health;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * #835: an APCu that is present but inert must not be selected as the cache backend.
 *
 * The predicate this replaces was `extension_loaded('apcu') && function_exists('apcu_exists'|'apcu_store'|'apcu_fetch')`,
 * and on the deployment host all four are true while `apc.enable_cli=0` leaves APCu doing nothing under
 * the CLI SAPI the WebSocket server runs in. Selecting that backend produces a cache that stores
 * nothing: every `cacheSet()` reports success, every `cacheGet()` misses, and the only symptom is quiet
 * slowness — reached only on the Redis-outage fallback, i.e. exactly when the fallback is what is being
 * relied on. Nothing exercised either detection site, which is how the gap survived.
 *
 * Both directions are asserted deliberately: a probe that always fails would satisfy the negative case
 * on its own while disabling caching everywhere. Read as a pair, they say that flipping *only* the
 * round trip — same extension, same functions, same everything else — is what flips the decision.
 *
 * The backend is driven through {@see \LiturgicalCalendar\Api\Health::apcuUsable()} and through the
 * real Redis-failure fallback ({@see \LiturgicalCalendar\Api\Health::handleRedisFailure()}), not by
 * setting `cacheBackend` by reflection the way the cache-*behaviour* tests do — the selection decision
 * is the thing under test here.
 *
 * `apcu_*` calls inside `Health` resolve to `phpunit_tests/Support/ApcuShim.php`'s namespaced
 * stand-ins (see that file's header for why namespace-first resolution is load-bearing in CI), so
 * `ApcuShimStore::simulateDisabledStore()` can make a store silently drop what it is given while still
 * reporting success — the inert-APCu behaviour, reproduced without needing an inert APCu.
 */
#[CoversClass(Health::class)]
final class HealthApcuDetectionTest extends TestCase
{
    private const PROBE_KEY_PREFIX = 'health_apcu_probe_';

    private bool $cacheEnabledBefore;
    private string $cacheBackendBefore;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Support/ApcuShim.php';

        $enabled                  = ( new \ReflectionProperty(Health::class, 'cacheEnabled') )->getValue();
        $backend                  = ( new \ReflectionProperty(Health::class, 'cacheBackend') )->getValue();
        $this->cacheEnabledBefore = is_bool($enabled) ? $enabled : false;
        $this->cacheBackendBefore = is_string($backend) ? $backend : 'none';
    }

    protected function tearDown(): void
    {
        ApcuShimStore::simulateDisabledStore(false);
        ( new \ReflectionProperty(Health::class, 'cacheEnabled') )->setValue(null, $this->cacheEnabledBefore);
        ( new \ReflectionProperty(Health::class, 'cacheBackend') )->setValue(null, $this->cacheBackendBefore);
        parent::tearDown();
    }

    // ---------------------------------------------------------------- harness

    private static function apcuUsable(): bool
    {
        $usable = ( new \ReflectionMethod(Health::class, 'apcuUsable') )->invoke(null);
        self::assertIsBool($usable);

        return $usable;
    }

    /**
     * Drive the real Redis-connection-lost fallback, which is one of the two sites that has to make
     * this decision (the other being initial selection in `onOpen()`).
     */
    private static function handleRedisFailure(): void
    {
        ob_start();
        ( new \ReflectionMethod(Health::class, 'handleRedisFailure') )->invoke(null, new \RedisException('connection lost'));
        ob_end_clean();
    }

    /**
     * @return array{0: bool, 1: string} [cacheEnabled, cacheBackend]
     */
    private static function backendState(): array
    {
        $enabled = ( new \ReflectionProperty(Health::class, 'cacheEnabled') )->getValue();
        $backend = ( new \ReflectionProperty(Health::class, 'cacheBackend') )->getValue();
        self::assertIsBool($enabled);
        self::assertIsString($backend);

        return [$enabled, $backend];
    }

    /**
     * @return list<string>
     */
    private static function probeKeysLeftBehind(): array
    {
        return array_values(array_filter(
            ApcuShimStore::keys(),
            static fn (string $key): bool => str_starts_with($key, self::PROBE_KEY_PREFIX)
        ));
    }

    // ---------------------------------------------------------------- the probe itself

    /**
     * The defect, reduced to its smallest statement: functions all present, store reports success,
     * nothing comes back. That must read as unusable.
     */
    public function testAnApcuThatReportsSuccessButStoresNothingIsNotUsable(): void
    {
        ApcuShimStore::simulateDisabledStore(true);

        self::assertFalse(
            self::apcuUsable(),
            'an APCu whose writes silently vanish must not be reported as usable, however present its functions are'
        );
    }

    /**
     * The other half of the pair: a backend that does round-trip is still selected, so the probe is
     * not simply always-false — which would "fix" the issue by disabling caching outright.
     */
    public function testAnApcuThatRoundTripsIsUsable(): void
    {
        self::assertTrue(
            self::apcuUsable(),
            'a store/fetch round trip that works must be reported as usable'
        );
    }

    /**
     * A probe key is a write into a live cache and must not outlive the question it answers.
     */
    public function testTheProbeCleansUpAfterItself(): void
    {
        self::assertTrue(self::apcuUsable(), 'precondition: the probe took the store/fetch path');

        self::assertSame(
            [],
            self::probeKeysLeftBehind(),
            'the probe key must be deleted, not left sitting in the cache it was only testing'
        );
    }

    // ---------------------------------------------------------------- the Redis-outage fallback site

    /**
     * The path the issue is actually about: Redis drops, and the fallback must refuse an APCu that
     * stores nothing rather than switching to it and quietly caching into the void.
     */
    public function testTheRedisFallbackDisablesCachingWhenApcuStoresNothing(): void
    {
        ( new \ReflectionProperty(Health::class, 'cacheEnabled') )->setValue(null, true);
        ( new \ReflectionProperty(Health::class, 'cacheBackend') )->setValue(null, 'redis');
        ApcuShimStore::simulateDisabledStore(true);

        self::handleRedisFailure();

        self::assertSame(
            [false, 'none'],
            self::backendState(),
            'an inert APCu must fall to `none` with caching off, exactly as an absent one does'
        );
    }

    /**
     * And the fallback still works when there is something to fall back to.
     */
    public function testTheRedisFallbackSelectsApcuWhenTheRoundTripSucceeds(): void
    {
        ( new \ReflectionProperty(Health::class, 'cacheEnabled') )->setValue(null, true);
        ( new \ReflectionProperty(Health::class, 'cacheBackend') )->setValue(null, 'redis');

        self::handleRedisFailure();

        self::assertSame(
            [true, 'apcu'],
            self::backendState(),
            'a working APCu is still the fallback — this fix must not amount to switching the fallback off'
        );
    }
}
