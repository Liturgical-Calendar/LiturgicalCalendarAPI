<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Support;

/**
 * Per-test env-variable isolation helper.
 *
 * `phpunit_tests/bootstrap.php` loads `.env.local` into `$_ENV` via
 * `Dotenv::createMutable(...)->safeLoad()`. On a developer machine running the
 * docker-compose stack, `.env.local` typically holds real ZITADEL_* and
 * OPENFGA_* credentials, which makes service `isConfigured()` gates return
 * `true` during tests — leaking the dev environment into assertions that want
 * to prove "service-X-is-not-configured" behavior.
 *
 * The trait exposes {@see withoutEnv()}: run a closure with the named env
 * vars cleared from both `$_ENV` and the process env table, then restore the
 * originals even if the closure throws. Per-test scope (not setUp/tearDown)
 * because most tests in the affected classes legitimately rely on the
 * configured state — only the "not-configured" assertion paths need it cleared.
 *
 * Closes #619, #620.
 */
trait EnvIsolationTrait
{
    /**
     * Zitadel env vars consulted by `ZitadelService::isConfigured()`.
     *
     * @var list<string>
     */
    private const ZITADEL_ENV_VARS = [
        'ZITADEL_ISSUER',
        'ZITADEL_PROJECT_ID',
        'ZITADEL_MACHINE_TOKEN',
        'ZITADEL_INTERNAL_URL',
    ];

    /**
     * OpenFGA env vars consulted by `OpenFgaClient::isConfigured()`.
     *
     * @var list<string>
     */
    private const OPENFGA_ENV_VARS = [
        'OPENFGA_API_URL',
        'OPENFGA_STORE_ID',
        'OPENFGA_MODEL_ID',
        'OPENFGA_API_TOKEN',
    ];

    /**
     * Sentinel that survives string-typed restoration; distinguishes
     * "originally absent" from "originally set to empty string".
     */
    private const UNSET_SENTINEL = "\0__env_isolation_unset__\0";

    /**
     * Run `$fn` with `$vars` cleared from `$_ENV` and the process env table,
     * restoring originals on exit — even when `$fn` throws.
     *
     * @template T
     * @param list<string>  $vars Env-var names to clear for the duration.
     * @param callable(): T $fn   The closure to run under the cleared env.
     * @return T The return value of `$fn`.
     */
    protected function withoutEnv(array $vars, callable $fn): mixed
    {
        $saved = [];
        foreach ($vars as $k) {
            $saved[$k] = array_key_exists($k, $_ENV) ? $_ENV[$k] : self::UNSET_SENTINEL;
            unset($_ENV[$k]);
            putenv($k);
        }
        try {
            return $fn();
        } finally {
            foreach ($saved as $k => $v) {
                if ($v === self::UNSET_SENTINEL) {
                    unset($_ENV[$k]);
                    putenv($k);
                } else {
                    $_ENV[$k] = $v;
                    putenv($k . '=' . ( is_scalar($v) ? (string) $v : '' ));
                }
            }
        }
    }
}
