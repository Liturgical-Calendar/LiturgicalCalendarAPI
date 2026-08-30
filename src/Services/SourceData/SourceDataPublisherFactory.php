<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use GuzzleHttp\Client as GuzzleClient;
use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\Repositories\AuditLogRepository;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\GitHub\GitHubAppAuth;
use LiturgicalCalendar\Api\Services\GitHub\GitHubGitDataClient;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\Outbox\OutboxProcessor;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeService;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeServiceInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * One wiring, behind three entry points: the cron publisher (`scripts/publish-sourcedata.php`),
 * the cron merge poller (`scripts/poll-sourcedata-merges.php`) and the Redis stream consumer
 * (`bin/publish-sourcedata-consumer`).
 *
 * # Why this exists
 *
 * Phase 2's most expensive defect was a script CONSTRUCTING what every test INJECTED. The cron
 * entry point took `LoggerFactory::create()`'s default processors, which attach a processor
 * ({@see \LiturgicalCalendar\Api\Http\Logs\RequestResponseProcessor}) that THROWS on any record
 * whose context lacks `type => request|response`. `PublishRunner` and `MergePollRunner` log batch
 * ids and exception classes — so with that default, every log call either runner made, INCLUDING
 * the ones inside their own catch blocks, would have thrown in production, before
 * `releaseClaim()` ever ran, stranding the batch. Every unit test passed; none of them crossed
 * the seam between a hand-built test logger and what the script actually asked `LoggerFactory`
 * for.
 *
 * Phase 3 adds two more entry points to that same wiring, tripling the surface for the same
 * mistake to recur on. Moving it here, into `src/`, is what brings it under `composer analyse`
 * (PHPStan level 10): `phpstan.neon.dist` scans `paths: [src]` only, so a script-level
 * `(string) $_ENV[...]` blind cast is invisible to CI, while the same read inside this class is
 * not.
 *
 * # The umask window is NOT this class's job
 *
 * {@see tokenCache()} builds the filesystem-backed cache the installation token is written into
 * and chmods its directory — but it does not touch the process umask. The token itself is
 * fetched LAZILY, the first time a built {@see GitHubGitDataClient} sends a request (inside
 * `runOnce()`, not inside anything in this class), so the restrictive umask that protects it has
 * to stay in effect for the whole run, not merely for construction. A factory method that set it
 * and returned would either leave it set long after the object graph it built has nothing left to
 * do (harmless for a one-shot script, but wrong for a factory called from a unit test that keeps
 * running afterward), or restore it before the run it is meant to protect ever starts (wrong
 * everywhere). Both entry points that touch the token cache therefore own the umask themselves,
 * bracketing their own call into this factory and the run that follows it — see the matching
 * comment in each of `scripts/publish-sourcedata.php`, `scripts/poll-sourcedata-merges.php` and
 * `bin/publish-sourcedata-consumer`.
 */
final class SourceDataPublisherFactory
{
    private ?SourceDataChangeRequestRepository $repository = null;

    private ?GuzzleClient $httpClient = null;

    private ?FilesystemAdapter $tokenCache = null;

    private bool $purgeServiceResolved = false;

    private ?ResourceTuplePurgeServiceInterface $purgeService = null;

    /**
     * `includeProcessors: false` — the sixth argument, and load-bearing rather than cosmetic.
     * LoggerFactory's default attaches RequestResponseProcessor, which THROWS a RuntimeException
     * for any record whose context does not carry type => request|response. The runners log
     * batch ids, so with the default every log call they make — including the ones inside their
     * catch blocks — would throw from inside the failure handling, before releaseClaim() ever
     * ran, stranding the batch and killing the process. Every other non-HTTP caller of this
     * factory passes false for the same reason; only the HTTP error middleware, which really
     * does log requests and responses, passes true.
     */
    public function logger(string $channel): LoggerInterface
    {
        return LoggerFactory::create($channel, null, 30, false, true, false);
    }

