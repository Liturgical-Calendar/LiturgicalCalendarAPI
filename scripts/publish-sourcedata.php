#!/usr/bin/env php
<?php

/**
 * Cron entry point for the source-data change-request publisher (phase 2).
 *
 * One-shot: reclaims any batch stranded `queued` past the grace period, then claims up to a
 * limit of approved-and-unpublished batches and publishes each as a commit plus rolling pull
 * request via the GitHub App, stopping early if a publish attempt fails so a stale credential
 * or a GitHub outage cannot hammer the API on every remaining batch in the same run. See
 * {@see \LiturgicalCalendar\Api\Services\SourceData\PublishRunner} for the claim/release/reclaim
 * contract this relies on.
 *
 * Exit codes:
 *   0  Every claimed batch published, or the queue was empty. (A reclaimed stale claim is
 *      ordinary recovery, not a failure, and does not affect this. So is a batch that lost a
 *      race for its resource branch — a GitHub 422 — which the next tick republishes.)
 *   1  Misconfiguration (bad arguments, GitHub App or GITHUB_REPOSITORY not set), a database
 *      failure, OR a publish attempt failed and the run stopped early. In the last case the
 *      failed batch is back at `publication_status = 'none'` — NOT `queued`, which is a
 *      live claim and is exactly what the failure path gives up. Look for approved batches
 *      with `publication_status = 'none'` and a non-zero `publish_attempts`; see the log
 *      (publish-sourcedata.log / .json.log) for which batch and why.
 *
 * The summary line also reports `parked=N`: approved batches that have exhausted
 * SourceDataChangeRequestRepository::MAX_PUBLISH_ATTEMPTS consecutive attempts and are no
 * longer being claimed, so the rest of the queue can drain past them. A parked batch produces
 * NO failure of its own — that is the point of parking it — so a run can exit 0 with work
 * stuck. Monitor `parked` and GET /health's source_data_publisher block, not the exit code
 * alone.
 *
 * Usage:
 *   php scripts/publish-sourcedata.php [limit]
 *
 * `limit` (optional, default 10) caps how many batches this single run will publish.
 *
 * Required environment variables (loaded from .env* files if present):
 *   DB_HOST, DB_NAME, DB_USER, DB_PASSWORD (DB_PORT optional, defaults to 5432)
 *   GITHUB_APP_ID, GITHUB_APP_INSTALLATION_ID, GITHUB_APP_PRIVATE_KEY_PATH, GITHUB_REPOSITORY
 *
 * Optional:
 *   GITHUB_BASE_BRANCH          (default: development)
 *   GITHUB_APP_COMMITTER_NAME   (default: Litcal Publisher)
 *   GITHUB_APP_COMMITTER_EMAIL  (default: litcal-publisher[bot]@users.noreply.github.com)
 */

declare(strict_types=1);

// Refuse any entry that is not the CLI — see the identical guard in every other scripts/*.php
// for why this is not redundant with the shebang line above it.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client as GuzzleClient;
use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\SourceData\PublishRunner;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisher;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

// ---------------------------------------------------------------------------
// Bootstrap: load environment from .env* files if present. Same chain as
// bin/reconcile-outbox, for the same reason — CLI's variables_order may not
// include "E", so a systemd EnvironmentFile or exported shell vars would not
// reach $_ENV; Dotenv reading the files directly is what makes configuration
// reach this script regardless.
// ---------------------------------------------------------------------------
$projectRoot = dirname(__DIR__);
Dotenv::createImmutable(
    $projectRoot,
    ['.env', '.env.local', '.env.development', '.env.test', '.env.staging', '.env.production'],
    false
)->safeLoad();

// ---------------------------------------------------------------------------
// Argument parsing: optional positional limit, default 10 (PublishRunner's own default).
// ---------------------------------------------------------------------------
$limit = 10;
if (isset($argv[1])) {
    if (!ctype_digit($argv[1]) || (int) $argv[1] < 1) {
        fwrite(STDERR, sprintf("Error: limit must be a positive integer, got \"%s\".\n", $argv[1]));
        exit(1);
    }
    $limit = (int) $argv[1];
}

