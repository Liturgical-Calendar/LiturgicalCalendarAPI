<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api;

/**
 * The one place in the request path that decides whether APCu is usable, and the one place that calls
 * `apcu_*`.
 *
 * ## Why this class exists at all (#836)
 *
 * Six sites used to answer "is there a cache?" with
 * `extension_loaded('apcu') && function_exists('apcu_exists') && function_exists('apcu_store') && function_exists('apcu_fetch')`.
 * Every operand can be true while APCu stores nothing: on the deployment host `apc.enable_cli=0`
 * leaves the extension loaded, its functions defined, and the store inert under the CLI SAPI — every
 * write reports success, every read misses. #835 established the shape of the defect and replaced the
 * predicate in `Health` with a store→fetch round trip; #836 is the same replacement for the other six.
 *
 * A round trip also answers questions no configuration flag does: a full or corrupted shared-memory
 * segment reports nothing at all, and `apcu_enabled()` would still say yes.
 *
 * ## Why it lives in `LiturgicalCalendar\Api` and not in a `Cache\` sub-namespace
 *
 * This is the subtle part, and it is a correctness constraint rather than a matter of taste.
 *
 * PHP resolves an *unqualified* function call against the current namespace first and only then the
 * global one. A probe therefore describes the functions reachable **from the namespace the probe's own
 * calls are written in** — nothing more. That is exactly what makes
 * `phpunit_tests/Support/ApcuShim.php` able to stand in for the backend: it declares
 * `LiturgicalCalendar\Api\apcu_store()` and friends, so unqualified calls from that namespace reach
 * the shim whether or not ext-apcu is installed, loaded, or CLI-enabled.
 *
 * The consequence for a *shared* helper is that a probe cannot answer on behalf of a caller in a
 * different namespace. `Utilities` is in `LiturgicalCalendar\Api`; `MissalMetadataMap` is in
 * `LiturgicalCalendar\Api\Models\MissalsPath`, where the identical unqualified `apcu_store()` resolves
 * to a different function. Before #836 the map decided usability from one set of functions and wrote
 * through another — a divergence invisible in production, where only one set exists, and precisely
 * the thing the test shim is meant to be able to reproduce.
 *
 * Two ways out were available. The helper could take the caller's `__NAMESPACE__` as an argument and
 * dispatch through variable function names — which spreads the resolution rule across every call site
 * and makes it something each new caller has to remember. Or every `apcu_*` call in the request path
 * could be moved into one namespace, so that "where the decision is made" and "where the writes
 * happen" are the same place by construction. This class is the second: it is the only code outside
 * `Health` that calls `apcu_*`, it sits in the namespace the shim already covers, and callers in any
 * namespace get an answer that is true of the calls that will actually be made — because those calls
 * are made here.
 *
 * ## Which entry points reach this, and under which SAPI
 *
 * #836 asked for this to be established rather than assumed, because `apc.enable_cli` is what makes
 * the difference between a cache and a hole in the ground.
 *
 * - **php-fpm** (`public/index.php` in production) — APCu live. Every `Utilities::jsonFileTo*()` /
 *   `jsonUrlToObject()` caller is here: `CalendarHandler`, `EventsHandler`, `MissalsHandler`,
 *   `DecreesHandler`, `RegionalDataHandler`, `TemporaleHandler`, `CalendarMetadataProvider`,
 *   `CalendarParams`, `LocaleConfigurator`, `AmbrosianSanctoraleLoader`.
 * - **cli-server** (`composer start`, i.e. `php -S`) — APCu **live**, which is the opposite of what
 *   the issue suspected. APCu disables itself by comparing `sapi_module.name` against the literal
 *   `"cli"`; the built-in server reports `"cli-server"`, so `apc.enable_cli=0` does not touch it.
 *   Measured on the project image: under `php -S` with `apc.enable_cli=0`, `apcu_enabled()` is true
 *   and a store/fetch round trip succeeds. The dev server gets a real cache, not an inert one.
 * - **cli** — the WebSocket server (`public/LitCalTestServer.php`) runs here, but reaches calendar
 *   data only by issuing HTTP requests to the API, which execute in one of the two SAPIs above; it
 *   calls none of these helpers itself, and `Health` carries its own probe (#835). That leaves
 *   PHPUnit as the one routine CLI executor, via the in-process handler tests.
 *
 * So the six predicates this class replaces were latent rather than live — but latent only for as
 * long as that list holds, and nothing was asserting it. The round trip makes the question
 * self-answering wherever the code runs, which is the point.
 *
 * ## Memoisation
 *
 * The answer is cached for the life of the process. `Health` probes on connection open and on Redis
 * failure — rare events; these callers run per JSON file load, many times per request, and an extra
 * store/fetch/delete on each would be a real cost on the hot path of every handler. APCu's usability
 * does not change under a process: `apc.enable_cli` and `apc.enabled` are startup settings, and a
 * segment that fills later degrades safely on its own (`store()` returns false, the next `fetch()`
 * misses, and callers already treat a miss as "read from disk").
 *
 * Tests flip the answer by resetting `self::$usable` to null via reflection — see
 * `phpunit_tests/ApcuCacheDetectionTest.php`. Deliberately not a public reset method: nothing in the
 * request path has any business un-deciding this.
 *
 * ## One trap worth knowing, for tests only
 *
 * PHP's `INIT_NS_FCALL_BY_NAME` remembers, in each opline's run-time cache, which function the
 * namespace-then-global lookup resolved to. So the *first execution* of an unqualified `apcu_*` call
 * below binds that call site for the life of the process: if it runs while ext-apcu is loaded and the
 * shim is not, a shim required afterwards is ignored at precisely the sites it exists to stand in
 * for. Production never notices — there is only ever one candidate there — but anything that wants to
 * pin this answer before the tests start (`phpunit_tests/bootstrap.php` does) must do so *without*
 * calling through this class.
 */