    /**
     * Memoised so every caller within one process — including {@see publishRunner()} and
     * {@see mergePollRunner()}, both of which need it — shares one repository wired to the one
     * PDO singleton, rather than each silently constructing its own.
     */
    public function repository(): SourceDataChangeRequestRepository
    {
        return $this->repository ??= new SourceDataChangeRequestRepository();
    }

    /**
     * Build a {@see PublishRunner} wired to a real {@see SourceDataPublisher}.
     *
     * @throws RuntimeException         If the GitHub App credential is not configured or
     *                                  `GITHUB_REPOSITORY` is unset — see
     *                                  {@see SourceDataPublisher::fromEnv()}.
     * @throws \InvalidArgumentException If `GITHUB_REPOSITORY` is set but not a valid
     *                                  "owner/repo" pair.
     */
    public function publishRunner(LoggerInterface $logger): PublishRunner
    {
        $repository = $this->repository();
        $publisher  = SourceDataPublisher::fromEnv($repository, $this->httpClient(), $this->tokenCache($logger), $logger);

        return new PublishRunner($repository, $publisher, logger: $logger);
    }

    /**
     * Build a {@see MergePollRunner} wired to a real {@see GitHubGitDataClient}.
     *
     * Unlike {@see publishRunner()}, this does not go through {@see SourceDataPublisher::fromEnv()}
     * — `MergePollRunner` needs the lower-level `GitHubGitDataClient` directly, not a publisher —
     * so the GitHub App credential and `GITHUB_REPOSITORY` are resolved here, independently, with
     * the same validation `SourceDataPublisher::fromEnv()` applies (via
     * {@see SourceDataPublisher::splitGithubRepository()}, the single shared definition of what a
     * well-formed `GITHUB_REPOSITORY` is) so the two entry points fail identically on the same
     * misconfiguration.
     *
     * @throws RuntimeException         If the GitHub App credential is not configured (see
     *                                  {@see GitHubAppAuth::fromEnv()}) or `GITHUB_REPOSITORY` is
     *                                  unset or empty.
     * @throws \InvalidArgumentException If `GITHUB_REPOSITORY` is set but not a valid
     *                                  "owner/repo" pair. This is one pasted repository URL away
     *                                  for any operator, and extends `LogicException`, not
     *                                  `RuntimeException` — callers must catch `\Throwable`, not
     *                                  `\RuntimeException`, or this exception reaches them as an
     *                                  uncaught fatal.
     */
    public function mergePollRunner(LoggerInterface $logger): MergePollRunner
    {
        $auth = GitHubAppAuth::fromEnv($this->httpClient(), $this->tokenCache($logger));

        $githubRepository = self::getEnvString('GITHUB_REPOSITORY');
        if ('' === $githubRepository) {
            throw new RuntimeException('GITHUB_REPOSITORY is not configured (expected "owner/repo").');
        }
        ['owner' => $owner, 'repo' => $repo] = SourceDataPublisher::splitGithubRepository($githubRepository);

        $client = new GitHubGitDataClient($owner, $repo, $auth, $this->httpClient());

        return new MergePollRunner($this->repository(), $client, $this->purgeService(), new AuditLogRepository(), $logger);
    }

    /**
     * Best-effort `XADD` notifier — see {@see SourceDataPublishNotifier}'s own class docblock for
     * why it never throws. Mirrors
     * {@see \LiturgicalCalendar\Api\Handlers\Concerns\WritesSourceData::sourceDataPublishNotifier()}:
     * null `\Redis` when `ext-redis` is missing or neither `REDIS_SOCKET` nor `REDIS_HOST` is
     * configured, which is the ordinary state for a self-hoster (both are commented out in
     * `.env.example`). A connection failure is caught and also falls back to a null `\Redis`
     * rather than failing this call — Redis here is an accelerator, never a dependency.
     */
    /**
     * Read an environment variable from BOTH layers: `$_ENV` first, then `getenv()`.
     *
     * Public and static because `bin/publish-sourcedata-consumer` needs exactly this and must not
     * grow a second copy — see the class docblock on why the wiring lives here.
     *
     * The two layers are not interchangeable. Dotenv populates `$_ENV` from the `.env*` FILES, but
     * PHP CLI commonly runs with `variables_order` excluding `E`, so a variable exported by the
     * shell or set by a systemd `Environment=`/`EnvironmentFile=` directive reaches `getenv()` and
     * NEVER `$_ENV`. Reading only `$_ENV` therefore silently ignores the configuration mechanism
     * the change-request runbook's own systemd unit tells operators to use, and the consumer would
     * quietly fall back to its defaults — connecting to 127.0.0.1 instead of the configured Redis.
     *
     * Mirrors {@see SourceDataPublisher}'s private helper of the same shape, duplicated there
     * rather than shared for the same precedent {@see \LiturgicalCalendar\Api\Services\OpenFgaClient}
     * set; this copy exists so the two entry points that are NOT `SourceDataPublisher` share one.
     *
     * @return string The trimmed value, or '' when set in neither layer (or empty in both).
     */
    public static function envString(string $name): string
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

