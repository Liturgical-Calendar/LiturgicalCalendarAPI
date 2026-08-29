#!/usr/bin/env php
<?php

/**
 * Cron entry point for the source-data change-request publisher (phase 2).
 *
 * One-shot: claims up to a limit of approved-and-unpublished batches, publishes each as a
 * commit plus rolling pull request via the GitHub App, and stops early if a publish attempt
 * fails so a stale credential or a GitHub outage cannot hammer the API on every remaining
 * batch in the same run. See {@see \LiturgicalCalendar\Api\Services\SourceData\PublishRunner}
 * for the claim/release contract this relies on.
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
use LiturgicalCalendar\Api\Services\GitHub\GitHubAppAuth;
use LiturgicalCalendar\Api\Services\GitHub\GitHubGitDataClient;
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
// Guard: the GitHub App credential must be configured. Without it, approved
// batches accumulate unpublished — silently, since nothing about that state
// looks like an error to an editor. Fail loudly here instead.
// ---------------------------------------------------------------------------
if (!GitHubAppAuth::isConfigured()) {
    fwrite(
        STDERR,
        'Error: GitHub App is not configured. Set GITHUB_APP_ID, GITHUB_APP_INSTALLATION_ID, '
            . "and GITHUB_APP_PRIVATE_KEY_PATH.\n"
    );
    exit(1);
}

$githubRepository = trim((string) ( $_ENV['GITHUB_REPOSITORY'] ?? getenv('GITHUB_REPOSITORY') ?: '' ));
if ($githubRepository === '') {
    fwrite(STDERR, "Error: GITHUB_REPOSITORY is not configured (expected \"owner/repo\").\n");
    exit(1);
}

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
// ---------------------------------------------------------------------------
$pdo  = Connection::getInstance();
$repo = new SourceDataChangeRequestRepository($pdo);

$appId          = (string) ( $_ENV['GITHUB_APP_ID'] ?? getenv('GITHUB_APP_ID') );
$installationId = (string) ( $_ENV['GITHUB_APP_INSTALLATION_ID'] ?? getenv('GITHUB_APP_INSTALLATION_ID') );
$privateKeyPath = (string) ( $_ENV['GITHUB_APP_PRIVATE_KEY_PATH'] ?? getenv('GITHUB_APP_PRIVATE_KEY_PATH') );

$httpClient = new GuzzleClient();
// Installation tokens are cached (PSR-6) for 50 minutes against GitHub's one-hour token life —
// see GitHubAppAuth's own class docblock. A cron-invoked, short-lived CLI process needs that
// cache to be filesystem-backed, not in-memory, or every single invocation would re-authenticate.
$tokenCache = new FilesystemAdapter('github_app_tokens', 0, $projectRoot . '/cache');
$auth       = new GitHubAppAuth($appId, $installationId, $privateKeyPath, $httpClient, $tokenCache);

['owner' => $repoOwner, 'repo' => $repoName] = SourceDataPublisher::splitGithubRepository($githubRepository);
$gitDataClient                               = new GitHubGitDataClient($repoOwner, $repoName, $auth, $httpClient);

$baseBranch     = (string) ( $_ENV['GITHUB_BASE_BRANCH'] ?? getenv('GITHUB_BASE_BRANCH') ?: 'development' );
$committerName  = (string) ( $_ENV['GITHUB_APP_COMMITTER_NAME'] ?? getenv('GITHUB_APP_COMMITTER_NAME') ?: 'Litcal Publisher' );
$committerEmail = (string) (
    $_ENV['GITHUB_APP_COMMITTER_EMAIL'] ?? getenv('GITHUB_APP_COMMITTER_EMAIL')
    ?: 'litcal-publisher[bot]@users.noreply.github.com'
);

$publisher = new SourceDataPublisher($repo, $gitDataClient, $baseBranch, $committerName, $committerEmail);
$logger    = LoggerFactory::create('publish-sourcedata');
$runner    = new PublishRunner($repo, $publisher, $logger);

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------
$published = $runner->runOnce($limit);
fwrite(STDOUT, sprintf("publish-sourcedata published=%d\n", $published));
exit(0);