final class ApcuCache
{
    /**
     * Every `apcu_*` function this class calls. An undefined function is a fatal, not a miss, so the
     * probe must not proceed to a round trip until all of them are known to resolve.
     *
     * `extension_loaded('apcu')` is deliberately absent, and not merely because it is subsumed: it
     * asks about the extension, while what is called is whatever the unqualified name resolves to.
     * An unloaded extension defines no functions, so the callability check below already stands down;
     * a loaded one proves nothing the round trip does not prove better.
     *
     * @var list<string>
     */
    private const array REQUIRED_FUNCTIONS = ['apcu_exists', 'apcu_fetch', 'apcu_store', 'apcu_delete'];

    private const string PROBE_KEY_PREFIX = 'litcal_apcu_probe_';

    /**
     * The memoised answer, or null before it has been established.
     *
     * Reset via reflection by tests; see the class docblock.
     */
    private static ?bool $usable = null;

    /**
     * Whether the APCu that this file's unqualified `apcu_*` calls actually reach can hold a value —
     * established by storing a probe key and reading it back, not by asking whether an extension
     * happens to be present.
     *
     * **Never throws.** Every caller reads the answer as a yes/no about whether to use a cache, and
     * these callers sit on the HTTP request path: an exception escaping here would turn a degraded
     * cache into a failed `/calendar`, `/events` or `/missals` response.
     */
    public static function isUsable(): bool
    {
        return self::$usable ??= self::probe();
    }

    /**
     * Whether an unqualified call to $function from this namespace will resolve to something.
     *
     * This namespace before the global one, because that is the order PHP itself resolves the calls
     * below in. Checking only the global name would describe functions this class may never call.
     */
    private static function callable(string $function): bool
    {
        return function_exists(__NAMESPACE__ . '\\' . $function) || function_exists($function);
    }