    public function publishNotifier(): SourceDataPublishNotifier
    {
        $socket   = self::envString('REDIS_SOCKET');
        $host     = self::envString('REDIS_HOST');
        $password = self::envString('REDIS_PASSWORD');

        $redis = null;
        if (extension_loaded('redis') && ( '' !== $socket || '' !== $host )) {
            try {
                $redis = new \Redis();
                if ('' !== $socket) {
                    $redis->connect($socket, 0, 2.0); // 2 second timeout
                } else {
                    $port = self::envString('REDIS_PORT');
                    $redis->connect($host, is_numeric($port) ? (int) $port : 6379, 2.0); // 2 second timeout
                }
                if ('' !== $password) {
                    $redis->auth($password);
                }
            } catch (\Throwable) {
                $redis = null; // Best-effort; the publisher falls back to PG-plus-cron durability.
            }
        }

        $streamName = self::envString('REDIS_SOURCEDATA_PUBLISH_STREAM')
            ?: 'litcal:sourcedata-publish-stream';

        return new SourceDataPublishNotifier($redis, $streamName);
    }

    /**
     * Explicit timeouts, not Guzzle's default (no timeout at all — a hung TCP handshake or a
     * GitHub response that never completes would otherwise run indefinitely). This is what keeps
     * `PublishRunner`'s "still running" and "abandoned" distinguishable at all: without a bound
     * here, a whole publish can silently outlive `PublishRunner::DEFAULT_GRACE_SECONDS`, making
     * it the common case — not the rare one — that a second cron tick reclaims a batch whose
     * first publish attempt is still genuinely in flight (see `PublishRunner`'s class docblock,
     * "A merely SLOW process is not a dead one"). `connect_timeout: 10s` is generous for TLS+DNS
     * to api.github.com. `timeout: 30s` per request is generous for any single Git Data call.
     *
     * The quantity that must stay under the grace period is the WHOLE publish, not one request.
     * A batch issues one `createBlob` per changed file, serially, then six fixed calls. The
     * widest batch this repository can produce is the decrees corpus — decrees.json plus 14 i18n
     * locales plus 7 lectionary locales — so 22 blob writes plus 6 is 28 requests, or 840s if
     * every one hit its full ceiling. That is dozens of requests, not a handful, and it is why
     * the grace period is 1800 rather than the 600 an earlier draft of this comment assumed. If
     * either the timeout here or the widest batch grows, the grace period must grow with them.
     *
     * Shared between {@see publishRunner()} and {@see mergePollRunner()} — both talk to the same
     * GitHub API under the same latency assumptions, and a consumer that builds both (see
     * `bin/publish-sourcedata-consumer`) gets one client, not two.
     */
    private function httpClient(): GuzzleClient
    {
        return $this->httpClient ??= new GuzzleClient(['connect_timeout' => 10, 'timeout' => 30]);
    }

