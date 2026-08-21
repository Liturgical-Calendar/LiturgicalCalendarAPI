<?php

declare(strict_types=1);

// A minimal in-memory stand-in for the four APCu functions `Health` calls (apcu_store/apcu_fetch/
// apcu_exists/apcu_delete/apcu_sma_info), active only when the real ext-apcu is not loaded — guarded
// per function below, and never redeclared when the real extension is present (as in this project's
// Docker image), where it runs untouched.
//
// This exists so a test can drive `Health::cachedGet()`'s actual cache-hit branch — including the
// malformed/legacy-entry fall-through #834 fixed — through the real code path, instead of
// re-implementing that branch's logic in the test and only reasoning about whether the real one
// matches it. Deliberately not a mock of `Health`'s cache methods: those stay untouched, and only the
// backend three layers below them is stood in for.
//
// Declared in the global namespace on purpose: `Health.php` is in `LiturgicalCalendar\Api` and calls
// these functions unqualified, so PHP resolves them against the global namespace exactly as it would
// the real extension's functions.
if (!function_exists('apcu_store')) {
    function apcu_store(string $key, mixed $value, int $ttl = 0): bool
    {
        $GLOBALS['__apcuShimStore']     ??= [];
        $GLOBALS['__apcuShimStore'][$key] = [
            'value'   => $value,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
        ];
        return true;
    }

    function apcu_fetch(string $key, ?bool &$success = null): mixed
    {
        $GLOBALS['__apcuShimStore'] ??= [];
        $entry                        = $GLOBALS['__apcuShimStore'][$key] ?? null;
        if (null === $entry || ( $entry['expires'] > 0 && $entry['expires'] < time() )) {
            $success = false;
            return false;
        }
        $success = true;
        return $entry['value'];
    }

    function apcu_exists(string $key): bool
    {
        $GLOBALS['__apcuShimStore'] ??= [];
        $entry                        = $GLOBALS['__apcuShimStore'][$key] ?? null;
        if (null === $entry) {
            return false;
        }
        if ($entry['expires'] > 0 && $entry['expires'] < time()) {
            unset($GLOBALS['__apcuShimStore'][$key]);
            return false;
        }
        return true;
    }

    function apcu_delete(string $key): bool
    {
        $GLOBALS['__apcuShimStore'] ??= [];
        $existed                      = isset($GLOBALS['__apcuShimStore'][$key]);
        unset($GLOBALS['__apcuShimStore'][$key]);
        return $existed;
    }

    /**
     * @return array{seg_size: int, avail_mem: int}
     */
    function apcu_sma_info(bool $limited = false): array
    {
        return ['seg_size' => 0, 'avail_mem' => 0];
    }
}
