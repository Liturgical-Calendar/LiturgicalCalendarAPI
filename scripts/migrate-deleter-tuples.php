#!/usr/bin/env php
<?php

/**
 * Idempotent migration: remap all `deleter` OpenFGA tuples to `admin` tuples.
 *
 * PR #668 folds delete capability into the `admin` relation and removes
 * `deleter`. Existing stores may still contain `*#deleter@user` tuples; this
 * script writes the equivalent `*#admin@user` tuple (write-before-delete) for
 * each one, so resource-admins retain delete capability after the model update.
 *
 * Usage:
 *   php scripts/migrate-deleter-tuples.php [--dry-run|--apply]
 *
 * Flags:
 *   --dry-run  (default) Print what WOULD be done without touching the store.
 *   --apply             Write new tuples and delete old ones in OpenFGA.
 *
 * Safety guarantees:
 *   - Writing a tuple that already exists is benign (TupleAlreadyExistsException
 *     is caught and treated as a no-op).
 *   - Deleting a tuple that no longer exists is benign (TupleNotFoundException
 *     is caught and treated as a no-op).
 *   - The script is safe to re-run after a partial migration.
 *
 * Required environment variables (loaded from .env* files if present):
 *   OPENFGA_API_URL, OPENFGA_STORE_ID, OPENFGA_MODEL_ID
 *
 * Optional:
 *   OPENFGA_API_TOKEN
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use LiturgicalCalendar\Api\Services\DeleterTupleMapper;
use LiturgicalCalendar\Api\Services\Exception\TupleAlreadyExistsException;
use LiturgicalCalendar\Api\Services\Exception\TupleNotFoundException;
use LiturgicalCalendar\Api\Services\OpenFgaClient;

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

$client = OpenFgaClient::fromEnv();
$mapper = new DeleterTupleMapper();

// ---------------------------------------------------------------------------
// Enumerate ALL tuples (full scan, paginated)
// ---------------------------------------------------------------------------
/** @var list<array{user: string, relation: string, object: string}> $allTuples */
$allTuples         = [];
$continuationToken = null;

do {
    // Read ALL tuples (empty user + empty object = no filter) to avoid relying on
    // type-only object filters. Filter to deleter tuples in app code below.
    $page              = $client->readTuples('', '', null, null, $continuationToken);
    $allTuples         = array_merge($allTuples, $page['tuples']);
    $continuationToken = $page['next_continuation_token'] !== '' ? $page['next_continuation_token'] : null;
} while ($continuationToken !== null);

// Keep only tuples with relation === 'deleter'.
$deleterTuples = array_values(
    array_filter($allTuples, static fn (array $t): bool => $t['relation'] === 'deleter')
);

$totalCount    = count($deleterTuples);
$migratedCount = 0;

// ---------------------------------------------------------------------------
// Process each deleter tuple
// ---------------------------------------------------------------------------
foreach ($deleterTuples as $tuple) {
    $mapped = $mapper->mapTuple($tuple);

    if ($mapped === null) {
        // Should never happen (we pre-filtered to deleter tuples), but be safe.
        continue;
    }

    ++$migratedCount;

    if ($dryRun) {
        echo "[DRY RUN] {$tuple['object']}#deleter → #admin" . PHP_EOL;
        continue;
    }

    // --- APPLY: write new admin tuple first, then remove old deleter tuple ---

    try {
        $client->writeTuple($mapped['user'], $mapped['relation'], $mapped['object']);
        echo "[MIGRATED] {$tuple['object']}#deleter → #admin (user: {$tuple['user']})" . PHP_EOL;
    } catch (TupleAlreadyExistsException) {
        echo "[ALREADY EXISTS] {$mapped['object']}#admin for {$mapped['user']} — write skipped" . PHP_EOL;
    }

    try {
        $client->deleteTuple($tuple['user'], $tuple['relation'], $tuple['object']);
    } catch (TupleNotFoundException) {
        // Old tuple was already removed in a previous run — benign.
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
$migratedLabel = $dryRun ? 'Would migrate' : 'Migrated';

echo PHP_EOL;
echo 'Summary:' . PHP_EOL;
echo sprintf("  Total deleter tuples found : %d\n", $totalCount);
echo sprintf("  %-27s: %d\n", $migratedLabel, $migratedCount);
echo PHP_EOL;
exit(0);
