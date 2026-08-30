#!/usr/bin/env php
<?php

/**
 * Cron entry point for the source-data merge poller.
 *
 * ROLE: polls the rolling pull requests that carry published source-data change-request batches
 * and records what became of them — merged, closed unmerged, or merged without one of the
 * batches recorded against it (a review that landed concurrently with a publish). See
 * {@see \LiturgicalCalendar\Api\Services\SourceData\MergePollRunner} for the polling contract
 * this relies on, and in particular why this is polling rather than a webhook.
 *
 * `bin/publish-sourcedata-consumer` also runs a merge poll, on its own idle tick — this script
 * is what runs when the consumer is not up, exactly as `scripts/publish-sourcedata.php` is now
 * the backstop behind that same consumer's stream-driven publish path.
 *
 * Exit codes:
 *   0  Every open pull request was polled, or there were none.
 *   1  Misconfiguration (GitHub App or GITHUB_REPOSITORY unset OR malformed — GITHUB_REPOSITORY
 *      must be exactly "owner/repo"), a database failure, OR a poll failed and the run stopped
 *      early.
 *
 * The summary line also reports `reset=N` and `unpollable=N`. `reset` counts batches whose pull
 * request merged WITHOUT them (a review that landed concurrently with a publish); they are
 * claimable again and the next publish opens a fresh pull request carrying them, so this is not a
 * failure — but a value that keeps climbing means publishes and merges are racing routinely.
 * `unpollable` counts `open` batches with no pull request number, which should always be zero;
 * a non-zero value is an unexplained state that needs an operator, and it does NOT affect the
 * exit code, so monitor the line and GET /health, not the exit code alone.
 *
 * Usage:
 *   php scripts/poll-sourcedata-merges.php
 *
 * Required environment variables (loaded from .env* files if present):
 *   DB_HOST, DB_NAME, DB_USER, DB_PASSWORD (DB_PORT optional, defaults to 5432)
 *   GITHUB_APP_ID, GITHUB_APP_INSTALLATION_ID, GITHUB_APP_PRIVATE_KEY_PATH, GITHUB_REPOSITORY
 *
 * Optional:
 *   OPENFGA_API_URL, OPENFGA_STORE_ID, OPENFGA_MODEL_ID — when configured, a merged batch that
 *   deleted a resource has its operational OpenFGA tuples purged; see
 *   {@see \LiturgicalCalendar\Api\Services\SourceData\MergePollRunner::purgeIfResourceDeletion()}.
 *   Left unconfigured, merge detection still works — the purge step is a quiet no-op.
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
// bin/reconcile-outbox and scripts/publish-sourcedata.php, for the same reason — CLI's
// variables_order may not include "E", so a systemd EnvironmentFile or exported shell vars
// would not reach $_ENV; Dotenv reading the files directly is what makes configuration reach
// this script regardless.
// ---------------------------------------------------------------------------
$projectRoot = dirname(__DIR__);
Dotenv::createImmutable(
    $projectRoot,
    ['.env', '.env.local', '.env.development', '.env.test', '.env.staging', '.env.production'],
    false
)->safeLoad();

// ---------------------------------------------------------------------------
// Logging
//
// Set up FIRST, before anything that can fail, so that every failure below has somewhere to
// go besides stderr — a cron job's stderr is seen only if something is capturing it.
//
// includeProcessors: FALSE is baked into SourceDataPublisherFactory::logger() — see that
// method's own docblock. MergePollRunner logs batch ids and pull request numbers, so without
// it every log call this run makes — including the ones inside its own catch blocks — would
// throw under LoggerFactory's default processors. This is the exact defect
// scripts/publish-sourcedata.php shipped with in phase 2; the factory exists so a second entry
// point cannot repeat it.
// ---------------------------------------------------------------------------
$factory = new SourceDataPublisherFactory();
try {
    $logger = $factory->logger('poll-sourcedata-merges');
} catch (\Throwable $e) {
    // Nothing to log WITH, so this is the one failure that can only reach stderr. Still not a
    // raw fatal: an operator gets one line naming the cause instead of a stack trace.
    fwrite(STDERR, 'Error: could not open the poll-sourcedata-merges log: ' . $e->getMessage() . "\n");
    exit(1);
}

// Opens the log handlers NOW, under the ordinary umask, before the restrictive one set below
// around the installation-token cache. Monolog's RotatingFileHandler creates its file lazily
// on the first record, so without this the log files would be created mid-run at 0600 and
// become unreadable to anything that is not the cron user — a side effect of protecting the
// token cache, on files that are not secrets.
$logger->info('poll-sourcedata-merges run starting.');

// ---------------------------------------------------------------------------
// Dependency wiring
//
// All environment-variable reads (the GitHub App credential, GITHUB_REPOSITORY, OpenFGA, and
// their optional companions) are routed through SourceDataPublisherFactory, in src/, not read
// directly here — see scripts/publish-sourcedata.php's identical comment for why.
//
// The database bootstrap is wrapped for the same reason the run below is: MergePollRunner
// catches every DB failure that happens DURING a run, but a database that is already down when
// the cron job starts fails here instead — earlier than anything MergePollRunner can see — and
// would escape as an uncaught RuntimeException with no log entry and no summary line.
// ---------------------------------------------------------------------------
try {
    $factory->repository();
} catch (\Throwable $e) {
    $logger->error(
        'poll-sourcedata-merges could not reach the database; nothing was polled.',
        ['exception' => $e::class, 'message' => $e->getMessage()]
    );
    fwrite(STDERR, 'Error: database unavailable: ' . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Installation-token cache — the umask window
//
// SourceDataPublisherFactory::mergePollRunner() below builds a filesystem-backed cache for the
// GitHub App installation token. It does NOT touch the process umask, deliberately: the token
// is fetched LAZILY, the first time the built GitHubGitDataClient actually talks to GitHub,
// which happens inside runOnce() below, not inside the factory call. So the restrictive umask
// has to stay in effect across BOTH the factory call and the run that follows it, and this
// script — the caller — is what owns that window. See
// scripts/publish-sourcedata.php's identical comment for the full rationale (what lands on
// disk, and why the umask and the factory's own directory chmod are independent layers).
// ---------------------------------------------------------------------------
$previousUmask = umask(0o077);

try {
    $runner = $factory->mergePollRunner($logger);
} catch (\Throwable $e) {
    // Unconfigured GitHub App or GITHUB_REPOSITORY: merged and closed pull requests accumulate
    // unpolled — silently, since nothing about an `open` batch looks like an error on its own.
    // Fail loudly here instead.
    //
    // \Throwable, not \RuntimeException: SourceDataPublisherFactory::mergePollRunner() throws
    // InvalidArgumentException — a LogicException, not a RuntimeException — when
    // GITHUB_REPOSITORY is set but is not an "owner/repo" pair, which is one pasted repository
    // URL or one trailing slash away for any operator. A narrower catch is what made phase 2's
    // publisher exit 255 with nothing logged past "run starting"; this script does not repeat
    // that mistake for its own configuration surface.
    umask($previousUmask);
    // The class goes in the context because this catch is \Throwable: the reachable surface
    // today is only the two configuration exceptions, but that is a property of
    // mergePollRunner() not doing I/O beyond the token cache directory, not a guarantee.
    // Without it, a future TypeError here would read as "not configured" and send an operator
    // auditing four correct variables.
    $logger->error(
        'poll-sourcedata-merges is not configured; nothing was polled.',
        ['exception' => $e::class, 'message' => $e->getMessage()]
    );
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Run
//
// Wrapped for the same reason MergePollRunner wraps every one of its own DB and GitHub calls: a
// failure that escapes here is a raw PHP fatal on a cron job's stderr, with no summary line and
// no structured log entry. Nothing inside runOnce() is expected to throw (it catches
// \Throwable at every fallible call and reports the failure in its own result), which is
// exactly why an exception reaching this point deserves to be reported rather than splashed.
// ---------------------------------------------------------------------------
try {
    $result = $runner->runOnce();
} catch (\Throwable $e) {
    umask($previousUmask);
    $logger->error(
        'poll-sourcedata-merges run failed with an unhandled exception.',
        ['exception' => $e::class, 'message' => $e->getMessage()]
    );
    fwrite(STDERR, 'Error: ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}

umask($previousUmask);

fwrite(
    STDOUT,
    sprintf(
        "poll-sourcedata-merges merged=%d closed=%d reset=%d unpollable=%d stopped_on_failure=%s\n",
        $result->merged,
        $result->closed,
        $result->reset,
        $result->unpollable,
        $result->stoppedOnFailure ? 'true' : 'false'
    )
);

// A stopped-early run means the rest of the open pull requests were not polled this tick — that
// must be visible in the exit code, not just a log line nothing watches, or a stale credential
// or a GitHub outage silently stops merge detection indefinitely.
//
// `reset` and `unpollable` deliberately do NOT affect the exit code, for the same reason
// `parked` does not in scripts/publish-sourcedata.php: both are states an operator should
// monitor over time (via this summary line and GET /health), not failures of THIS run — a run
// that resets a batch, or reports a stubbornly non-zero `unpollable`, has still done its job.
exit($result->stoppedOnFailure ? 1 : 0);
