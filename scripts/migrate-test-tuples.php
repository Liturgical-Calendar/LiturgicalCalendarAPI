#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Idempotent migration: remap all `test_definition` OpenFGA tuples to their
 * scoped counterparts (diocesan_calendar_test, national_calendar_test, or
 * general_roman_calendar_test) using TestScopeResolver.
 *
 * Usage:
 *   php scripts/migrate-test-tuples.php [--dry-run|--apply]
 *
 * Flags:
 *   --dry-run  (default) Print what WOULD be done without touching the store.
 *   --apply             Write new tuples and delete old ones in OpenFGA.
 *
 * Safety guarantees:
 *   - If TestScopeResolver cannot resolve a test name (JSON file missing),
 *     the old tuple is NEVER deleted and the test id is reported as unresolved.
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

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use LiturgicalCalendar\Api\Services\Exception\TupleAlreadyExistsException;
use LiturgicalCalendar\Api\Services\Exception\TupleNotFoundException;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\TestScopeResolver;
use LiturgicalCalendar\Api\Services\TestTupleMigration;

// ---------------------------------------------------------------------------
// Bootstrap: load environment from .env* files if present
// ---------------------------------------------------------------------------
$projectRoot = dirname(__DIR__);
$dotenv      = Dotenv::createImmutable($projectRoot, ['.env', '.env.local', '.env.development', '.env.test', '.env.staging', '.env.production'], false);
$dotenv->safeLoad();

// ---------------------------------------------------------------------------
// Argument parsing: --apply enables writes; default is --dry-run
// ---------------------------------------------------------------------------
$apply  = in_array('--apply', $argv, true);
$dryRun = !$apply;

$modeName = $dryRun ? 'DRY RUN' : 'APPLY';
echo "Mode: {$modeName}" . ($dryRun ? ' (pass --apply to apply changes)' : '') . PHP_EOL;
echo PHP_EOL;

// ---------------------------------------------------------------------------
// Dependency setup
// ---------------------------------------------------------------------------
if (!OpenFgaClient::isConfigured()) {
    fwrite(STDERR, "Error: OpenFGA is not configured. Set OPENFGA_API_URL, OPENFGA_STORE_ID, and OPENFGA_MODEL_ID.\n");
    exit(1);
}

$client    = OpenFgaClient::fromEnv();
$resolver  = new TestScopeResolver();
$migration = new TestTupleMigration();

// ---------------------------------------------------------------------------
// Enumerate all test_definition tuples (paginated)
// ---------------------------------------------------------------------------
/** @var list<array{user: string, relation: string, object: string}> $allTuples */
$allTuples         = [];
$continuationToken = null;

do {
    // Read ALL tuples (empty user + empty object = no filter) to avoid relying on
    // the type-only object filter ("test_definition:" with an empty user), which is
    // not reliably valid per the OpenFGA Read API spec. Filter in app code below.
    $page              = $client->readTuples('', '', null, null, $continuationToken);
    $allTuples         = array_merge($allTuples, $page['tuples']);
    $continuationToken = $page['next_continuation_token'] !== '' ? $page['next_continuation_token'] : null;
} while ($continuationToken !== null);

// Keep only tuples whose object belongs to the test_definition type.
$allTuples = array_values(
    array_filter($allTuples, static fn (array $t): bool => str_starts_with($t['object'], 'test_definition:'))
);

$totalCount      = count($allTuples);
$migratedCount   = 0;
$unresolvedCount = 0;

/** @var list<string> $unresolvedIds */
$unresolvedIds = [];

// ---------------------------------------------------------------------------
// Process each tuple
// ---------------------------------------------------------------------------
foreach ($allTuples as $tuple) {
    $mapped = $migration->mapTuple($tuple, $resolver);

    // Extract the test name from the old object for reporting
    $colonPos = strpos($tuple['object'], ':');
    $testId   = $colonPos !== false ? substr($tuple['object'], $colonPos + 1) : $tuple['object'];

    if ($mapped === null) {
        ++$unresolvedCount;
        $unresolvedIds[] = $testId;
        echo "[UNRESOLVED] {$tuple['object']} (no test file found — skipping)" . PHP_EOL;
        continue;
    }

    ++$migratedCount;

    if ($dryRun) {
        echo "[DRY RUN] {$tuple['object']} → {$mapped['object']}" . PHP_EOL;
        continue;
    }

    // --- APPLY: write new tuple first, then remove old ---

    try {
        $client->writeTuple($mapped['user'], $mapped['relation'], $mapped['object']);
        echo "[MIGRATED] {$tuple['object']} → {$mapped['object']}" . PHP_EOL;
    } catch (TupleAlreadyExistsException) {
        echo "[ALREADY EXISTS] {$mapped['object']} — write skipped" . PHP_EOL;
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
$migratedLabel   = $dryRun ? 'Would migrate' : 'Migrated';
$unresolvedLabel = $dryRun ? 'Would skip (unresolved)' : 'Skipped (unresolved)';

echo PHP_EOL;
echo 'Summary:' . PHP_EOL;
echo sprintf("  Total test_definition tuples : %d\n", $totalCount);
echo sprintf("  %-29s: %d\n", $migratedLabel, $migratedCount);
echo sprintf("  %-29s: %d\n", $unresolvedLabel, $unresolvedCount);

if ($unresolvedIds !== []) {
    echo PHP_EOL;
    echo 'Unresolved test IDs (test JSON file not found):' . PHP_EOL;
    foreach ($unresolvedIds as $id) {
        echo "  - {$id}" . PHP_EOL;
    }
}

echo PHP_EOL;
exit($unresolvedCount > 0 ? 2 : 0);
