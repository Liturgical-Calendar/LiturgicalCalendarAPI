<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\ApcuCache;
use LiturgicalCalendar\Api\ApcuShimStore;
use LiturgicalCalendar\Api\Models\MissalsPath\MissalMetadataMap;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Utilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * #836: an APCu that is present but inert must not be selected as the JSON-file cache either.
 *
 * The same predicate #835 replaced in `Health` — `extension_loaded('apcu')` plus `function_exists()`
 * on three of the `apcu_*` names — survived in six further places: the four `Utilities::jsonFileTo*()`
 * / `jsonUrlToObject()` readers, `Utilities::invalidateJsonFileCache()` (a narrower variant), and
 * `MissalMetadataMap::__construct()`. All of them now consult {@see ApcuCache}, whose answer comes
 * from a store→fetch round trip.
 *
 * Both directions are asserted, as in {@see HealthApcuDetectionTest}: a probe that always answered
 * "no" would satisfy every negative case here while silently disabling caching outright, so the
 * positive cases are what make the negative ones mean something.
 *
 * **Why the sites are tested and not only the probe.** `ApcuCache` exists in the
 * `LiturgicalCalendar\Api` namespace on purpose. PHP resolves an unqualified `apcu_store()` against
 * the *calling* namespace before the global one, so a probe run inside `LiturgicalCalendar\Api` only
 * describes the functions a caller in `LiturgicalCalendar\Api` will really reach.
 * `MissalMetadataMap` lives in `LiturgicalCalendar\Api\Models\MissalsPath`, where the very same
 * unqualified call resolves somewhere else entirely — which is why it no longer calls `apcu_*` at all
 * and goes through `ApcuCache` instead. `testMissalMetadataMapCachesThroughTheSharedHelper()` is what
 * holds that property down: before #836 it failed, because the map's own unqualified calls bypassed
 * `phpunit_tests/Support/ApcuShim.php` and landed on the global (real, and under the CLI SAPI inert)
 * functions.
 */
#[CoversClass(ApcuCache::class)]
final class ApcuCacheDetectionTest extends TestCase
{
    private const PROBE_KEY_PREFIX  = 'litcal_apcu_probe_';
    private const MISSALS_INDEX_KEY = 'litcal_missals_index';

