#!/usr/bin/env php
<?php

/**
 * Retire the `viewer` relation for people (API #1049).
 *
 * People now read admin data through the role they hold. This sweep cleans up existing data:
 *   - deletes every `viewer` tuple held by a `user:` subject (a failed delete is enqueued in the outbox);
 *   - strips `viewer` from pending access requests, rejecting any request left empty
 *     (reviewer `system:viewer-retirement`);
 *   - revokes a calendar_editor / test_editor / developer role that was held only through `viewer`, through
 *     RoleCascadeService::maybeCascadeRoleRevoke(), which re-checks before it revokes.
 * Every change writes one audit-log entry. A second run finds nothing to do.
 *
 * Usage:
 *   php scripts/retire-viewer-tuples.php [--dry-run|--apply]
 *
 * Flags:
 *   --dry-run  (default) List what WOULD change, in three sections, without writing anything.
 *   --apply             Carry the plan out.
 *
 * Exit codes:
 *   0  dry run, or apply with no failures
 *   1  configuration or runtime error — including a Zitadel role read that failed while planning (nothing is
 *      changed), and an apply that stopped early (what was done so far, and every planned role revocation not
 *      carried out, is still printed)
 *   2  apply finished, but a tuple delete or request change failed outright or a planned role revocation was not
 *      carried out (deferred, kept or failed — listed with its reason in its own section; those need a manual
 *      look, since a later run cannot find a person whose viewer tuples are gone)
 *
 * Warnings (each failure's reason) go to stderr. The work itself is ViewerRetirementCli, in src/.
 *
 * Required environment variables (loaded from .env* files if present):
 *   OPENFGA_API_URL, OPENFGA_STORE_ID, OPENFGA_MODEL_ID, the DB_* settings and the ZITADEL_* settings.
 * The dry run needs the database too: it reads the pending access requests.
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
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\ViewerRetirementCli;
use LiturgicalCalendar\Api\Services\ViewerRetirementSweep;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

// ---------------------------------------------------------------------------
// Bootstrap: load environment from .env* files if present
// ---------------------------------------------------------------------------
$projectRoot = dirname(__DIR__);
$dotenv      = Dotenv::createImmutable(
    $projectRoot,
    ['.env', '.env.local', '.env.development', '.env.test', '.env.staging', '.env.production'],
    false
);
$dotenv->safeLoad();

// --apply enables writes; the default is a dry run.
$apply = in_array('--apply', $argv, true);

if (!OpenFgaClient::isConfigured()) {
    fwrite(STDERR, "Error: OpenFGA is not configured. Set OPENFGA_API_URL, OPENFGA_STORE_ID, and OPENFGA_MODEL_ID.\n");
    exit(1);
}

// Every failure's reason is logged as a warning: without a logger they would all be dropped.
$logger = new Logger('retire-viewer-tuples', [new StreamHandler('php://stderr', Level::Info)]);

try {
    $sweep = ViewerRetirementSweep::fromEnv($logger);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

exit(( new ViewerRetirementCli($sweep, STDOUT, STDERR) )->run($apply));
