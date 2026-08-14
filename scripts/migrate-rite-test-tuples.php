#!/usr/bin/env php
<?php

/**
 * Idempotent migration: copy every `general_roman_calendar_test` OpenFGA tuple
 * onto the generalised `rite_calendar_test:roman` object (issue #767).
 *
 * `general_roman_calendar_test` had exactly one id, `general_roman_calendar`,
 * denoting the Roman rite-level calendar. Once rites became a first-class scope
 * that type could no longer name the Ambrosian rite-level calendar, so
 * `TestScopeResolver` now emits `rite_calendar_test:<rite>` instead. Existing
 * grants have to follow, or every current test editor silently loses access.
 *
 * Usage:
 *   php scripts/migrate-rite-test-tuples.php [--dry-run|--apply] [--prune]
 *
 * Flags:
 *   --dry-run  (default) Print what WOULD be done without touching the store.
 *   --apply             Write the new tuples in OpenFGA.
 *   --prune             Additionally DELETE the old general_roman_calendar_test
 *                       tuples. Off by default: the old type stays in the model
 *                       and in every PHP allow-list so a rollback to pre-#767
 *                       code keeps authorizing. Only prune once every
 *                       deployment runs merged code.
 *
 * Safety guarantees:
 *   - Copy-then-prune ordering: a tuple is never deleted before its replacement
 *     is confirmed written.
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
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Services\Exception\TupleAlreadyExistsException;
use LiturgicalCalendar\Api\Services\Exception\TupleNotFoundException;
use LiturgicalCalendar\Api\Services\OpenFgaClient;

const LEGACY_OBJECT = 'general_roman_calendar_test:general_roman_calendar';
const LEGACY_PREFIX = 'general_roman_calendar_test:';

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
$prune  = in_array('--prune', $argv, true);
$dryRun = !$apply;

$modeName = $dryRun ? 'DRY RUN' : 'APPLY';
$modeHint = $dryRun ? ' (pass --apply to apply changes)' : '';
echo "Mode: {$modeName}{$modeHint}" . PHP_EOL;
echo 'Prune legacy tuples: ' . ( $prune ? 'YES' : 'no (copy only)' ) . PHP_EOL;
echo PHP_EOL;

// ---------------------------------------------------------------------------
// Dependency setup
// ---------------------------------------------------------------------------
if (!OpenFgaClient::isConfigured()) {
    fwrite(STDERR, "Error: OpenFGA is not configured. Set OPENFGA_API_URL, OPENFGA_STORE_ID, and OPENFGA_MODEL_ID.\n");
    exit(1);
}

$client = OpenFgaClient::fromEnv();

// ---------------------------------------------------------------------------
// Enumerate all general_roman_calendar_test tuples (paginated)
//
// Read ALL tuples (empty user + empty object = no filter) and filter in app
// code, mirroring scripts/migrate-test-tuples.php: the type-only object filter
// is not reliably valid per the OpenFGA Read API spec.
// ---------------------------------------------------------------------------
/** @var list<array{user: string, relation: string, object: string}> $allTuples */
$allTuples         = [];
$continuationToken = null;

do {
    $page              = $client->readTuples('', '', null, null, $continuationToken);
    $allTuples         = array_merge($allTuples, $page['tuples']);
    $continuationToken = $page['next_continuation_token'] !== '' ? $page['next_continuation_token'] : null;
} while ($continuationToken !== null);

$legacyTuples = array_values(
    array_filter($allTuples, static fn (array $t): bool => str_starts_with($t['object'], LEGACY_PREFIX))
);

$totalCount   = count($legacyTuples);
$copiedCount  = 0;
$prunedCount  = 0;
$skippedCount = 0;

/** @var list<string> $unexpectedObjects */
$unexpectedObjects = [];

$newObject = 'rite_calendar_test:' . Rite::ROMAN->value;

// ---------------------------------------------------------------------------
// Process each tuple
// ---------------------------------------------------------------------------
foreach ($legacyTuples as $tuple) {
    // The legacy type only ever had one id. Anything else is unexpected data we
    // must not guess a rite for, so report it and leave it alone.
    if ($tuple['object'] !== LEGACY_OBJECT) {
        ++$skippedCount;
        $unexpectedObjects[] = $tuple['object'];
        echo "[SKIPPED] {$tuple['object']} (unexpected id for the legacy type — leaving untouched)" . PHP_EOL;
        continue;
    }

    if ($dryRun) {
        ++$copiedCount;
        echo "[DRY RUN] {$tuple['user']} {$tuple['relation']} {$tuple['object']} → {$newObject}" . PHP_EOL;
        if ($prune) {
            echo "[DRY RUN] would then delete {$tuple['user']} {$tuple['relation']} {$tuple['object']}" . PHP_EOL;
        }
        continue;
    }

    // --- APPLY: write the new tuple first; only then optionally prune the old ---

    try {
        $client->writeTuple($tuple['user'], $tuple['relation'], $newObject);
        echo "[COPIED] {$tuple['user']} {$tuple['relation']} {$tuple['object']} → {$newObject}" . PHP_EOL;
    } catch (TupleAlreadyExistsException) {
        echo "[ALREADY EXISTS] {$tuple['user']} {$tuple['relation']} {$newObject} — write skipped" . PHP_EOL;
    }
    ++$copiedCount;

    if (!$prune) {
        continue;
    }

    try {
        $client->deleteTuple($tuple['user'], $tuple['relation'], $tuple['object']);
        ++$prunedCount;
        echo "[PRUNED] {$tuple['user']} {$tuple['relation']} {$tuple['object']}" . PHP_EOL;
    } catch (TupleNotFoundException) {
        // Old tuple was already removed in a previous run — benign.
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
$copiedLabel = $dryRun ? 'Would copy' : 'Copied';

echo PHP_EOL;
echo 'Summary:' . PHP_EOL;
echo sprintf("  Total legacy tuples : %d\n", $totalCount);
echo sprintf("  %-20s: %d\n", $copiedLabel, $copiedCount);
if ($prune) {
    echo sprintf("  %-20s: %d\n", $dryRun ? 'Would prune' : 'Pruned', $prunedCount);
}
echo sprintf("  %-20s: %d\n", 'Skipped', $skippedCount);

if ($unexpectedObjects !== []) {
    echo PHP_EOL;
    echo 'Unexpected legacy object ids (left untouched):' . PHP_EOL;
    foreach (array_unique($unexpectedObjects) as $object) {
        echo "  - {$object}" . PHP_EOL;
    }
}

echo PHP_EOL;
exit($skippedCount > 0 ? 2 : 0);
