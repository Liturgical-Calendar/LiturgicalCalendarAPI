#!/usr/bin/env php
<?php

/**
 * Idempotent migration: bring every rite-level OpenFGA tuple onto the generalised
 * `rite_calendar` type, and finish #767's leftover test-type rename (issue #955).
 *
 *   general_roman_calendar:<sub>                       → rite_calendar:<rite>/<sub>
 *   general_roman_calendar_test:general_roman_calendar → rite_calendar_test:roman
 *
 * `general_roman_calendar` modelled the rite-level tier as though only the Roman rite had one.
 * Every rite has one, so the type becomes `rite_calendar` and its ids carry their rite, like
 * every other object type that names a calendar. Existing grants have to follow, or every
 * current editor of the temporale, the decrees corpus, the supported-locale set and the typical
 * editions silently loses access.
 *
 * The second mapping is not new work: `rite_calendar_test` has been
 * `general_roman_calendar_test`'s successor since #767, and `TestScopeResolver` stopped emitting
 * the old type then. It is folded in here so the legacy data type and the legacy test type reach
 * their end state in ONE operator window rather than two.
 *
 * Rite inference is never a guess. A missal id's rite is whichever `MissalCatalog` source
 * declares it — exactly one does, asserted by `MissalCatalogTest::testTheRitesDoNotShareIds`.
 * `temporale`, `decrees` and `supported_locales` are Roman: they are the only sub-resources the
 * legacy type ever carried, and it denoted the Roman tier. An id matching neither rule is
 * reported and skipped.
 *
 * The third in a family with `migrate-rite-test-tuples.php` (#767) and
 * `migrate-rite-data-tuples.php` (#786), and deliberately identical in shape to both.
 *
 * Usage:
 *   php scripts/migrate-rite-calendar-tuples.php [--dry-run|--apply] [--prune]
 *
 * Flags:
 *   --dry-run  (default) Print what WOULD be done without touching the store.
 *   --apply             Write the new tuples in OpenFGA.
 *   --prune             Additionally DELETE the superseded tuples. Off by default: the legacy
 *                       types stay valid in every allow-list, and the authorization middleware
 *                       falls back to them, so a rollback to pre-#955 code keeps authorizing.
 *                       Only prune once every deployment runs merged code — and prefer to do it
 *                       in the same operator window as the deferred RBAC `deleter` drop, which
 *                       waits on the identical condition.
 *
 * Safety guarantees:
 *   - Copy-then-prune ordering: a tuple is never deleted before its replacement is confirmed.
 *   - Writing a tuple that already exists is benign (TupleAlreadyExistsException).
 *   - Deleting a tuple that no longer exists is benign (TupleNotFoundException).
 *   - Already-migrated tuples are recognised and left alone.
 *   - Safe to re-run after a partial migration.
 *
 * Required environment variables (loaded from .env* files if present):
 *   OPENFGA_API_URL, OPENFGA_STORE_ID, OPENFGA_MODEL_ID
 *
 * Optional:
 *   OPENFGA_API_TOKEN
 */

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects -- unlike its two predecessors, this script's rite-inference
// logic is meaty enough to want real, independently-testable-by-eye named functions rather than
// closures assigned to locals; PSR-1 objects to a file that both declares symbols and runs
// top-level side-effecting logic, but a CLI-only operator script has no autoloading consumer to
// protect from that mixture the way a class file would.

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
use LiturgicalCalendar\Api\Enum\MissalCatalog;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Services\Exception\TupleAlreadyExistsException;
use LiturgicalCalendar\Api\Services\Exception\TupleNotFoundException;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\RiteScopedObjectId;

/**
 * The rite a legacy `general_roman_calendar` sub-resource id belongs to.
 *
 * Returns null when the id matches no rule, so the caller can report and skip rather than guess.
 */
function riteForLegacySubResource(string $subResource): ?Rite
{
    if (in_array($subResource, ['temporale', 'decrees', 'supported_locales'], true)) {
        return Rite::ROMAN;
    }

    foreach (Rite::cases() as $rite) {
        if (MissalCatalog::for($rite)->isValid($subResource)) {
            return $rite;
        }
    }

    return null;
}

/**
 * The successor object for one legacy tuple, or null when the tuple should be reported and skipped.
 *
 * @return array{0: string, 1: string}|null [objectType, objectId]
 */
function successorFor(string $objectType, string $objectId): ?array
{
    if ($objectType === 'general_roman_calendar_test') {
        // #767 gave this type exactly one id, denoting the Roman rite-level calendar.
        return $objectId === 'general_roman_calendar' ? ['rite_calendar_test', 'roman'] : null;
    }

    if ($objectType !== 'general_roman_calendar') {
        return null;
    }

    // Already migrated by a previous run: leave it alone rather than double-qualifying it.
    if (null !== RiteScopedObjectId::parse($objectId)) {
        return null;
    }

    $rite = riteForLegacySubResource($objectId);

    return null === $rite ? null : ['rite_calendar', RiteScopedObjectId::qualify($rite, $objectId)];
}

$projectRoot = dirname(__DIR__);

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
$dotenv = Dotenv::createImmutable(
    $projectRoot,
    ['.env', '.env.local', '.env.development', '.env.test', '.env.staging', '.env.production'],
    false
);
$dotenv->safeLoad();

$apply  = in_array('--apply', $argv, true);
$prune  = in_array('--prune', $argv, true);
$dryRun = !$apply;

echo 'Mode: ' . ( $dryRun ? 'DRY RUN (pass --apply to apply changes)' : 'APPLY' ) . PHP_EOL;
echo 'Prune superseded tuples: ' . ( $prune ? 'YES' : 'no (copy only)' ) . PHP_EOL;
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
// Enumerate every tuple (paginated). Read with no filter and select in app code,
// mirroring the sibling migration scripts: the type-only object filter is not
// reliably valid per the OpenFGA Read API spec.
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
    return str_starts_with($t['object'], 'general_roman_calendar:')
        || str_starts_with($t['object'], 'general_roman_calendar_test:');
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

    $successor = successorFor($type, $objectId);

    if (null === $successor) {
        if ($type === 'general_roman_calendar' && null !== RiteScopedObjectId::parse($objectId)) {
            ++$alreadyCount;
            echo "[ALREADY MIGRATED] {$object}" . PHP_EOL;
            continue;
        }

        ++$skippedCount;
        $unresolved[] = "{$object} (matches no known rite-inference rule)";
        echo "[SKIPPED] {$object} — matches no known rite-inference rule, leaving untouched" . PHP_EOL;
        continue;
    }

    [$newType, $newId] = $successor;
    $newObject         = $newType . ':' . $newId;

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