// ---------------------------------------------------------------------------
// Logging
//
// Set up FIRST, before anything that can fail, so that every failure below has somewhere to
// go besides stderr — a cron job's stderr is seen only if something is capturing it.
// ---------------------------------------------------------------------------
try {
    // includeProcessors: FALSE — the sixth argument, and it is load-bearing rather than
    // cosmetic. LoggerFactory's default attaches RequestResponseProcessor, which THROWS a
    // RuntimeException for any record whose context does not carry type => request|response.
    // PublishRunner logs batch ids and exception classes, so with the default every single
    // log call it makes — including the ones inside its catch blocks — would throw from
    // inside the failure handling, before releaseClaim() ever ran, stranding the batch and
    // killing the process. Every other non-HTTP caller of this factory passes false for the
    // same reason; only the HTTP error middleware, which really does log requests and
    // responses, passes true.
    $logger = LoggerFactory::create('publish-sourcedata', null, 30, false, true, false);
} catch (\Throwable $e) {
    // Nothing to log WITH, so this is the one failure that can only reach stderr. Still not a
    // raw fatal: an operator gets one line naming the cause instead of a stack trace.
    fwrite(STDERR, 'Error: could not open the publish-sourcedata log: ' . $e->getMessage() . "\n");
    exit(1);
}

// Opens the log handlers NOW, under the ordinary umask, before the restrictive one below.
// Monolog's RotatingFileHandler creates its file lazily on the first record, so without this
// the log files would be created mid-run at 0600 and become unreadable to anything that is not
// the cron user — a side effect of protecting the token cache, on files that are not secrets.
$logger->info('publish-sourcedata run starting.', ['limit' => $limit]);

// ---------------------------------------------------------------------------
// Dependency wiring
//
// All environment-variable reads (the GitHub App credential, GITHUB_REPOSITORY, and their
// optional companions) are routed through SourceDataPublisher::fromEnv() /
// GitHubAppAuth::fromEnv() in src/, not read directly here. phpstan.neon.dist scans
// `paths: [src]` only, so a script-level `(string) $_ENV[...]` blind cast would be invisible
// to `composer analyse`; going through src/ keeps this script covered by the same PHPStan
// level 10 pass as everything else.
//
// The database bootstrap is wrapped for the same reason the run below is, and it is the
// SIBLING of that one rather than a duplicate of it: PublishRunner catches every DB failure
// that happens DURING a run, but a database that is already down when the cron job starts
// fails here instead — earlier than anything PublishRunner can see — and would escape as an
// uncaught RuntimeException with no log entry and no summary line.
// ---------------------------------------------------------------------------
try {
    $pdo  = Connection::getInstance();
    $repo = new SourceDataChangeRequestRepository($pdo);
} catch (\Throwable $e) {
    $logger->error(
        'publish-sourcedata could not reach the database; no batch was claimed.',
        ['exception' => $e::class, 'message' => $e->getMessage()]
    );
    fwrite(STDERR, 'Error: database unavailable: ' . $e->getMessage() . "\n");
    exit(1);
}

// Explicit timeouts, not Guzzle's default (no timeout at all — a hung TCP handshake or a
// GitHub response that never completes would otherwise run indefinitely). This is what keeps
// PublishRunner's "still running" and "abandoned" distinguishable at all: without a bound
// here, a whole publish can silently outlive PublishRunner::DEFAULT_GRACE_SECONDS, making it
// the common case — not the rare one — that a second cron tick reclaims a batch whose first
// publish attempt is still genuinely in flight (see PublishRunner's class docblock, "A merely
// SLOW process is not a dead one"). connect_timeout: 10s is generous for TLS+DNS to
// api.github.com. timeout: 30s per request is generous for any single Git Data call.
//
// The quantity that must stay under the grace period is the WHOLE publish, not one request.
// A batch issues one createBlob per changed file, serially, then six fixed calls. The widest
// batch this repository can produce is the decrees corpus — decrees.json plus 14 i18n locales
// plus 7 lectionary locales — so 22 blob writes plus 6 is 28 requests, or 840s if every one
// hit its full ceiling. That is dozens of requests, not a handful, and it is why the grace
// period is 1800 rather than the 600 an earlier draft of this comment assumed. If either the
// timeout here or the widest batch grows, the grace period must grow with them.
$httpClient = new GuzzleClient(['connect_timeout' => 10, 'timeout' => 30]);


