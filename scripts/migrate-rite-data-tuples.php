#!/usr/bin/env php
<?php

/**
 * Idempotent migration: rite-qualify the OpenFGA object ids of the calendar *data*
 * resource types (issue #786).
 *
 *   national_calendar:<id>  → national_calendar:<rite>/<id>
 *   diocesan_calendar:<id>  → diocesan_calendar:<rite>/<id>
 *   wider_region:<id>       → wider_region:<rite>/<id>
 *
 * A bare calendar id does not identify a calendar: the source tree is partitioned as
 * `jsondata/sourcedata/rite/{rite}/calendars/...`, so `lugano_ch` could name an
 * Ambrosian calendar or a Roman one, and a grant on the bare id would silently widen
 * to cover whichever was added later. Existing grants have to follow, or every current
 * calendar editor loses access.
 *
 * The companion of `migrate-rite-test-tuples.php`, which did the same for the *test*
 * scope types in #785. These are **production calendar-editing grants**, so the default
 * is copy-only and nothing is deleted until `--prune`.
 *
 * National calendars and wider regions exist only in the Roman rite, so their rite is a
 * constant. Only a diocese needs inferring, from the rite-partitioned source tree, which
 * is the authority on which rite a calendar is defined under. A diocese id defined under
 * two rites — or under none — is reported and skipped: the script never guesses which
 * grant was meant.
 *
 * `general_roman_calendar` is deliberately untouched HERE: its ids are `temporale`, `decrees`,
 * `supported_locales` and missal editions, which are not calendars. They were Roman by
 * construction when this script was written; #953 added the Ambrosian `EDITIO_TYPICA_2024`, and
 * #955 generalises the whole type to `rite_calendar` with rite-qualified ids. That migration is
 * `scripts/migrate-rite-calendar-tuples.php`, not this one.
 *
 * Usage:
 *   php scripts/migrate-rite-data-tuples.php [--dry-run|--apply] [--prune]
 *
 * Flags:
 *   --dry-run  (default) Print what WOULD be done without touching the store.
 *   --apply             Write the rite-qualified tuples in OpenFGA.
 *   --prune             Additionally DELETE the superseded unqualified tuples. Off by
 *                       default: the unqualified ids stay valid in every allow-list so a
 *                       rollback to pre-#786 code keeps authorizing. Only prune once
 *                       every deployment runs merged code.
 *
 * Safety guarantees:
 *   - Copy-then-prune ordering: a tuple is never deleted before its replacement is
 *     confirmed written.
 *   - Writing a tuple that already exists is benign (TupleAlreadyExistsException).
 *   - Deleting a tuple that no longer exists is benign (TupleNotFoundException).
 *   - Already-qualified ids are recognised and skipped.
 *   - `member_nation` tuples are migrated on BOTH sides: the user side is a
 *     `national_calendar:` object, not a `user:`, so it needs qualifying too.
 *   - Safe to re-run after a partial migration.
 *
 * Required environment variables (loaded from .env* files if present):
 *   OPENFGA_API_URL, OPENFGA_STORE_ID, OPENFGA_MODEL_ID
 *
 * Optional:
 *   OPENFGA_API_TOKEN
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
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Services\Exception\TupleAlreadyExistsException;
use LiturgicalCalendar\Api\Services\Exception\TupleNotFoundException;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\RiteScopedObjectId;

/** Object types whose ids name a calendar and therefore gain a rite. */
const QUALIFIED_TYPES = ['national_calendar', 'diocesan_calendar', 'wider_region'];

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