    private ?string $tmpFile    = null;
    private ?bool $usableBefore = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // MissalMetadataMap::buildIndex() resolves JsonData paths through Router::$apiFilePath and
        // composes api_path values from Router::$apiPath, both uninitialized typed statics.
        Router::getApiPaths();
    }

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Support/ApcuShim.php';

        // The memoised answer is process-wide state, and this class deliberately flips what the
        // answer would be. Capture it so tearDown can put the process back exactly as it was found:
        // the shim's functions cannot be un-declared once loaded, so a memo left unresolved here
        // would let this class retroactively switch Utilities caching on for every later test.
        $usable             = ( new \ReflectionProperty(ApcuCache::class, 'usable') )->getValue();
        $this->usableBefore = is_bool($usable) ? $usable : null;
        self::forgetProbeResult();

        self::assertShimIsTheBoundBackend();

        $this->tmpFile = sys_get_temp_dir() . '/litcal_apcu_cache_test_' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        ApcuShimStore::simulateDisabledStore(false);
        ApcuShimStore::simulateThrowingStore(false);

        if (null !== $this->tmpFile) {
            Utilities::invalidateJsonFileCache($this->tmpFile);
            if (file_exists($this->tmpFile)) {
                unlink($this->tmpFile);
            }
        }
        ApcuShimStore::delete(self::MISSALS_INDEX_KEY);

        ( new \ReflectionProperty(ApcuCache::class, 'usable') )->setValue(null, $this->usableBefore);
        parent::tearDown();
    }

    // ---------------------------------------------------------------- harness

    /**
     * Drop the memoised probe result so the next question is answered against the fault switches the
     * calling test has just set. Memoisation is what keeps a store/fetch/delete off the hot path of
     * every JSON file read; it is also what would otherwise freeze the first test's answer for all
     * the rest.
     */
    private static function forgetProbeResult(): void
    {
        ( new \ReflectionProperty(ApcuCache::class, 'usable') )->setValue(null, null);
    }

    /**
     * Fail loudly if `ApcuCache`'s calls are not reaching the shim.
     *
     * PHP's `INIT_NS_FCALL_BY_NAME` caches the function it resolved in the opline's run-time cache,
     * so a call site that once executed while the shim was not loaded stays bound to the *global*
     * `apcu_*` for the life of the process. Nothing in this class can undo that, and the failure is
     * silent in the worst way: the fault switches below would simply have no effect, and the
     * positive cases would pass against a real backend while the negative ones passed for the wrong
     * reason.
     *
     * `phpunit_tests/bootstrap.php` is what keeps this from happening — by pinning the memo it stops
     * `Utilities` reaching an `apcu_*` opline at all on hosts where the answer is `false`. But that
     * argument does not hold on a host where the probe genuinely answers `true` (a real APCu with
     * `apc.enable_cli=1`), where an earlier test *can* bind these sites first. Rather than load the
     * shim from the bootstrap — which would also bind `Health`'s call sites to it and change what
     * `Health::apcuUsable()` measures on such a host — the coupling is asserted here, where it is
     * cheap and where a violation is a test failure rather than a wrong answer.
     */
    private static function assertShimIsTheBoundBackend(): void
    {
        ApcuShimStore::simulateDisabledStore(false);
        ApcuShimStore::simulateThrowingStore(false);

        $sentinel = self::PROBE_KEY_PREFIX . 'binding_' . uniqid();
        ApcuShimStore::store($sentinel, 'bound', 10);

        ( new \ReflectionProperty(ApcuCache::class, 'usable') )->setValue(null, true);

        try {
            $value = ApcuCache::fetch($sentinel, $found);
            self::assertTrue(
                $found && 'bound' === $value,
                'ApcuCache is not reaching phpunit_tests/Support/ApcuShim.php: its apcu_* call sites were '
                . 'bound to the global functions before the shim was loaded, so the fault switches this '
                . 'class relies on cannot take effect. See assertShimIsTheBoundBackend().'
            );
        } finally {
            ApcuShimStore::delete($sentinel);
            self::forgetProbeResult();
        }
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

    private function writeTmpFile(mixed $contents): string
    {
        self::assertIsString($this->tmpFile);
        file_put_contents($this->tmpFile, json_encode($contents, JSON_THROW_ON_ERROR));

        return $this->tmpFile;
    }

    // ---------------------------------------------------------------- the probe itself

    /**
     * The defect, reduced to its smallest statement: the functions are all present, the store reports
     * success, and nothing comes back. That must read as unusable.
     */
    public function testAnApcuThatReportsSuccessButStoresNothingIsNotUsable(): void
    {
        ApcuShimStore::simulateDisabledStore(true);

        self::assertFalse(
            ApcuCache::isUsable(),
            'an APCu whose writes silently vanish must not be reported as usable, however present its functions are'
        );
    }

    /**
     * The other half of the pair: a backend that does round-trip is still used, so the probe is not
     * simply always-false — which would "fix" the issue by disabling caching everywhere.
     */
    public function testAnApcuThatRoundTripsIsUsable(): void
    {
        self::assertTrue(
            ApcuCache::isUsable(),
            'a store/fetch round trip that works must be reported as usable'
        );
    }

    /**
     * A probe key is a write into a live cache and must not outlive the question it answers.
     */
    public function testTheProbeCleansUpAfterItself(): void
    {
        self::assertTrue(ApcuCache::isUsable(), 'precondition: the probe took the store/fetch path');

        self::assertSame(
            [],
            self::probeKeysLeftBehind(),
            'the probe key must be deleted, not left sitting in the cache it was only testing'
        );
    }

    /**
     * The probe owes its callers a bool even when the backend raises. Unlike `Health`, these callers
     * sit on the HTTP request path: an escaping exception would turn a degraded cache into a failed
     * `/calendar` response.
     */
    public function testAnApcuWhoseStoreThrowsIsNotUsableRatherThanPropagating(): void
    {
        ApcuShimStore::simulateThrowingStore(true);

        self::assertFalse(
            ApcuCache::isUsable(),
            'a store that throws must read as unusable, not escape as an exception'
        );
    }

    /**
     * Nothing may be written to stdout on the way to that answer. `Health` reports its probe failures
     * with `echo` because it is a CLI process; the same line from `Utilities` would be prepended to a
     * PSR-7 response body.
     */
    public function testTheProbeNeverWritesToOutput(): void
    {
        ApcuShimStore::simulateThrowingStore(true);

        ob_start();
        ApcuCache::isUsable();
        $output = ob_get_clean();

        self::assertSame('', $output, 'a probe failure must not be echoed into an HTTP response body');
    }

    // ---------------------------------------------------------------- Utilities::jsonFileTo*()

    /**
     * A usable backend is still used: prime, rewrite the file underneath, and the stale value must
     * come back. Before #836 this failed wherever `extension_loaded('apcu')` was false — the operand
     * is not merely subsumed by the round trip, it actively contradicts it whenever the functions
     * that will really be called are not the extension's.
     */
    public function testJsonFileToArrayServesFromCacheWhenApcuRoundTrips(): void
    {
        $file = $this->writeTmpFile(['greeting' => 'hello']);

        self::assertSame('hello', Utilities::jsonFileToArray($file)['greeting']);

        $this->writeTmpFile(['greeting' => 'world']);

        self::assertSame(
            'hello',
            Utilities::jsonFileToArray($file)['greeting'],
            'a working APCu must actually be used — otherwise the fix amounts to switching caching off'
        );
    }

    /**
     * And an inert one is not: every read goes back to disk, so a rewrite is visible immediately.
     */
    public function testJsonFileToArrayDoesNotCacheWhenApcuStoresNothing(): void
    {
        $file = $this->writeTmpFile(['greeting' => 'hello']);
        ApcuShimStore::simulateDisabledStore(true);

        self::assertSame('hello', Utilities::jsonFileToArray($file)['greeting']);

        $this->writeTmpFile(['greeting' => 'world']);

        self::assertSame(
            'world',
            Utilities::jsonFileToArray($file)['greeting'],
            'an inert APCu must leave the reader going to disk, not pretending it has a cache'
        );
    }

    /**
     * `jsonFileToObject()` and `jsonUrlToObject()` share a cache-key prefix, and the object readers
     * take a different branch from the array one; assert the same pair for the object reader.
     */
    public function testJsonFileToObjectServesFromCacheWhenApcuRoundTrips(): void
    {
        $file = $this->writeTmpFile(['status' => 'initial']);

        self::assertSame('initial', Utilities::jsonFileToObject($file)->status);

        $this->writeTmpFile(['status' => 'refreshed']);

        self::assertSame('initial', Utilities::jsonFileToObject($file)->status);

        Utilities::invalidateJsonFileCache($file);

        self::assertSame(
            'refreshed',
            Utilities::jsonFileToObject($file)->status,
            'invalidation must reach the same backend the reader used'
        );
    }

    /**
     * The object-array reader has its own key prefix and its own fetch branch.
     */
    public function testJsonFileToObjectArrayServesFromCacheWhenApcuRoundTrips(): void
    {
        $file = $this->writeTmpFile([['key' => 'original_value']]);

        self::assertSame('original_value', Utilities::jsonFileToObjectArray($file)[0]->key);

        $this->writeTmpFile([['key' => 'updated_value']]);

        self::assertSame('original_value', Utilities::jsonFileToObjectArray($file)[0]->key);

        Utilities::invalidateJsonFileCache($file);

        self::assertSame(
            'updated_value',
            Utilities::jsonFileToObjectArray($file)[0]->key,
            'invalidation must clear the object-array key too'
        );
    }

    /**
     * A backend that raises must not take the request down with it. Before #836 the reader called
     * `apcu_store()` on the strength of `function_exists()` alone, so a throwing backend threw
     * straight out of `jsonFileToArray()` — a JSON file read that had already succeeded.
     */
    public function testJsonFileToArraySurvivesAThrowingApcu(): void
    {
        $file = $this->writeTmpFile(['greeting' => 'hello']);
        ApcuShimStore::simulateThrowingStore(true);

        self::assertSame(
            'hello',
            Utilities::jsonFileToArray($file)['greeting'],
            'a throwing cache backend must degrade to no cache, not to a failed read'
        );
    }

    /**
     * `invalidateJsonFileCache()` carried the narrow variant of the predicate,
     * `!extension_loaded('apcu') || !function_exists('apcu_delete')`. Its `extension_loaded()` operand
     * is wrong for the same reason the full predicate's is: it asks about the extension rather than
     * about the functions the unqualified calls actually reach.
     */
    public function testInvalidationReachesTheBackendTheReaderWroteTo(): void
    {
        $file = $this->writeTmpFile(['greeting' => 'hello']);

        Utilities::jsonFileToArray($file);
        $this->writeTmpFile(['greeting' => 'world']);
        Utilities::invalidateJsonFileCache($file);

        self::assertSame(
            'world',
            Utilities::jsonFileToArray($file)['greeting'],
            'invalidation must not be gated on an extension the reader never consulted'
        );
    }

    // ---------------------------------------------------------------- MissalMetadataMap

    /**
     * The namespace question, made observable.
     *
     * `MissalMetadataMap` lives in `LiturgicalCalendar\Api\Models\MissalsPath`, so its own unqualified
     * `apcu_store()` resolved to the global function — never to the shim, which declares its
     * stand-ins in `LiturgicalCalendar\Api`. It therefore decided usability from one set of functions
     * while writing through another. Routing it through `ApcuCache` collapses that into one question
     * with one answer, and this assertion is what proves the write really lands where the decision
     * was made.
     */
    public function testMissalMetadataMapCachesThroughTheSharedHelper(): void
    {
        ApcuShimStore::delete(self::MISSALS_INDEX_KEY);

        ( new MissalMetadataMap() )->buildIndex();

        self::assertTrue(
            ApcuShimStore::exists(self::MISSALS_INDEX_KEY),
            'the missals index must be written to the same backend the usability decision was made against'
        );
    }

    /**
     * And an inert backend leaves nothing behind, rather than a decision that says otherwise.
     */
    public function testMissalMetadataMapWritesNothingWhenApcuStoresNothing(): void
    {
        ApcuShimStore::delete(self::MISSALS_INDEX_KEY);
        ApcuShimStore::simulateDisabledStore(true);

        ( new MissalMetadataMap() )->buildIndex();

        self::assertFalse(
            ApcuShimStore::exists(self::MISSALS_INDEX_KEY),
            'an inert APCu must not be selected as the missals-index cache'
        );
    }

    /**
     * The map builds its index on the HTTP request path for `/missals`; a throwing backend must cost
     * the cache, not the response.
     */
    public function testMissalMetadataMapSurvivesAThrowingApcu(): void
    {
        ApcuShimStore::simulateThrowingStore(true);

        $map = new MissalMetadataMap();
        $map->buildIndex();

        self::assertFalse($map->isEmpty(), 'the index must still be built when the cache backend raises');
    }
}
