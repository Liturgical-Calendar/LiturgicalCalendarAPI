<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use Psr\Log\LoggerInterface;

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
 * | Variable                | Meaning                                                           |
 * | ----------------------- | ----------------------------------------------------------------- |
 * | `REDIS_SOCKET`          | UNIX socket path. Takes precedence over host/port when non-empty. |
 * | `REDIS_HOST`            | Hostname or IP; may carry a `tls://` or `ssl://` scheme prefix.   |
 * | `REDIS_PORT`            | TCP port, default 6379.                                           |
 * | `REDIS_PASSWORD`        | Optional `AUTH` credential.                                       |
 * | `REDIS_TLS`             | `true` to wrap the TCP connection in TLS without a scheme prefix. |
 * | `REDIS_TLS_CA_FILE`     | Optional CA bundle used to verify the server certificate.         |
 * | `REDIS_TLS_VERIFY_PEER` | `false` to disable peer verification. Development only.           |
 *
 * ## The password-over-plain-TCP warning
 *
 * A `REDIS_PASSWORD` sent to anything that is not a UNIX socket, a loopback address or a TLS
 * endpoint crosses the network in cleartext, and so does every command after it. Reaching that
 * combination logs a warning once per process — see {@see self::warnIfPasswordTravelsInClear()}.
 *
 * It warns; it does NOT refuse. Failing closed was considered in #919 and rejected: it would
 * break, on upgrade, every deployment already running that way.
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

    /** Scheme prefixes on `REDIS_HOST` that mean "wrap this connection in TLS". */
    private const TLS_SCHEMES = ['tls://', 'ssl://'];

    /**
     * Has the "password over unencrypted TCP" warning already been emitted in this process?
     *
     * Per-process is the only "once" a shared-nothing PHP application can honestly offer: under
     * FPM it is once per worker, for that worker's lifetime; under CLI once per run. It is
     * deliberately not per connection — the eleven sites connect lazily on many different request
     * paths, and warning at each would bury the message in its own repetition.
     */
    private static bool $plainTcpPasswordWarned = false;

    /**
     * @param string $socket        `REDIS_SOCKET`, or '' when not configured.
     * @param string $host          `REDIS_HOST` with any `tls://`/`ssl://` prefix stripped, or ''.
     * @param int    $port          `REDIS_PORT`, or {@see self::DEFAULT_PORT}.
     * @param string $password      `REDIS_PASSWORD`, or '' when not configured.
     * @param bool   $tls           Whether the TCP connection should be wrapped in TLS.
     * @param string $tlsCaFile     `REDIS_TLS_CA_FILE`, or '' when not configured.
     * @param bool   $tlsVerifyPeer Whether the server certificate must verify. Default true.
     */
    private function __construct(
        public readonly string $socket,
        public readonly string $host,
        public readonly int $port,
        public readonly string $password,
        public readonly bool $tls,
        public readonly string $tlsCaFile,
        public readonly bool $tlsVerifyPeer,
    ) {
    }

    /**
     * Build the connection description from the environment.
     *
     * A `tls://` or `ssl://` prefix on `REDIS_HOST` is stripped into {@see self::$tls} rather
     * than left on the host, so that loopback detection — and therefore
     * {@see self::isTransportSecure()} and `describe()` — still recognise `tls://127.0.0.1`. The
     * prefix is put back by {@see self::target()} at connect time.
     */
    public static function fromEnv(): self
    {
        $host = self::envString('REDIS_HOST');
        $tls  = false;

        foreach (self::TLS_SCHEMES as $scheme) {
            if (0 === stripos($host, $scheme)) {
                $tls  = true;
                $host = substr($host, strlen($scheme));
                break;
            }
        }

        if (false === $tls) {
            $tls = self::envBool('REDIS_TLS', false);
        }

        $port = self::envString('REDIS_PORT');

        return new self(
            self::envString('REDIS_SOCKET'),
            $host,
            is_numeric($port) ? (int) $port : self::DEFAULT_PORT,
            self::envString('REDIS_PASSWORD'),
            $tls,
            self::envString('REDIS_TLS_CA_FILE'),
            self::envBool('REDIS_TLS_VERIFY_PEER', true),
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
     * @param LoggerInterface|null $logger  Destination for the plain-TCP password warning;
     *                                      `error_log()` when null.
     * @param float|null           $timeout Connect timeout override, in seconds.
     */
    public static function bestEffort(?LoggerInterface $logger = null, ?float $timeout = null): ?\Redis
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
            if (false === $config->authenticate($redis, $logger)) {
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
     * @param LoggerInterface|null $logger  Destination for the plain-TCP password warning.
     * @param float|null           $timeout Connect timeout override, in seconds.
     *
     * @throws \RuntimeException When the connection or the authentication fails.
     * @throws \RedisException   Propagated unchanged from phpredis.
     */
    public static function openOrFail(?LoggerInterface $logger = null, ?float $timeout = null): \Redis
    {
        $config = self::fromEnv();
        $redis  = new \Redis();

        if (false === $config->connect($redis, $timeout)) {
            throw new \RuntimeException(sprintf('Could not connect to Redis at %s.', $config->describe()));
        }

        if (false === $config->authenticate($redis, $logger)) {
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

    /**
     * Would a password sent over this connection stay off the wire, or be encrypted on it?
     *
     * True for a UNIX socket (never leaves the host), for a loopback address (never leaves the
     * interface), and for TLS. False is exactly the combination #919 is about: a credential
     * crossing a network segment in cleartext, along with every command after it.
     */
    public function isTransportSecure(): bool
    {
        return $this->usesSocket() || $this->tls || $this->isLoopback();
    }

    /**
     * The first argument to `\Redis::connect()`: a socket path, or a host carrying its scheme.
     */
    public function target(): string
    {
        if ($this->usesSocket()) {
            return $this->socket;
        }

        return ( $this->tls ? 'tls://' : '' ) . $this->hostOrDefault();
    }

    /**
     * Human-readable endpoint, for logs and the health payload.
     *
     * Never includes the password. The two pre-#919 `Health` spellings —
     * `"socket: /path"` and `"host:port"` — are preserved verbatim so that the health output an
     * operator greps for does not change shape; a TLS endpoint additionally carries its scheme,
     * which is the whole point of reporting it.
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
     * The stream context is passed only when the TLS options actually require one, so the
     * ordinary — and overwhelmingly common — plain path issues exactly the same three-argument
     * call it issued before #919.
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

        $context = $this->streamContext();
        if ([] === $context) {
            return $redis->connect($this->target(), $this->port, $timeout);
        }

        // phpredis takes no named arguments, so the persistent-id, retry-interval and
        // read-timeout defaults have to be restated to reach the context argument. The values
        // below ARE phpredis' own defaults: null, 0, 0.
        return $redis->connect($this->target(), $this->port, $timeout, null, 0, 0, $context);
    }

    /**
     * Send `AUTH` when there is a password, warning first if it would cross the wire in the clear.
     *
     * Returns true when there is nothing to send, so callers can invoke it unconditionally.
     *
     * @param LoggerInterface|null $logger Destination for the warning; `error_log()` when null.
     *
     * @throws \RedisException Propagated unchanged from phpredis.
     */
    public function authenticate(\Redis $redis, ?LoggerInterface $logger = null): bool
    {
        if (false === $this->hasPassword()) {
            return true;
        }

        $this->warnIfPasswordTravelsInClear($logger);

        // phpredis returns bool from AUTH, but its fluent-mode build returns $this instead;
        // anything that is not an outright false counts as authenticated.
        return false !== $redis->auth($this->password);
    }

    /**
     * Emit the #919 warning at most once per process. A no-op when there is no password, or when
     * the transport already protects it.
     *
     * This warns; it does NOT refuse. Failing closed on the password-over-plain-TCP combination
     * was considered and rejected in #919: it would break, on upgrade, every deployment already
     * running that way. An operator who wants the credential protected has three documented ways
     * to do it, and the message names all three.
     */
    public function warnIfPasswordTravelsInClear(?LoggerInterface $logger = null): void
    {
        if (false === $this->hasPassword() || $this->isTransportSecure() || self::$plainTcpPasswordWarned) {
            return;
        }

        self::$plainTcpPasswordWarned = true;

        $message = sprintf(
            'REDIS_PASSWORD is being sent to %s over an unencrypted TCP connection: the credential, '
            . 'and every command after it, cross the network in cleartext. Reach Redis over a UNIX '
            . 'socket (REDIS_SOCKET), keep it on loopback, or enable TLS (REDIS_TLS=true, or a '
            . 'tls:// prefix on REDIS_HOST). See .env.example for the full set of options.',
            $this->describe()
        );

        if ($logger instanceof LoggerInterface) {
            $logger->warning($message);

            return;
        }

        error_log($message);
    }

    /**
     * Forget that the warning was emitted.
     *
     * @internal Exists for the test suite, which has to observe the first emission more than once
     *           within a single process.
     */
    public static function resetPlainTcpWarningState(): void
    {
        self::$plainTcpPasswordWarned = false;
    }

    /** `REDIS_HOST`, or the loopback default when it is unset. */
    private function hostOrDefault(): string
    {
        return '' !== $this->host ? $this->host : self::DEFAULT_HOST;
    }

    /**
     * Does the configured host resolve, literally, to this machine's loopback interface?
     *
     * Literal addresses and `localhost` only. A hostname that happens to carry a loopback A
     * record cannot be recognised without resolving it, and a DNS lookup on a best-effort
     * connect path is not worth the latency. Treating an unrecognised host as non-loopback is
     * the safe direction: it warns rather than staying silent.
     */
    private function isLoopback(): bool
    {
        $host = strtolower(trim($this->hostOrDefault(), '[]'));

        if ('localhost' === $host) {
            return true;
        }

        $packed = @inet_pton($host);
        if (false === $packed) {
            return false;
        }

        // ::1 in any spelling, the fully expanded 0:0:0:0:0:0:0:1 included.
        if ($packed === inet_pton('::1')) {
            return true;
        }

        // The whole 127.0.0.0/8 block, not just 127.0.0.1.
        return 4 === strlen($packed) && "\x7f" === $packed[0];
    }

    /**
     * The `$context` argument for `\Redis::connect()`, or `[]` when phpredis' defaults will do.
     *
     * Peer verification is left to phpredis (which verifies) unless `REDIS_TLS_VERIFY_PEER` is
     * explicitly falsy, so the context appears only when an operator has asked for something the
     * defaults do not give them.
     *
     * @return array{stream?: array<string, bool|string>} Shaped to phpredis' own `$context`
     *                                                      parameter, whose only other key
     *                                                      (`auth`) this helper does not use.
     */
    private function streamContext(): array
    {
        if (false === $this->tls) {
            return [];
        }

        $stream = [];

        if ('' !== $this->tlsCaFile) {
            $stream['cafile'] = $this->tlsCaFile;
        }

        if (false === $this->tlsVerifyPeer) {
            $stream['verify_peer']      = false;
            $stream['verify_peer_name'] = false;
        }

        return [] === $stream ? [] : ['stream' => $stream];
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

    /**
     * Read a boolean environment variable, falling back to `$default` when it is set in neither
     * layer.
     *
     * Anything other than a recognised affirmative reads as false, so a typo disables a flag
     * rather than silently enabling it — the safe direction for `REDIS_TLS_VERIFY_PEER`, and the
     * loud one for `REDIS_TLS` (a mistyped `REDIS_TLS` leaves the warning firing rather than
     * pretending the connection is protected).
     */
    private static function envBool(string $name, bool $default): bool
    {
        $raw = strtolower(self::envString($name));

        if ('' === $raw) {
            return $default;
        }

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }
}
