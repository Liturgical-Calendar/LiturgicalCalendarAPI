<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/**
 * The single definition of "how this application reaches Redis".
 *
 * Before #919 the same block — read `REDIS_SOCKET`/`REDIS_HOST`/`REDIS_PORT`, prefer the socket,
 * `auth()` with `REDIS_PASSWORD`, `catch (\Throwable) → null` — was copy-pasted at eleven call
 * sites, and had already drifted: four of them passed a 2-second connect timeout and the other
 * seven passed none, so a correctness fix applied once had failed to reach the rest. Everything
 * about reaching Redis now lives here, and the eleven sites express only what they do differently.
 *
 * ## Configuration
 *
 * | Variable         | Meaning                                                           |
 * | ---------------- | ----------------------------------------------------------------- |
 * | `REDIS_SOCKET`   | UNIX socket path. Takes precedence over host/port when non-empty.  |
 * | `REDIS_HOST`     | Hostname or IP.                                                    |
 * | `REDIS_PORT`     | TCP port, default 6379.                                            |
 * | `REDIS_PASSWORD` | Optional `AUTH` credential.                                        |
 *
 * ## Redis is an accelerator, never a dependency
 *
 * {@see self::bestEffort()} returns `null` — never throws — when `ext-redis` is missing, when
 * neither `REDIS_SOCKET` nor `REDIS_HOST` is configured (the ordinary state for a self-hoster:
 * both are commented out in `.env.example`), or when the connection or authentication fails.
 * Every caller of it degrades to a Postgres-plus-cron or disk path. Only the long-lived consumer
 * entry points, whose entire reason to exist is the stream, use {@see self::openOrFail()}.
 *
 * @package LiturgicalCalendar\Api\Services
 */
final class RedisConnection
{
    /** Host used when neither `REDIS_SOCKET` nor `REDIS_HOST` names one. */
    public const DEFAULT_HOST = '127.0.0.1';

    /** Port used when `REDIS_PORT` is unset or non-numeric. */
    public const DEFAULT_PORT = 6379;

    /**
     * Connect timeout, in seconds, applied at every site.
     *
     * Reconciles the pre-#919 split: `Health` (both sites), `WritesSourceData`,
     * `ChangeRequestAdminHandler`, `SourceDataPublisherFactory` and
     * `bin/publish-sourcedata-consumer` passed 2.0; the remaining five passed nothing and so
     * inherited phpredis' `default_socket_timeout` (60s on a stock php.ini). The bounded value
     * wins because most of those five sit on a request-handling path where a Redis host that
     * blackholes SYNs would otherwise stall the whole response for a minute to send a
     * best-effort `XADD` that the cron backstop re-sends anyway.
     *
     * This is the CONNECT timeout only. phpredis keeps the read timeout as a separate argument,
     * left at its 0 (unlimited) default here, so a blocking `XREADGROUP` in the consumer loop is
     * unaffected.
     */
    public const CONNECT_TIMEOUT_SECONDS = 2.0;

    /**
     * @param string $socket   `REDIS_SOCKET`, or '' when not configured.
     * @param string $host     `REDIS_HOST`, or '' when not configured.
     * @param int    $port     `REDIS_PORT`, or {@see self::DEFAULT_PORT}.
     * @param string $password `REDIS_PASSWORD`, or '' when not configured.
     */
    private function __construct(
        public readonly string $socket,
        public readonly string $host,
        public readonly int $port,
        public readonly string $password,
    ) {
    }

    /** Build the connection description from the environment. */
    public static function fromEnv(): self
    {
        $port = self::envString('REDIS_PORT');

        return new self(
            self::envString('REDIS_SOCKET'),
            self::envString('REDIS_HOST'),
            is_numeric($port) ? (int) $port : self::DEFAULT_PORT,
            self::envString('REDIS_PASSWORD'),
        );
    }

