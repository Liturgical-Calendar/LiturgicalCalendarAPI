#!/usr/bin/env php
<?php

/**
 * Idempotent migration: bring every scoped-test OpenFGA tuple onto its
 * rite-qualified successor (issue #767).
 *
 * Three remappings, all of them because a scope has to name a rite:
 *
 *   general_roman_calendar_test:general_roman_calendar → rite_calendar_test:roman
 *   national_calendar_test:<id>                        → national_calendar_test:<rite>/<id>
 *   diocesan_calendar_test:<id>                        → diocesan_calendar_test:<rite>/<id>
 *
 * `general_roman_calendar_test` had exactly one id, denoting the Roman rite-level
 * calendar; it cannot name the Ambrosian one, so `rite_calendar_test` replaces it.
 * The national and diocesan types keep their names but gain a rite-qualified id,
 * because a bare calendar id is ambiguous: the source tree is partitioned as
 * `jsondata/sourcedata/rite/{rite}/calendars/...`, so `lugano_ch` could name an
 * Ambrosian calendar or a Roman one. Existing grants have to follow, or every
 * current test editor silently loses access.
 *
 * The rite of an existing national/diocesan tuple is inferred from that same
 * source tree, which is the authority on which rite a calendar is defined under.
 * A calendar id that is defined under two rites is reported and skipped — the
 * script never guesses which grant was meant.
 *
 * Usage:
 *   php scripts/migrate-rite-test-tuples.php [--dry-run|--apply] [--prune]
 *
 * Flags:
 *   --dry-run  (default) Print what WOULD be done without touching the store.
 *   --apply             Write the new tuples in OpenFGA.
 *   --prune             Additionally DELETE the superseded tuples. Off by
 *                       default: the legacy type and the unqualified ids stay
 *                       valid so a rollback to pre-#767 code keeps authorizing.
 *                       Only prune once every deployment runs merged code.
 *
 * Safety guarantees:
 *   - Copy-then-prune ordering: a tuple is never deleted before its replacement
 *     is confirmed written.
 *   - Writing a tuple that already exists is benign (TupleAlreadyExistsException
 *     is caught and treated as a no-op).
 *   - Deleting a tuple that no longer exists is benign (TupleNotFoundException
 *     is caught and treated as a no-op).
 *   - Already-migrated tuples are recognised and left alone.
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
use LiturgicalCalendar\Api\Services\TestScopeResolver;

const LEGACY_GRC_OBJECT = 'general_roman_calendar_test:general_roman_calendar';
const LEGACY_GRC_PREFIX = 'general_roman_calendar_test:';
const NATIONAL_PREFIX   = 'national_calendar_test:';
const DIOCESAN_PREFIX   = 'diocesan_calendar_test:';

$projectRoot = dirname(__DIR__);

// ---------------------------------------------------------------------------
// Bootstrap: load environment from .env* files if present
// ---------------------------------------------------------------------------
$dotenv = Dotenv::createImmutable(
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

echo 'Mode: ' . ( $dryRun ? 'DRY RUN (pass --apply to apply changes)' : 'APPLY' ) . PHP_EOL;
echo 'Prune superseded tuples: ' . ( $prune ? 'YES' : 'no (copy only)' ) . PHP_EOL;
echo PHP_EOL;

/**
 * Rites under which a diocesan calendar of the given id is defined on disk.
 *
 * Closures rather than named functions: this file is a script whose job is side
 * effects, and PSR-1 asks a file to declare symbols or execute logic, not both.
 *
 * @var callable(string): list<Rite> $ritesDefiningDiocese
 */
$ritesDefiningDiocese = static function (string $calendarId) use ($projectRoot): array {
    $found = [];
    foreach (Rite::cases() as $rite) {
        $matches = glob(
            sprintf(
                '%s/jsondata/sourcedata/rite/%s/calendars/dioceses/*/%s',
                $projectRoot,
                $rite->value,
                $calendarId
            ),
            GLOB_ONLYDIR
        );
        if ($matches !== false && $matches !== []) {
            $found[] = $rite;
        }
    }
    return $found;
};

/**
 * Rites under which a national calendar of the given id is defined on disk.
 *
 * @var callable(string): list<Rite> $ritesDefiningNation
 */
$ritesDefiningNation = static function (string $calendarId) use ($projectRoot): array {
    $found = [];
    foreach (Rite::cases() as $rite) {
        $file = sprintf(
            '%s/jsondata/sourcedata/rite/%s/calendars/nations/%s/%s.json',
            $projectRoot,
            $rite->value,
            $calendarId,
            $calendarId
        );
        if (is_file($file)) {
            $found[] = $rite;
        }
    }
    return $found;
};

