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
 *   1  configuration or runtime error
 *   2  apply finished but some changes failed (see the counts; re-run to retry)
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
use LiturgicalCalendar\Api\Services\ViewerRetirementSweep;

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

// ---------------------------------------------------------------------------
// Argument parsing: --apply enables writes; default is --dry-run
// ---------------------------------------------------------------------------
$apply  = in_array('--apply', $argv, true);
$dryRun = !$apply;

$modeName = $dryRun ? 'DRY RUN' : 'APPLY';
$modeHint = $dryRun ? ' (pass --apply to apply changes)' : '';
echo "Mode: {$modeName}{$modeHint}" . PHP_EOL;
echo PHP_EOL;

// ---------------------------------------------------------------------------
// Dependency setup
// ---------------------------------------------------------------------------
if (!OpenFgaClient::isConfigured()) {
    fwrite(STDERR, "Error: OpenFGA is not configured. Set OPENFGA_API_URL, OPENFGA_STORE_ID, and OPENFGA_MODEL_ID.\n");
    exit(1);
}

try {
    $sweep = ViewerRetirementSweep::fromEnv();
    $plan  = $sweep->plan();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// ---------------------------------------------------------------------------
// The plan, in three sections
// ---------------------------------------------------------------------------
$describe = static function (array $permissions): string {
    if ($permissions === []) {
        return '(none)';
    }
    $parts = [];
    foreach ($permissions as $p) {
        /** @var array{object_type: string, object_id: string, relation: string} $p */
        $parts[] = "{$p['relation']} {$p['object_type']}:{$p['object_id']}";
    }
    return implode(', ', $parts);
};

echo sprintf('Viewer tuples held by people (%d) — delete:', count($plan->viewerTuples)) . PHP_EOL;
foreach ($plan->viewerTuples as $tuple) {
    echo "  - {$tuple['user']} viewer {$tuple['object']}" . PHP_EOL;
}
echo PHP_EOL;

echo sprintf('Pending access requests naming viewer (%d):', count($plan->pendingRequests)) . PHP_EOL;
foreach ($plan->pendingRequests as $request) {
    $action = strtoupper($request['action']);
    echo "  - [{$action}] request {$request['id']} (user {$request['user']})" . PHP_EOL;
    echo '      drop: ' . $describe($request['removed']) . PHP_EOL;
    echo '      keep: ' . $describe($request['keep']) . PHP_EOL;
}
echo PHP_EOL;

echo sprintf('Roles held only through viewer (%d) — revoke:', count($plan->roleRevocations)) . PHP_EOL;
foreach ($plan->roleRevocations as $revocation) {
    echo "  - user {$revocation['user']}: {$revocation['role']}" . PHP_EOL;
}
echo PHP_EOL;

if ($plan->isEmpty()) {
    echo 'Nothing to do.' . PHP_EOL;
    exit(0);
}

if ($dryRun) {
    echo 'Dry run: nothing was changed. Review the lists above, then re-run with --apply.' . PHP_EOL;
    exit(0);
}

// ---------------------------------------------------------------------------
// Apply
// ---------------------------------------------------------------------------
try {
    $counts = $sweep->apply($plan);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Summary:' . PHP_EOL;
foreach ($counts as $name => $count) {
    echo sprintf("  %-20s: %d\n", $name, $count);
}
echo PHP_EOL;

exit(( $counts['tuples_failed'] + $counts['roles_failed'] ) > 0 ? 2 : 0);
