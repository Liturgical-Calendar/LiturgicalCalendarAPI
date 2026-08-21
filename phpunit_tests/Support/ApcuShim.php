<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api;

// A minimal in-memory stand-in for the APCu functions `Health` calls (apcu_store/apcu_fetch/
// apcu_exists/apcu_delete/apcu_sma_info).
//
// Declared in the `LiturgicalCalendar\Api` namespace — the namespace `Health.php` itself lives in —
// rather than the global one, and unconditionally rather than guarded by `function_exists()`. PHP
// resolves an unqualified function call to the *current* namespace before falling back to the global
// one, so once this file is loaded, every unqualified `apcu_*` call `Health.php` makes resolves to
// the functions below regardless of whether the real ext-apcu is installed, and regardless of
// whether it is enabled for the CLI SAPI.
//
// That last clause is not hypothetical: CI installs the real extension, but the workflow's
// `apcu.enable_cli=1` ini setting is a no-op — APCu's actual setting is `apc.enable_cli` (inherited
// from APC's naming), not `apcu.`. So in CI the extension is loaded but silently disabled under the
// CLI SAPI: `apcu_store()` returns without storing anything and `apcu_exists()` always reports false.
// A global-namespace shim guarded by `function_exists('apcu_store')` — this file's first version —
// never activated there, because the real (if inert) function already existed. Namespace-first
// resolution sidesteps that runtime-configuration question entirely: it does not matter whether real
// APCu exists, is loaded, or is CLI-enabled, because `LiturgicalCalendar\Api\apcu_store` is found
// before PHP ever looks for the global one.
//
// This exists so a test can drive `Health::cachedGet()`'s actual cache-hit branch — including the
// malformed/legacy-entry and out-of-range-status fall-throughs #834 fixed — through the real code
// path, instead of re-implementing that branch's logic in the test and only reasoning about whether
// the real one matches it. Deliberately not a mock of `Health`'s cache methods: those stay untouched,
// and only the backend three layers below them is stood in for.
//
// Tests must never call `apcu_store()`/`apcu_fetch()`/`apcu_exists()`/`apcu_delete()` directly: a
// test lives in `LiturgicalCalendar\Tests`, so an unqualified call from there resolves to
// `LiturgicalCalendar\Tests\apcu_*` (undefined), then falls back to the *global* — real — function,
// not this shim. That would have the test writing to one store and `Health` reading from another,
// failing the test's own precondition for a reason that has nothing to do with the code under test.
// {@see ApcuShimStore} is the accessor tests use instead, backed by the same array these functions
// read and write.

/**
 * The backing store shared between the namespaced `apcu_*` shim functions below (which `Health.php`
 * calls unqualified) and the tests that seed or inspect it directly. A single shared store is the
 * whole point: it is what makes "the test wrote this entry" and "Health read this entry" the same
 * claim, rather than two stores that happen to have similar names.
 */
final class ApcuShimStore
{
    /** @var array<string, array{value: string, expires: int}> */
    private static array $store = [];

    /**
     * Fault injection for #835: when true, `store()` reports success and stores nothing.
     *
     * That is exactly the shape of the defect the round-trip probe exists to catch — an APCu that is
     * loaded and whose functions all exist, but which is inert (CLI-disabled, or a full/broken shared
     * memory segment), so every write is silently dropped while reporting success. Reporting success
     * is the harsher of the two possible behaviours and the one that defeats any check that trusts
     * `apcu_store()`'s return value, which is why the shim simulates it that way round.
     */
    private static bool $storeIsNoOp = false;

    /**
     * Fault injection for #835: when true, `store()` raises instead of returning.
     *
     * A separate failure mode from {@see self::$storeIsNoOp}: that one models a backend that answers
     * dishonestly, this one models one that blows up. `Health::apcuUsable()` owes its callers a bool
     * in both cases — a raised exception would propagate out of `handleRedisFailure()` during the
     * Redis outage the APCu fallback exists to cover.
     */
    private static bool $storeThrows = false;

    /**
     * Make every subsequent `store()` a reported-success no-op (or stop doing so).
     *
     * Process-wide state, so a test that turns it on must turn it off again in a `finally`/`tearDown`.
     */
    public static function simulateDisabledStore(bool $noOp): void
    {
        self::$storeIsNoOp = $noOp;
    }

    /**
     * Make every subsequent `store()` throw a \RuntimeException (or stop doing so).
     *
     * Process-wide state, so a test that turns it on must turn it off again in a `finally`/`tearDown`.
     */
    public static function simulateThrowingStore(bool $throws): void
    {
        self::$storeThrows = $throws;
    }

    /**
     * The keys currently held, expired entries included — for asserting that a probe cleaned up after
     * itself rather than leaving a key behind in the cache it was only meant to test.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::$store);
    }

    public static function store(string $key, mixed $value, int $ttl = 0): bool
    {
        if (self::$storeThrows) {
            throw new \RuntimeException('simulated APCu store failure');
        }
        if (self::$storeIsNoOp) {
            return true;
        }
        self::$store[$key] = [
            'value'   => $value,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
        ];
        return true;
    }

    public static function fetch(string $key, ?bool &$success = null): mixed
    {
        $entry = self::$store[$key] ?? null;
        if (null === $entry || ( $entry['expires'] > 0 && $entry['expires'] < time() )) {
            $success = false;
            return false;
        }
        $success = true;
        return $entry['value'];
    }

    public static function exists(string $key): bool
    {
        $entry = self::$store[$key] ?? null;
        if (null === $entry) {
            return false;
        }
        if ($entry['expires'] > 0 && $entry['expires'] < time()) {
            unset(self::$store[$key]);
            return false;
        }
        return true;
    }

    public static function delete(string $key): bool
    {
        $existed = isset(self::$store[$key]);
        unset(self::$store[$key]);
        return $existed;
    }
}

function apcu_store(string $key, mixed $value, int $ttl = 0): bool
{
    return ApcuShimStore::store($key, $value, $ttl);
}

function apcu_fetch(string $key, ?bool &$success = null): mixed
{
    return ApcuShimStore::fetch($key, $success);
}

function apcu_exists(string $key): bool
{
    return ApcuShimStore::exists($key);
}

function apcu_delete(string $key): bool
{
    return ApcuShimStore::delete($key);
}

/**
 * @return array{seg_size: int, avail_mem: int}
 */
function apcu_sma_info(bool $limited = false): array
{
    return ['seg_size' => 0, 'avail_mem' => 0];
}