    /**
     * Installation-token cache.
     *
     * Installation tokens are cached (PSR-6) for 50 minutes against GitHub's one-hour token
     * life — see `GitHubAppAuth`'s own class docblock. A cron-invoked, short-lived CLI process
     * needs that cache to be filesystem-backed, not in-memory, or every single invocation would
     * re-authenticate; a long-lived consumer benefits from the same cache surviving restarts.
     *
     * What lands on disk is a BEARER CREDENTIAL carrying `contents: write` and
     * `pull_requests: write` on the repository, valid for up to 50 minutes. The private key it
     * is derived from is handled carefully (a path in the environment, never the key bytes;
     * never logged); the token derived from it must be too, and Symfony's `FilesystemAdapter`
     * creates its directory and its entries with 0777/0666 masked by the process umask —
     * 0755/0644 under the usual 0022, i.e. readable by every local user, UNLESS the caller has
     * already narrowed the umask before this method runs — see the class docblock's "The umask
     * window is NOT this class's job".
     *
     * The explicit `chmod` below is the second, independent layer: entries of any mode inside a
     * 0700 directory are unreachable regardless of what created them or under what umask, so it
     * repairs a directory an earlier, laxer run (or a caller that forgot to narrow its umask)
     * left wide open. It is a no-op — silently, via the `is_dir()` guard — on the very first run,
     * before the directory exists; that gap is exactly what a caller's own restrictive umask is
     * for, which is why both layers are load-bearing and neither is redundant with the other.
     *
     * Memoised per factory instance so {@see publishRunner()} and {@see mergePollRunner()} share
     * one cache and the directory is chmod'd (and any failure logged) at most once per run.
     */
    private function tokenCache(LoggerInterface $logger): FilesystemAdapter
    {
        if (null !== $this->tokenCache) {
            return $this->tokenCache;
        }

        $projectRoot   = dirname(__DIR__, 3);
        $tokenCache    = new FilesystemAdapter('github_app_tokens', 0, $projectRoot . '/cache');
        $tokenCacheDir = $projectRoot . '/cache/github_app_tokens';
        if (is_dir($tokenCacheDir) && !@chmod($tokenCacheDir, 0o700)) {
            $logger->warning(
                'Could not restrict permissions on the GitHub App token cache directory; the '
                    . 'installation token it holds may be readable by other local users.',
                ['directory' => $tokenCacheDir]
            );
        }

        return $this->tokenCache = $tokenCache;
    }

    /**
     * Returns null when OpenFGA is not configured — merge detection must still work on a
     * deployment without it, since the whole point of the write-mode seam
     * {@see \LiturgicalCalendar\Api\Services\SourceData\SourceDataWriteMode} exists behind is
     * that the stack is optional. Mirrors
     * {@see \LiturgicalCalendar\Api\Handlers\Concerns\ResolvesOutboxTooling::getPurgeService()},
     * duplicated rather than shared because that trait's version is memoised per HTTP request
     * (and carries a test seam via `setPurgeService()`) — a different lifecycle from this
     * factory's, which is memoised per CLI process instead.
     */
    private function purgeService(): ?ResourceTuplePurgeServiceInterface
    {
        if ($this->purgeServiceResolved) {
            return $this->purgeService;
        }
        $this->purgeServiceResolved = true;

        if (!OpenFgaClient::isConfigured()) {
            return $this->purgeService = null;
        }

        $pdo       = Connection::getInstance();
        $client    = OpenFgaClient::fromEnv();
        $repo      = new OutboxRepository($pdo);
        $processor = new OutboxProcessor($repo, $client);

        return $this->purgeService = new ResourceTuplePurgeService($client, $repo, $processor, $pdo);
    }

    /**
     * Get an environment variable as a string, or '' if unset/empty. Mirrors
     * {@see SourceDataPublisher}'s own private helper of the same name (duplicated rather than
     * shared — same precedent as {@see \LiturgicalCalendar\Api\Services\OpenFgaClient}'s own
     * copy, and {@see \LiturgicalCalendar\Api\Services\GitHub\GitHubAppAuth}'s).
     */
    private static function getEnvString(string $name): string
    {
        $value = $_ENV[$name] ?? null;
        if (is_string($value) && '' !== trim($value)) {
            return trim($value);
        }

        $envValue = getenv($name);
        if (is_string($envValue) && '' !== trim($envValue)) {
            return trim($envValue);
        }

        return '';
    }
}