// ---------------------------------------------------------------------------
// Installation-token cache
//
// Installation tokens are cached (PSR-6) for 50 minutes against GitHub's one-hour token life —
// see GitHubAppAuth's own class docblock. A cron-invoked, short-lived CLI process needs that
// cache to be filesystem-backed, not in-memory, or every single invocation would re-authenticate.
//
// What lands on disk is a BEARER CREDENTIAL carrying `contents: write` and
// `pull_requests: write` on the repository, valid for up to 50 minutes. The private key it is
// derived from is handled carefully (a path in the environment, never the key bytes; never
// logged); the token derived from it must be too, and Symfony's FilesystemAdapter creates its
// directory and its entries with 0777/0666 masked by the process umask — 0755/0644 under the
// usual 0022, i.e. readable by every local user.
//
// So: a restrictive umask for the whole window in which cache entries are written (the token
// is fetched lazily, during the run, not here), plus an explicit chmod of the namespace
// directory, which also repairs a directory an earlier, laxer run created. Either alone would
// do — 0600 entries are unreadable, and entries of any mode inside a 0700 directory are
// unreachable — but they fail in different ways (a umask does nothing to what already exists;
// a directory mode can be widened by a well-meaning `chmod -R`), so both are set.
// ---------------------------------------------------------------------------
$previousUmask = umask(0o077);
$tokenCache    = new FilesystemAdapter('github_app_tokens', 0, $projectRoot . '/cache');
$tokenCacheDir = $projectRoot . '/cache/github_app_tokens';
if (is_dir($tokenCacheDir) && !@chmod($tokenCacheDir, 0o700)) {
    $logger->warning(
        'Could not restrict permissions on the GitHub App token cache directory; the '
            . 'installation token it holds may be readable by other local users.',
        ['directory' => $tokenCacheDir]
    );
}

try {
    $publisher = SourceDataPublisher::fromEnv($repo, $httpClient, $tokenCache);
} catch (\RuntimeException $e) {
    // Unconfigured GitHub App or GITHUB_REPOSITORY: approved batches accumulate unpublished —
    // silently, since nothing about that state looks like an error to an editor. Fail loudly
    // here instead.
    umask($previousUmask);
    $logger->error('publish-sourcedata is not configured; nothing was published.', ['message' => $e->getMessage()]);
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

$runner = new PublishRunner($repo, $publisher, logger: $logger);

// ---------------------------------------------------------------------------
// Run
//
// Wrapped for the same reason PublishRunner wraps every one of its own DB calls: a failure
// that escapes here is a raw PHP fatal on a cron job's stderr, with no summary line and no
// structured log entry — an operator would have to find it by grepping stack traces. Nothing
// inside runOnce() is expected to throw (it catches \Throwable at every fallible call), which
// is exactly why an exception reaching this point deserves to be reported rather than
// splashed.
// ---------------------------------------------------------------------------
try {
    $result = $runner->runOnce($limit);
} catch (\Throwable $e) {
    umask($previousUmask);
    $logger->error(
        'publish-sourcedata run failed with an unhandled exception.',
        ['exception' => $e::class, 'message' => $e->getMessage()]
    );
    fwrite(STDERR, 'Error: ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}

umask($previousUmask);

fwrite(
    STDOUT,
    sprintf(
        "publish-sourcedata published=%d stopped_on_failure=%s parked=%d\n",
        $result->published,
        $result->stoppedOnFailure ? 'true' : 'false',
        $result->parkedBatches
    )
);

// A stopped-early run means approved work is back at `none`, unpublished, with no further
// retry until the next cron tick — that must be visible in the exit code, not just a log line
// nothing watches, or a revoked credential silently piles up work indefinitely.
//
// `parked` deliberately does NOT affect the exit code: parking is what lets the rest of the
// queue drain, so a run that publishes everything it can and reports N parked batches has
// succeeded at its job. That is also why it is on the summary line and in /health — the signal
// has to exist somewhere, and this is not the channel for it.
exit($result->stoppedOnFailure ? 1 : 0);