/**
 * Rites under which a diocesan calendar of the given id is defined on disk.
 *
 * Only the diocesan tier needs inferring. National calendars and wider regions exist
 * exclusively in the Roman rite — there is no `rite/ambrosian/calendars/nations` or
 * `.../wider_regions` — so their rite is a constant, not a lookup, and stays constant
 * however the Roman tree grows.
 *
 * Using the filesystem for them would also have been fragile rather than merely
 * redundant: the Vatican is announced as a national calendar but is still served by the
 * General Roman Calendar and has no `nations/VA/VA.json` of its own yet, so a filesystem
 * probe would report `national_calendar:VA` unresolvable and skip a live grant.
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
            sprintf('%s/jsondata/sourcedata/rite/%s/calendars/dioceses/*/%s', $projectRoot, $rite->value, $calendarId),
            GLOB_ONLYDIR
        );
        if ($matches !== false && $matches !== []) {
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

/**
 * Rite-qualify one `<type>:<id>` reference, or return it unchanged.
 *
 * Returns [newReference, status] where status is one of 'qualified', 'unchanged'
 * (not a calendar-naming type, or already qualified) or a reason string.
 *
 * @var callable(string): array{0: string, 1: string} $qualifyReference
 */
$qualifyReference = static function (string $reference) use ($ritesDefiningDiocese): array {
    $colon = strpos($reference, ':');
    if ($colon === false) {
        return [$reference, 'unchanged'];
    }

    $type = substr($reference, 0, $colon);
    $id   = substr($reference, $colon + 1);

    if (!in_array($type, QUALIFIED_TYPES, true)) {
        return [$reference, 'unchanged'];
    }

    if (null !== RiteScopedObjectId::parse($id)) {
        return [$reference, 'already'];
    }

    // National calendars and wider regions exist only in the Roman rite.
    if ($type !== 'diocesan_calendar') {
        return [$type . ':' . RiteScopedObjectId::qualify(Rite::ROMAN, $id), 'qualified'];
    }

    $rites = $ritesDefiningDiocese($id);
    if (count($rites) !== 1) {
        $reason = $rites === []
            ? "no diocesan calendar of id `{$id}` is defined under any rite"
            : "`{$id}` is defined under more than one rite: " . implode(', ', array_column($rites, 'value'));
        return [$reference, $reason];
    }

    return [$type . ':' . RiteScopedObjectId::qualify($rites[0], $id), 'qualified'];
};

$copiedCount  = 0;
$prunedCount  = 0;
$skippedCount = 0;
$alreadyCount = 0;

/** @var list<string> $unresolved */
$unresolved = [];

foreach ($allTuples as $tuple) {
    // Both sides can carry a calendar reference: `member_nation` tuples have a
    // national_calendar on the USER side, not a user:.
    [$newUser, $userStatus]     = $qualifyReference($tuple['user']);
    [$newObject, $objectStatus] = $qualifyReference($tuple['object']);

    $statuses = [$userStatus, $objectStatus];

    $problems = array_values(array_filter($statuses, static fn (string $s): bool => !in_array($s, ['unchanged', 'already', 'qualified'], true)));
    if ($problems !== []) {
        ++$skippedCount;
        $detail       = "{$tuple['user']} {$tuple['relation']} {$tuple['object']} — " . implode('; ', $problems);
        $unresolved[] = $detail;
        echo "[SKIPPED] {$detail}" . PHP_EOL;
        continue;
    }

    if (!in_array('qualified', $statuses, true)) {
        if (in_array('already', $statuses, true)) {
            ++$alreadyCount;
        }
        continue;
    }

    $before = "{$tuple['user']} {$tuple['relation']} {$tuple['object']}";
    $after  = "{$newUser} {$tuple['relation']} {$newObject}";

    if ($dryRun) {
        ++$copiedCount;
        echo "[DRY RUN] {$before} → {$after}" . PHP_EOL;
        if ($prune) {
            echo "[DRY RUN] would then delete {$before}" . PHP_EOL;
        }
        continue;
    }

    try {
        $client->writeTuple($newUser, $tuple['relation'], $newObject);
        echo "[COPIED] {$before} → {$after}" . PHP_EOL;
    } catch (TupleAlreadyExistsException) {
        echo "[ALREADY EXISTS] {$after} — write skipped" . PHP_EOL;
    }
    ++$copiedCount;

    if (!$prune) {
        continue;
    }

    try {
        $client->deleteTuple($tuple['user'], $tuple['relation'], $tuple['object']);
        ++$prunedCount;
        echo "[PRUNED] {$before}" . PHP_EOL;
    } catch (TupleNotFoundException) {
        // Already removed by a previous run — benign.
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo PHP_EOL;
echo 'Summary:' . PHP_EOL;
echo sprintf("  Tuples read         : %d\n", count($allTuples));
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
