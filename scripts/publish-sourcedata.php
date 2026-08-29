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
 *      ordinary recovery, not a failure, and does not affect this.)
 *   1  Misconfiguration (bad arguments, GitHub App or GITHUB_REPOSITORY not set), OR a publish
 *      attempt failed and the run stopped early — approved work remains queued unpublished;
 *      see the log (publish-sourcedata.log / .json.log) for which batch and why.
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
// Dependency wiring
//
// All environment-variable reads (the GitHub App credential, GITHUB_REPOSITORY, and their
// optional companions) are routed through SourceDataPublisher::fromEnv() /
// GitHubAppAuth::fromEnv() in src/, not read directly here. phpstan.neon.dist scans
// `paths: [src]` only, so a script-level `(string) $_ENV[...]` blind cast would be invisible
// to `composer analyse`; going through src/ keeps this script covered by the same PHPStan
// level 10 pass as everything else.
// ---------------------------------------------------------------------------
$pdo  = Connection::getInstance();
$repo = new SourceDataChangeRequestRepository($pdo);

$httpClient = new GuzzleClient();
// Installation tokens are cached (PSR-6) for 50 minutes against GitHub's one-hour token life —
// see GitHubAppAuth's own class docblock. A cron-invoked, short-lived CLI process needs that
// cache to be filesystem-backed, not in-memory, or every single invocation would re-authenticate.
$tokenCache = new FilesystemAdapter('github_app_tokens', 0, $projectRoot . '/cache');

try {
    $publisher = SourceDataPublisher::fromEnv($repo, $httpClient, $tokenCache);
} catch (\RuntimeException $e) {
    // Unconfigured GitHub App or GITHUB_REPOSITORY: approved batches accumulate unpublished —
    // silently, since nothing about that state looks like an error to an editor. Fail loudly
    // here instead.
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

$logger = LoggerFactory::create('publish-sourcedata');
$runner = new PublishRunner($repo, $publisher, logger: $logger);

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------
$result = $runner->runOnce($limit);
fwrite(
    STDOUT,
    sprintf("publish-sourcedata published=%d stopped_on_failure=%s\n", $result->published, $result->stoppedOnFailure ? 'true' : 'false')
);

// A stopped-early run means approved work is stuck queued and unpublished with no further
// retry until the next cron tick — that must be visible in the exit code, not just a log line
// nothing watches, or a revoked credential silently piles up work indefinitely.
exit($result->stoppedOnFailure ? 1 : 0);
