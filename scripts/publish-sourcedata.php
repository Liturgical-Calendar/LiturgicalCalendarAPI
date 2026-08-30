#!/usr/bin/env php
<?php

/**
 * Cron entry point for the source-data change-request publisher.
 *
 * ROLE (phase 3): this is now the BACKSTOP behind `bin/publish-sourcedata-consumer`, the Redis
 * stream consumer that reacts to an approval within seconds. This script exists for what the
 * stream cannot guarantee — a lost `XADD` (see
 * {@see \LiturgicalCalendar\Api\Services\SourceData\SourceDataPublishNotifier}, which never
 * throws and so can silently lose a notification) or a dead consumer process — so it still runs
 * on its own cron schedule, just less urgently than in phase 2, when it was the only path.
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
 *   1  Misconfiguration (bad arguments, GitHub App or GITHUB_REPOSITORY unset OR malformed —
 *      GITHUB_REPOSITORY must be exactly "owner/repo"), a database
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
use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisherFactory;

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
//
// includeProcessors: FALSE is baked into SourceDataPublisherFactory::logger() — see that
// method's own docblock for why it is load-bearing rather than cosmetic. This is precisely the
// wiring that used to live here, inline, and was the phase 2 defect: a script CONSTRUCTING
// what every test INJECTED.
// ---------------------------------------------------------------------------
$factory = new SourceDataPublisherFactory();
try {
    $logger = $factory->logger('publish-sourcedata');
} catch (\Throwable $e) {
    // Nothing to log WITH, so this is the one failure that can only reach stderr. Still not a
    // raw fatal: an operator gets one line naming the cause instead of a stack trace.
    fwrite(STDERR, 'Error: could not open the publish-sourcedata log: ' . $e->getMessage() . "\n");
    exit(1);
}

// Opens the log handlers NOW, under the ordinary umask, before the restrictive one set below
// around the installation-token cache. Monolog's RotatingFileHandler creates its file lazily
// on the first record, so without this the log files would be created mid-run at 0600 and
// become unreadable to anything that is not the cron user — a side effect of protecting the
// token cache, on files that are not secrets.
$logger->info('publish-sourcedata run starting.', ['limit' => $limit]);

// ---------------------------------------------------------------------------
// Dependency wiring
//
// All environment-variable reads (the GitHub App credential, GITHUB_REPOSITORY, and their
// optional companions) are routed through SourceDataPublisherFactory, in src/, not read
// directly here. phpstan.neon.dist scans `paths: [src]` only, so a script-level
// `(string) $_ENV[...]` blind cast would be invisible to `composer analyse`; going through
// src/ keeps this script covered by the same PHPStan level 10 pass as everything else.
//
// The database bootstrap is wrapped for the same reason the run below is, and it is the
// SIBLING of that one rather than a duplicate of it: PublishRunner catches every DB failure
// that happens DURING a run, but a database that is already down when the cron job starts
// fails here instead — earlier than anything PublishRunner can see — and would escape as an
// uncaught RuntimeException with no log entry and no summary line.
// ---------------------------------------------------------------------------
try {
    $factory->repository();
} catch (\Throwable $e) {
    $logger->error(
        'publish-sourcedata could not reach the database; no batch was claimed.',
        ['exception' => $e::class, 'message' => $e->getMessage()]
    );
    fwrite(STDERR, 'Error: database unavailable: ' . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Installation-token cache — the umask window
//
// SourceDataPublisherFactory::publishRunner() below builds a filesystem-backed cache for the
// GitHub App installation token (see that factory's own docblocks for the Guzzle timeout and
// token-cache rationale — the object construction itself now lives there). What it does NOT do
// is touch the process umask, deliberately: the token is fetched LAZILY, the first time the
// built PublishRunner's publisher actually talks to GitHub, which happens inside runOnce()
// below, not inside the factory call. So the restrictive umask has to stay in effect across
// BOTH the factory call and the run that follows it, and this script — the caller — is what
// owns that window.
//
// What lands on disk is a BEARER CREDENTIAL carrying `contents: write` and
// `pull_requests: write` on the repository, valid for up to 50 minutes. Symfony's
// FilesystemAdapter creates its directory and its entries with 0777/0666 masked by the process
// umask — 0755/0644 under the usual 0022, i.e. readable by every local user, unless the umask
// is narrowed first. `publishRunner()`'s own explicit chmod of the cache directory is the
// second, independent layer (entries inside a 0700 directory are unreachable regardless of
// their own mode) but is a no-op on the very first run, before the directory exists — this
// umask is what protects exactly that gap.
// ---------------------------------------------------------------------------
$previousUmask = umask(0o077);

try {
    $runner = $factory->publishRunner($logger);
} catch (\Throwable $e) {
    // Unconfigured GitHub App or GITHUB_REPOSITORY: approved batches accumulate unpublished —
    // silently, since nothing about that state looks like an error to an editor. Fail loudly
    // here instead.
    //
    // \Throwable, not \RuntimeException, and this is the difference between an alarm and
    // silence. SourceDataPublisherFactory::publishRunner() also throws InvalidArgumentException
    // — a LogicException, not a RuntimeException — when GITHUB_REPOSITORY is set but is not an
    // "owner/repo" pair, which is one pasted repository URL or one trailing slash away for any
    // operator. A narrower catch let that escape as an uncaught fatal: exit 255 (a code this
    // script's own table does not list), a stack trace on a cron job's stderr that usually goes
    // nowhere, and not one log line past "run starting". Same reasoning as the two sibling
    // wraps around this one: whatever goes wrong, the operator gets a message and an exit code,
    // never a stack trace.
    umask($previousUmask);
    // The class goes in the context because this catch is now \Throwable: the reachable
    // surface today is only the two configuration exceptions, but that is a property of
    // publishRunner() not doing I/O beyond the token cache directory, not a guarantee. Without
    // it, a future TypeError here would read as "not configured" and send an operator auditing
    // four correct variables.
    $logger->error(
        'publish-sourcedata is not configured; nothing was published.',
        ['exception' => $e::class, 'message' => $e->getMessage()]
    );
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

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

// A stopped-early run means approved work is back at `none`, unpublished, and held off by its own
// `next_attempt_at` until the backoff elapses — that must be visible in the exit code, not just a
// log line nothing watches, or a revoked credential silently piles up work indefinitely.
//
// `parked` deliberately does NOT affect the exit code: parking is what lets the rest of the
// queue drain, so a run that publishes everything it can and reports N parked batches has
// succeeded at its job. That is also why it is on the summary line and in /health — the signal
// has to exist somewhere, and this is not the channel for it.
exit($result->stoppedOnFailure ? 1 : 0);
