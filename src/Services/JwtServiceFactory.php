<?php

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Environment;

/**
 * Factory for creating JwtService instances from environment variables.
 *
 * This factory centralizes JwtService configuration and prevents configuration
 * drift across different parts of the application (middleware, handlers, etc.).
 */
class JwtServiceFactory
{
    private const SUPPORTED_ALGORITHMS = ['HS256', 'HS384', 'HS512'];

    /**
     * Common placeholder patterns that indicate an insecure default secret.
     * These patterns are checked case-insensitively.
     */
    private const PLACEHOLDER_PATTERNS = [
        'change-this',
        'change_this',
        'changethis',
        'change-me',
        'change_me',
        'changeme',
        'replace-this',
        'replace_this',
        'replacethis',
        'replace-me',
        'replace_me',
        'replaceme',
        'your-secret',
        'your_secret',
        'yoursecret',
        'my-secret',
        'my_secret',
        'mysecret',
        'secret-key',
        'secret_key',
        'secretkey',
        'example',
        'placeholder',
        'default',
        'insecure',
        'xxxxxxxx',
        'password',
        'test-secret',
        'test_secret',
        'testsecret',
        'dev-secret',
        'dev_secret',
        'devsecret',
        'jwt',
        'dummy',
        'sample',
    ];

    /**
     * Read one configuration value from the environment, from either place it can live.
     *
     * **`getenv()` first, and `$_ENV` is not enough on its own.** Reading only `$_ENV` works under
     * the web SAPIs and fails under CLI, which is how the WebSocket server ended up unable to
     * authenticate anybody while the HTTP API — same checkout, same `.env.local`, same Dotenv call —
     * was signing tokens perfectly well. The mechanism:
     *
     *  - `variables_order` is `GPCS` in the shipped ini files, so **no** `E`: an inherited environment
     *    variable never reaches `$_ENV` by itself.
     *  - The CLI SAPI nevertheless merges the whole environment into **`$_SERVER`**, where the web
     *    SAPIs rebuild `$_SERVER` per request from HTTP variables instead.
     *  - `Dotenv::createImmutable()` refuses to overwrite a variable it can already see, and it sees
     *    `$_SERVER`. So under CLI it declines to write the value from `.env.local` into `$_ENV`,
     *    which is then empty, while under the web SAPI it writes it happily.
     *
     * The net effect is that a CLI entrypoint whose `JWT_SECRET` comes from the *environment* —
     * systemd `Environment=`, `docker run -e`, a compose `environment:` block, a parent process such
     * as Playwright — reads no secret at all and throws. Measured, with the value present in
     * `getenv()` throughout: `$_ENV[JWT_SECRET]` ABSENT and
     * `RuntimeException: JWT_SECRET environment variable is required`.
     *
     * `getenv()` takes precedence deliberately, matching both Dotenv's own immutable semantics — a
     * pre-existing environment variable wins over a file — and the existing convention in
     * {@see \LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware} and {@see WsCallerResolver}.
     *
     * Sibling sites read `$_ENV` alone in CLI contexts and have the same exposure, left alone here
     * rather than swept in: `Health`'s `REDIS_*` lookups and `bin/LitCalTestServer.php`'s `WS_HOST` /
     * `WS_PORT`. Neither is a credential, and both fail visibly rather than silently.
     */
    private static function env(string $name): ?string
    {
        $fromGetenv = getenv($name);
        if (is_string($fromGetenv) && '' !== $fromGetenv) {
            return $fromGetenv;
        }

        $fromEnv = $_ENV[$name] ?? null;

        return is_string($fromEnv) && '' !== $fromEnv ? $fromEnv : null;
    }

    /**
     * A token lifetime in seconds, read from the environment.
     *
     * @param string $name    The variable to read.
     * @param int    $default Used when the variable is absent or empty.
     * @throws \RuntimeException If the value is not numeric, or is not greater than zero.
     */
    private static function positiveSeconds(string $name, int $default): int
    {
        $raw = self::env($name);
        if ($raw === null) {
            return $default;
        }

        if (!is_numeric($raw)) {
            throw new \RuntimeException($name . ' must be a numeric value (got: ' . $raw . ')');
        }

        $seconds = (int) $raw;
        if ($seconds <= 0) {
            throw new \RuntimeException($name . ' must be a positive integer (got: ' . $seconds . ')');
        }

        return $seconds;
    }

    /**
     * Check if a secret appears to be a placeholder value.
     *
     * @param string $secret The secret to check.
     * @return bool True if the secret matches a placeholder pattern.
     */
    private static function isPlaceholderSecret(string $secret): bool
    {
        $lowercaseSecret = strtolower($secret);

        foreach (self::PLACEHOLDER_PATTERNS as $pattern) {
            if (str_contains($lowercaseSecret, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a JwtService configured from environment variables.
     *
     * Reads these environment variables:
     * - JWT_SECRET (required): signing secret, must be at least 32 characters.
     * - JWT_ALGORITHM: algorithm name (HS256, HS384, or HS512), defaults to 'HS256'.
     * - JWT_EXPIRY: access token lifetime in seconds, defaults to 3600; must be greater than 0.
     * - JWT_REFRESH_EXPIRY: refresh token lifetime in seconds, defaults to 604800; must be greater than 0.
     *
     * In staging/production environments, throws an exception if the JWT_SECRET appears
     * to be a placeholder value (e.g., contains 'change-this', 'your-secret', etc.).
     *
     * @return JwtService The configured JWT service instance.
     * @throws \RuntimeException If JWT_SECRET is missing/empty/too short/placeholder, JWT_ALGORITHM is invalid, or expiry values are not positive integers.
     */
    public static function fromEnv(): JwtService
    {
        // `env()` returns null for both "absent" and "present but empty", which is the same answer
        // here: there is no secret to sign with.
        $secret = self::env('JWT_SECRET');
        if ($secret === null) {
            throw new \RuntimeException('JWT_SECRET environment variable is required and must be a non-empty string');
        }
        if (strlen($secret) < 32) {
            throw new \RuntimeException('JWT_SECRET must be at least 32 characters long');
        }

        // In production environments, reject placeholder secrets
        if (Environment::isProduction() && self::isPlaceholderSecret($secret)) {
            throw new \RuntimeException(
                'JWT_SECRET appears to be a placeholder value. ' .
                'In staging/production environments, you must use a secure random secret. ' .
                'Generate one with: php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        $algorithm = self::env('JWT_ALGORITHM') ?? 'HS256';
        if (!in_array($algorithm, self::SUPPORTED_ALGORITHMS, true)) {
            throw new \RuntimeException('JWT_ALGORITHM must be one of: ' . implode(', ', self::SUPPORTED_ALGORITHMS));
        }

        // Both lifetimes are read the same way. The `get_debug_type()` arm the two blocks used to
        // carry is gone with the `mixed` they used to receive: an environment variable is a string or
        // it is absent, and `env()` now says which.
        $expiry        = self::positiveSeconds('JWT_EXPIRY', 3600);
        $refreshExpiry = self::positiveSeconds('JWT_REFRESH_EXPIRY', 604800);

        return new JwtService($secret, $algorithm, $expiry, $refreshExpiry);
    }
}