// ---------------------------------------------------------------------------
// Dependency setup
// ---------------------------------------------------------------------------
if (!OpenFgaClient::isConfigured()) {
    fwrite(STDERR, "Error: OpenFGA is not configured. Set OPENFGA_API_URL, OPENFGA_STORE_ID, and OPENFGA_MODEL_ID.\n");
    exit(1);
}

$client = OpenFgaClient::fromEnv();

// ---------------------------------------------------------------------------
// Enumerate every tuple (paginated)
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

$candidates = array_values(array_filter($allTuples, static function (array $t): bool {
    return str_starts_with($t['object'], LEGACY_GRC_PREFIX)
        || str_starts_with($t['object'], NATIONAL_PREFIX)
        || str_starts_with($t['object'], DIOCESAN_PREFIX);
}));

$copiedCount  = 0;
$prunedCount  = 0;
$skippedCount = 0;
$alreadyCount = 0;

/** @var list<string> $unresolved */
$unresolved = [];

// ---------------------------------------------------------------------------
// Process each tuple
// ---------------------------------------------------------------------------
foreach ($candidates as $tuple) {
    $object   = $tuple['object'];
    $colonPos = (int) strpos($object, ':');
    $type     = substr($object, 0, $colonPos);
    $objectId = substr($object, $colonPos + 1);

    $newObject = null;

    if ($type === 'general_roman_calendar_test') {
        // The legacy type only ever had one id. Anything else is unexpected data
        // we must not guess a rite for.
        if ($object !== LEGACY_GRC_OBJECT) {
            ++$skippedCount;
            $unresolved[] = "{$object} (unexpected id for the legacy type)";
            echo "[SKIPPED] {$object} — unexpected id for the legacy type, leaving untouched" . PHP_EOL;
            continue;
        }
        $newObject = 'rite_calendar_test:' . Rite::ROMAN->value;
    } else {
        if (null !== TestScopeResolver::parseQualifiedId($objectId)) {
            ++$alreadyCount;
            echo "[ALREADY QUALIFIED] {$object}" . PHP_EOL;
            continue;
        }

        $rites = $type === 'diocesan_calendar_test'
            ? $ritesDefiningDiocese($objectId)
            : $ritesDefiningNation($objectId);

        if (count($rites) !== 1) {
            ++$skippedCount;
            $reason       = $rites === []
                ? 'no calendar of that id is defined under any rite'
                : 'defined under more than one rite: ' . implode(', ', array_column($rites, 'value'));
            $unresolved[] = "{$object} ({$reason})";
            echo "[SKIPPED] {$object} — {$reason}, leaving untouched" . PHP_EOL;
            continue;
        }

        $newObject = $type . ':' . TestScopeResolver::qualify($rites[0], $objectId);
    }

    if ($dryRun) {
        ++$copiedCount;
        echo "[DRY RUN] {$tuple['user']} {$tuple['relation']} {$object} → {$newObject}" . PHP_EOL;
        if ($prune) {
            echo "[DRY RUN] would then delete {$tuple['user']} {$tuple['relation']} {$object}" . PHP_EOL;
        }
        continue;
    }

    // --- APPLY: write the new tuple first; only then optionally prune the old ---

    try {
        $client->writeTuple($tuple['user'], $tuple['relation'], $newObject);
        echo "[COPIED] {$tuple['user']} {$tuple['relation']} {$object} → {$newObject}" . PHP_EOL;
    } catch (TupleAlreadyExistsException) {
        echo "[ALREADY EXISTS] {$tuple['user']} {$tuple['relation']} {$newObject} — write skipped" . PHP_EOL;
    }
    ++$copiedCount;

    if (!$prune) {
        continue;
    }

    try {
        $client->deleteTuple($tuple['user'], $tuple['relation'], $object);
        ++$prunedCount;
        echo "[PRUNED] {$tuple['user']} {$tuple['relation']} {$object}" . PHP_EOL;
    } catch (TupleNotFoundException) {
        // Old tuple was already removed in a previous run — benign.
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo PHP_EOL;
echo 'Summary:' . PHP_EOL;
echo sprintf("  Candidate tuples    : %d\n", count($candidates));
echo sprintf("  %-20s: %d\n", $dryRun ? 'Would copy' : 'Copied', $copiedCount);
if ($prune) {
    echo sprintf("  %-20s: %d\n", $dryRun ? 'Would prune' : 'Pruned', $prunedCount);
}
echo sprintf("  %-20s: %d\n", 'Already qualified', $alreadyCount);
echo sprintf("  %-20s: %d\n", 'Skipped', $skippedCount);

if ($unresolved !== []) {
    echo PHP_EOL;
    echo 'Left untouched (resolve by hand):' . PHP_EOL;
    foreach (array_unique($unresolved) as $entry) {
        echo "  - {$entry}" . PHP_EOL;
    }
}

echo PHP_EOL;
exit($skippedCount > 0 ? 2 : 0);