    /**
     * Best-effort connection: a live `\Redis`, or `null` for every reason it could not be one.
     *
     * This is what the nine notifier/cache call sites want. `null` is not an error condition —
     * see the class docblock — so nothing here throws, including a `\Throwable` raised inside
     * phpredis itself.
     *
     * Unlike the pre-#919 copies, a `connect()` that returns `false` and an `auth()` that returns
     * `false` both yield `null` rather than a `\Redis` that is not actually usable. The callers
     * all treat a null `\Redis` as "no acceleration available", which is the honest answer in
     * both cases.
     *
     * @param float|null $timeout Connect timeout override, in seconds.
     */
    public static function bestEffort(?float $timeout = null): ?\Redis
    {
        $config = self::fromEnv();

        if (!extension_loaded('redis') || !$config->isConfigured()) {
            return null;
        }

        try {
            $redis = new \Redis();
            if (false === $config->connect($redis, $timeout)) {
                return null;
            }
            if (false === $config->authenticate($redis)) {
                return null;
            }

            return $redis;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Connect or throw — for the entry points whose entire reason to exist is the stream.
     *
     * `ext-redis` being absent is the caller's guard, not this method's: both consumers exit 2
     * on that, distinctly from the exit 1 they use for a configuration error, so an operator can
     * tell "not installed" from "installed but misconfigured".
     *
     * @param float|null $timeout Connect timeout override, in seconds.
     *
     * @throws \RuntimeException When the connection or the authentication fails.
     * @throws \RedisException   Propagated unchanged from phpredis.
     */
    public static function openOrFail(?float $timeout = null): \Redis
    {
        $config = self::fromEnv();
        $redis  = new \Redis();

        if (false === $config->connect($redis, $timeout)) {
            throw new \RuntimeException(sprintf('Could not connect to Redis at %s.', $config->describe()));
        }

        if (false === $config->authenticate($redis)) {
            throw new \RuntimeException(sprintf('Redis authentication failed for %s.', $config->describe()));
        }

        return $redis;
    }

    /**
     * Is Redis configured at all?
     *
     * False for a self-hoster who has commented neither variable back in, which is the ordinary
     * case. Deliberately NOT consulted by {@see self::connect()}: `Health` connects to the
     * loopback default even with nothing configured, which is the documented behaviour in
     * `.env.example` ("If not configured, defaults to TCP 127.0.0.1:6379") and must not regress.
     */
    public function isConfigured(): bool
    {
        return '' !== $this->socket || '' !== $this->host;
    }

    /** Will this connection go over a UNIX socket rather than TCP? */
    public function usesSocket(): bool
    {
        return '' !== $this->socket;
    }

    /** Is there a `REDIS_PASSWORD` to send? */
    public function hasPassword(): bool
    {
        return '' !== $this->password;
    }

    /** The first argument to `\Redis::connect()`: a socket path, or a host. */
    public function target(): string
    {
        return $this->usesSocket() ? $this->socket : $this->hostOrDefault();
    }

    /**
     * Human-readable endpoint, for logs and the health payload.
     *
     * Never includes the password. The two pre-#919 `Health` spellings —
     * `"socket: /path"` and `"host:port"` — are preserved verbatim so that the health output an
     * operator greps for does not change shape.
     */
    public function describe(): string
    {
        if ($this->usesSocket()) {
            return "socket: {$this->socket}";
        }

        return $this->target() . ':' . $this->port;
    }

    /**
     * Perform the connect, socket before host, and report phpredis' own boolean.
     *
     * @param float|null $timeout Connect timeout override; {@see self::CONNECT_TIMEOUT_SECONDS}
     *                            when null.
     *
     * @throws \RedisException Propagated unchanged from phpredis.
     */
    public function connect(\Redis $redis, ?float $timeout = null): bool
    {
        $timeout ??= self::CONNECT_TIMEOUT_SECONDS;

        if ($this->usesSocket()) {
            return $redis->connect($this->socket, 0, $timeout);
        }

        return $redis->connect($this->target(), $this->port, $timeout);
    }

    /**
     * Send `AUTH` when there is a password.
     *
     * Returns true when there is nothing to send, so callers can invoke it unconditionally.
     *
     * @throws \RedisException Propagated unchanged from phpredis.
     */
    public function authenticate(\Redis $redis): bool
    {
        if (false === $this->hasPassword()) {
            return true;
        }

        // phpredis returns bool from AUTH, but the fluent-mode build returns $this instead;
        // anything that is not an outright false counts as authenticated.
        return false !== $redis->auth($this->password);
    }

    /** `REDIS_HOST`, or the loopback default when it is unset. */
    private function hostOrDefault(): string
    {
        return '' !== $this->host ? $this->host : self::DEFAULT_HOST;
    }

    /**
     * Read an environment variable from BOTH layers: `$_ENV` first, then `getenv()`.
     *
     * The two layers are not interchangeable. Dotenv populates `$_ENV` from the `.env*` FILES,
     * but PHP CLI commonly runs with `variables_order` excluding `E`, so a variable exported by
     * the shell or set by a systemd `Environment=`/`EnvironmentFile=` directive reaches
     * `getenv()` and NEVER `$_ENV`. Reading only `$_ENV` — which nine of the eleven pre-#919
     * copies did — silently ignores the configuration mechanism the change-request runbook's own
     * systemd unit tells operators to use, and the process quietly falls back to 127.0.0.1
     * instead of the configured Redis.
     *
     * Duplicated in shape (not shared) with
     * {@see \LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisherFactory::envString()}
     * and {@see \LiturgicalCalendar\Api\Services\OpenFgaClient}'s private helper, following the
     * precedent those two set: a low-level connection helper should not depend on a GitHub
     * publishing factory just to read a string.
     *
     * @return string The trimmed value, or '' when set in neither layer (or empty in both).
     */
    private static function envString(string $name): string
    {
        $value = $_ENV[$name] ?? null;
        if (is_string($value) && '' !== trim($value)) {
            return trim($value);
        }

        $fromProcess = getenv($name);
        if (is_string($fromProcess) && '' !== trim($fromProcess)) {
            return trim($fromProcess);
        }

        return '';
    }
}
