<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api;

/**
 * Environment utility for reading application configuration and checking environment settings.
 *
 * Centralizes environment lookup and APP_ENV detection logic to maintain DRY principles
 * across CLI entrypoints, middleware, services, and other components.
 *
 * @package LiturgicalCalendar\Api
 */
class Environment
{
    public const DEFAULT_WS_HOST = '127.0.0.1';
    public const DEFAULT_WS_PORT = 8082;

    /**
     * Read a configuration value from the environment, checking `getenv()` before `$_ENV`.
     *
     * PHP CLI commonly runs with `variables_order = "GPCS"` (without `E`), so variables passed
     * via the process environment (shell, `docker run -e`, systemd `Environment=`) reach
     * `getenv()` and `$_SERVER` but never `$_ENV` — and `Dotenv::createImmutable()` declines to
     * overwrite keys already present in `$_SERVER`, leaving `$_ENV` unset for those variables.
     * Checking `getenv()` first matches Dotenv's immutable precedence (process environment wins
     * over `.env*` files) while still reading file-loaded values from `$_ENV`.
     *
     * Empty or whitespace-only values are treated as unset (`null`) in both layers.
     *
     * @return non-empty-string|null Trimmed non-empty value, or null when unset or blank.
     */
    public static function get(string $name): ?string
    {
        $fromProcess = getenv($name);
        if (is_string($fromProcess)) {
            $trimmed = trim($fromProcess);
            if ('' !== $trimmed) {
                return $trimmed;
            }
        }

        $fromEnv = $_ENV[$name] ?? null;
        if (is_string($fromEnv)) {
            $trimmed = trim($fromEnv);
            if ('' !== $trimmed) {
                return $trimmed;
            }
        }

        return null;
    }

    /**
     * Resolve WebSocket server bind settings and debug-mode flag from the environment.
     *
     * Reads `WS_HOST`, `WS_PORT`, and `APP_ENV` via {@see self::get()} so process-environment
     * overrides are honoured under CLI `variables_order=GPCS`.
     *
     * When `APP_ENV` is unset or anything other than `development` or `test`, `isDebugEnv` is
     * `false` (fail-safe: `LitCalTestServer` keeps `display_errors` off unless explicitly running
     * in `development` or `test`).
     *
     * @return array{host: non-empty-string, port: int<1, 65535>, isDebugEnv: bool}
     */
    public static function resolveWebSocketConfig(): array
    {
        $wsHost = self::get('WS_HOST') ?? self::DEFAULT_WS_HOST;
        $wsPort = filter_var(self::get('WS_PORT'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        $appEnv = self::get('APP_ENV');

        $resolvedPort = is_int($wsPort) && $wsPort >= 1 && $wsPort <= 65535
            ? $wsPort
            : self::DEFAULT_WS_PORT;

        return [
            'host'       => $wsHost,
            'port'       => $resolvedPort,
            'isDebugEnv' => null !== $appEnv && in_array(strtolower($appEnv), ['development', 'test'], true),
        ];
    }

    /**
     * Get the current environment name.
     *
     * Centralizes APP_ENV normalization (lowercase, trimmed) to avoid
     * subtle drift between environment check methods.
     *
     * @return string The environment name (lowercase, trimmed).
     */
    public static function getName(): string
    {
        $appEnv = self::get('APP_ENV') ?? 'development';

        return strtolower($appEnv);
    }

    /**
     * Check if the current environment is a production-like environment.
     *
     * @return bool True if APP_ENV is 'staging' or 'production'.
     */
    public static function isProduction(): bool
    {
        return in_array(self::getName(), ['staging', 'production'], true);
    }

    /**
     * Check if the current environment is a development environment.
     *
     * @return bool True if APP_ENV is 'development' or 'test'.
     */
    public static function isDevelopment(): bool
    {
        return in_array(self::getName(), ['development', 'test'], true);
    }
}
