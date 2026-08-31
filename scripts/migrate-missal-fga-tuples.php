#!/usr/bin/env php
<?php

/**
 * Idempotent migration: rite-qualify the OpenFGA object ids of missal-edition tuples on the
 * `general_roman_calendar` type (issue #953).
 *
 *   general_roman_calendar:EDITIO_TYPICA_1970 → general_roman_calendar:roman/EDITIO_TYPICA_1970
 *   general_roman_calendar:EDITIO_TYPICA_2002 → general_roman_calendar:roman/EDITIO_TYPICA_2002
 *   general_roman_calendar:EDITIO_TYPICA_2008 → general_roman_calendar:roman/EDITIO_TYPICA_2008
 *
 * Only ids that name a Roman typical edition are touched. `temporale`, `decrees` and
 * `supported_locales` are also `general_roman_calendar` ids, but they are not calendars — they
 * stay bare by design (see the class docblock on {@see \LiturgicalCalendar\Api\Services\RiteScopedObjectId}).
 * `national_calendar` ids for national missal editions (e.g. `national_calendar:roman/IT`) are
 * already rite-qualified — they went through `migrate-rite-data-tuples.php` under #786, since
 * that migration qualified every `national_calendar` tuple regardless of what put it there.
 *
 * **THIS MUST RUN BEFORE THE #953 CODE DEPLOYS, NOT AFTER.** {@see
 * \LiturgicalCalendar\Api\Http\Middleware\OpenFgaAuthorizationMiddleware::forMissals()} fails
 * closed: the moment the deployed code asks OpenFGA to check
 * `general_roman_calendar:roman/EDITIO_TYPICA_1970`, a store that still only holds the
 * unqualified `general_roman_calendar:EDITIO_TYPICA_1970` grant denies every typical-edition
 * missal write, for every existing editor, with no warning beyond a 403. This script is what
 * makes the qualified tuple exist ahead of time. It is additive — it writes the qualified tuple
 * and leaves the unqualified one in place — so it is safe to run against a store the
 * pre-#953 code is still serving from: nothing pre-#953 asks OpenFGA about a `/`-qualified id, so
 * the new tuple is inert until the new code ships. Deployment order is therefore:
 *
 *   1. Run this script with --apply.
 *   2. Verify with `--dry-run` (the default) that a second run reports nothing left to migrate.
 *   3. Deploy the #953 code.
 *
 * The unqualified tuple is deliberately left in place rather than pruned, so a rollback to
 * pre-#953 code keeps authorizing every existing editor. There is no `--prune` flag here (unlike
 * `migrate-rite-data-tuples.php`): pruning would need its own follow-up once every deployment
 * runs merged #953 code, which is out of scope for this script.
 *
 * Usage:
 *   php scripts/migrate-missal-fga-tuples.php [--dry-run|--apply]
 *
 * Flags:
 *   --dry-run  (default) Print what WOULD be done without touching the store.
 *   --apply             Write the rite-qualified tuples in OpenFGA.
 *
 * Safety guarantees:
 *   - Writing a tuple that already exists is benign (TupleAlreadyExistsException).
 *   - Already-qualified ids (containing `/`) are recognised and skipped.
 *   - Non-missal `general_roman_calendar` ids (`temporale`, `decrees`, `supported_locales`,
 *     anything else not a Roman typical edition) are left untouched.
 *   - Safe to re-run after a partial migration, and safe to run more than once after a complete
 *     one (every tuple it would write already exists, so it is a no-op).
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
use LiturgicalCalendar\Api\Enum\MissalCatalog;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Services\Exception\TupleAlreadyExistsException;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\RiteScopedObjectId;

/** The only object type this migration touches. */
const MISSAL_OBJECT_TYPE = 'general_roman_calendar';

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
$dryRun = !$apply;

echo 'Mode: ' . ( $dryRun ? 'DRY RUN (pass --apply to apply changes)' : 'APPLY' ) . PHP_EOL;
echo PHP_EOL;

// ---------------------------------------------------------------------------
// Dependency setup
// ---------------------------------------------------------------------------
if (!OpenFgaClient::isConfigured()) {
    fwrite(STDERR, "Error: OpenFGA is not configured. Set OPENFGA_API_URL, OPENFGA_STORE_ID, and OPENFGA_MODEL_ID.\n");
    exit(1);
}

$client = OpenFgaClient::fromEnv();

/**
 * Whether an id names a Roman typical edition — the only `general_roman_calendar` ids this
 * migration qualifies. `temporale`, `decrees`, `supported_locales` and any other fixed id on
 * this type are not calendars and stay bare; `isEditioTypica()` already answers false for them.
 */
$isRomanTypicalEdition = static function (string $id): bool {
    return MissalCatalog::for(Rite::ROMAN)->isEditioTypica($id);
};

// ---------------------------------------------------------------------------
// Enumerate every tuple (paginated). Read with no filter and select in app code, mirroring the
// sibling migration scripts: the type-only object filter is not reliably valid per the OpenFGA
// Read API spec.
// ---------------------------------------------------------------------------
/** @var list<array{user: string, relation: string, object: string}> $allTuples */
$allTuples         = [];
$continuationToken = null;

do {
    $page              = $client->readTuples('', '', null, null, $continuationToken);
    $allTuples         = array_merge($allTuples, $page['tuples']);
    $continuationToken = $page['next_continuation_token'] !== '' ? $page['next_continuation_token'] : null;
} while ($continuationToken !== null);

$qualifiedCount = 0;
$alreadyCount   = 0;
$notMissalCount = 0;

foreach ($allTuples as $tuple) {
    $colon = strpos($tuple['object'], ':');
    if ($colon === false) {
        continue;
    }

    $type = substr($tuple['object'], 0, $colon);
    $id   = substr($tuple['object'], $colon + 1);

    if ($type !== MISSAL_OBJECT_TYPE) {
        continue;
    }

    if (str_contains($id, RiteScopedObjectId::SEPARATOR)) {
        ++$alreadyCount;
        continue;
    }

    if (!$isRomanTypicalEdition($id)) {
        // temporale, decrees, supported_locales, or any other non-missal fixed id: untouched by
        // design — these are not calendars and are Roman by construction.
        ++$notMissalCount;
        continue;
    }

    $newObject = MISSAL_OBJECT_TYPE . ':' . RiteScopedObjectId::qualify(Rite::ROMAN, $id);
    $before    = "{$tuple['user']} {$tuple['relation']} {$tuple['object']}";
    $after     = "{$tuple['user']} {$tuple['relation']} {$newObject}";

    if ($dryRun) {
        ++$qualifiedCount;
        echo "[DRY RUN] {$before} → {$after}" . PHP_EOL;
        continue;
    }

    try {
        $client->writeTuple($tuple['user'], $tuple['relation'], $newObject);
        echo "[COPIED] {$before} → {$after}" . PHP_EOL;
    } catch (TupleAlreadyExistsException) {
        echo "[ALREADY EXISTS] {$after} — write skipped" . PHP_EOL;
    }
    ++$qualifiedCount;
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo PHP_EOL;
echo 'Summary:' . PHP_EOL;
echo sprintf("  Tuples read              : %d\n", count($allTuples));
echo sprintf("  %-25s: %d\n", $dryRun ? 'Would qualify' : 'Qualified', $qualifiedCount);
echo sprintf("  %-25s: %d\n", 'Already qualified', $alreadyCount);
echo sprintf("  %-25s: %d\n", 'Not a missal (untouched)', $notMissalCount);

echo PHP_EOL;
exit(0);
