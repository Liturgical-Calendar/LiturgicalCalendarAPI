#!/usr/bin/env php
<?php

/**
 * Defense-in-depth reconciler: scans ALL OpenFGA tuples and enqueues
 * DELETE_TUPLE outbox rows for every operational (editor/viewer) tuple
 * whose backing resource no longer exists on disk. `admin` tuples on
 * deleted resources are intentional governance and are never touched.
 *
 * Usage:
 *   php scripts/reconcile-resource-tuples.php [--apply]
 *
 * Flags:
 *   (no flag)  Dry-run: reports that --apply is needed; makes no changes.
 *   --apply    Runs the sweep and enqueues purge rows in the outbox.
 *
 * Required environment variables (loaded from .env* files if present):
 *   OPENFGA_API_URL, OPENFGA_STORE_ID, OPENFGA_MODEL_ID
 *
 * Optional:
 *   OPENFGA_API_TOKEN
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD (required for --apply)
 */

declare(strict_types=1);

// Refuse any entry that is not the CLI. These scripts ship to the server — they are run there per
// the RBAC runbook — and they sit under a path whose `.php` files are handed to php-fpm, so an HTTP
// request can reach them.
//
// Every script here currently also carries a `#!` line, which a web SAPI treats as output and which
// therefore invalidates the `declare(strict_types=1)` beneath it: they fail to COMPILE rather than
// run, and answer 500. That is an accident of formatting rather than a decision, and it is not a
// guarantee — a script added or edited without that exact pairing compiles and runs. This guard is
// what holds in that case, and `mint-official-key.php` is why it matters: it mints an `is_system`
// key exempt from the per-IP rate limit.
//
// Inlined per script rather than factored into a shared require: a guard that depends on resolving
// another path has a failure mode that a single constant comparison does not.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\Outbox\OutboxProcessor;
use LiturgicalCalendar\Api\Services\Outbox\ResourceTuplePurgeReconciler;
use LiturgicalCalendar\Api\Services\ResourceExistenceChecker;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeService;

// ---------------------------------------------------------------------------
// Bootstrap: load environment from .env* files if present
// ---------------------------------------------------------------------------
$projectRoot = dirname(__DIR__);
Dotenv::createImmutable(
    $projectRoot,
    ['.env', '.env.local', '.env.development', '.env.test', '.env.staging', '.env.production'],
    false
)->safeLoad();

// Initialize the file-path prefix that JsonData::path() requires.
// Router sets this during HTTP boot; CLI scripts must set it manually.
Router::$apiFilePath = $projectRoot . DIRECTORY_SEPARATOR;

// ---------------------------------------------------------------------------
// Argument parsing: --apply enables writes; default is dry-run
// ---------------------------------------------------------------------------
$apply = in_array('--apply', $argv, true);

if (!$apply) {
    echo 'Dry run: pass --apply to enqueue purges.' . PHP_EOL;
    exit(0);
}

// ---------------------------------------------------------------------------
// Guard: OpenFGA must be configured
// ---------------------------------------------------------------------------
if (!OpenFgaClient::isConfigured()) {
    fwrite(STDERR, "Error: OpenFGA is not configured. Set OPENFGA_API_URL, OPENFGA_STORE_ID, and OPENFGA_MODEL_ID.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Dependency wiring
// ---------------------------------------------------------------------------
$client     = OpenFgaClient::fromEnv();
$pdo        = Connection::getInstance();
$repo       = new OutboxRepository($pdo);
$processor  = new OutboxProcessor($repo, $client);
$purge      = new ResourceTuplePurgeService($client, $repo, $processor, $pdo);
$reconciler = new ResourceTuplePurgeReconciler($client, new ResourceExistenceChecker(), $purge);

// ---------------------------------------------------------------------------
// Run the sweep
// ---------------------------------------------------------------------------
echo 'Mode: APPLY — running reconciler sweep...' . PHP_EOL . PHP_EOL;

$result = $reconciler->sweep();

echo 'Summary:' . PHP_EOL;
echo sprintf("  Tuples scanned  : %d\n", $result['scanned']);
echo sprintf("  Objects purged  : %d\n", $result['purgedObjects']);
echo sprintf("  Rows enqueued   : %d\n", $result['enqueued']);
echo PHP_EOL;
exit(0);