    /**
     * The store→fetch round trip, run once per process.
     */
    private static function probe(): bool
    {
        foreach (self::REQUIRED_FUNCTIONS as $function) {
            if (false === self::callable($function)) {
                return false;
            }
        }

        $probeKey = null;

        try {
            // Random per call: a fixed key could collide with a concurrent process probing the same
            // shared segment, and a probe must never answer using another writer's value. Generated
            // inside the `try` because `random_bytes()` can itself throw, and a caller of this method
            // is owed a bool rather than an exception.
            $probeKey   = self::PROBE_KEY_PREFIX . bin2hex(random_bytes(8));
            $probeValue = 'probe';

            if (true !== apcu_store($probeKey, $probeValue, 10)) {
                return false;
            }

            // The store's own return value is not trusted on its own — reporting success while
            // storing nothing is the exact failure being ruled out here.
            $success = false;
            $fetched = apcu_fetch($probeKey, $success);

            return true === $success && $fetched === $probeValue;
        } catch (\Throwable $e) {
            // Any failure whatsoever means "do not use this backend". Reported rather than swallowed —
            // an exception reaching here is by definition one nobody anticipated — but through
            // `error_log()` and never `echo`, unlike `Health::apcuUsable()`: `Health` is a CLI process
            // whose stdout is a log, whereas anything written here would be prepended to a PSR-7
            // response body.
            error_log('APCu probe failed, caching disabled for this process: ' . $e::class . ': ' . $e->getMessage());

            return false;
        } finally {
            // Best effort, and deliberately not allowed to change the answer: an exception raised in a
            // `finally` *replaces* whatever the `try` or `catch` was returning, so an unlucky cleanup
            // could otherwise mask an answer that was already correctly decided.
            if (null !== $probeKey) {
                try {
                    apcu_delete($probeKey);
                } catch (\Throwable) {
                    // Nothing to do: the answer is settled and the probe key carries a TTL.
                }
            }
        }
    }

    /**
     * Whether $key is currently held. False whenever the backend is unusable — there is nothing to
     * find in a cache that cannot hold anything.
     */
    public static function exists(string $key): bool
    {
        return self::isUsable() && true === apcu_exists($key);
    }

    /**
     * Read $key, reporting through $success whether anything was found.
     *
     * An unusable backend is reported as a miss rather than as an error: callers of a cache read
     * already have a path for "not cached", and it is the correct one here.
     *
     * @param bool|null $success Set to true only when a stored value was returned.
     * @param-out bool $success
     */
    public static function fetch(string $key, ?bool &$success = null): mixed
    {
        if (false === self::isUsable()) {
            $success = false;
            return false;
        }

        // `apcu_fetch()` declares its out-parameter as `mixed`; narrow it here rather than passing
        // `$success` straight in, so callers get the strict bool this method's signature promises.
        $found   = false;
        $value   = apcu_fetch($key, $found);
        $success = true === $found;

        return $value;
    }

    /**
     * Write $key, returning whether the backend accepted it.
     *
     * @param int $ttl Seconds; 0 means no expiry.
     */
    public static function store(string $key, mixed $value, int $ttl = 0): bool
    {
        return self::isUsable() && true === apcu_store($key, $value, $ttl);
    }

    /**
     * Remove $key.
     *
     * Gated on callability alone rather than on {@see self::isUsable()}, and the asymmetry is
     * deliberate. A store that cannot happen costs a cache hit; a delete that does not happen leaves
     * stale data being served, so the weaker precondition is the safer one. Against an inert backend
     * the call is a harmless no-op, which is all "unusable" would have bought.
     */
    public static function delete(string $key): bool
    {
        if (false === self::callable('apcu_delete')) {
            return false;
        }

        try {
            return true === apcu_delete($key);
        } catch (\Throwable $e) {
            error_log('APCu delete failed for key ' . $key . ': ' . $e::class . ': ' . $e->getMessage());

            return false;
        }
    }
}
