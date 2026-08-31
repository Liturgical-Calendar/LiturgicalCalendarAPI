#!/usr/bin/env php
<?php

/**
 * Guard the two structural invariants of the missals tree that nothing else enforces.
 *
 * Both were found the hard way (#939, #940) and both fail SILENTLY: neither produces an
 * error, a warning, or a log line — just a wrong calendar.
 *
 *   1. NAMING CONVENTION (#940). Every missal directory holds a data file named after
 *      itself: `{missal_folder}/{missal_folder}.json`. That rule is not folklore — it is
 *      already codified as {@see JsonData::MISSAL_FILE} / {@see JsonData::AMBROSIAN_MISSAL_FILE},
 *      and any consumer that discovers missals by walking the tree resolves paths with it.
 *      The Ambrosian sanctorale used to be spelled `propriumdesanctis_2024/propriumdesanctis.json`,
 *      so a resolver written against the convention returned a path that did not exist for
 *      exactly one missal — and the failure reads as "this missal has no data", not as an error.
 *
 *   2. EVENT_KEY IDENTITY (#939). A missal is a delta layer: later editions and national
 *      editions are merged over the editio typica by `event_key`, and
 *      `LiturgicalEventCollection::addLiturgicalEvent()` is keyed on that string alone. So a
 *      key that appears in two sanctorale files is a claim that both rows are the SAME saint.
 *      `StIsidore` was not: `propriumdesanctis_1970` meant Isidore of Seville (4 April) and
 *      `propriumdesanctis_US_2011` meant Isidore the Farmer (15 May). The US row silently
 *      overwrote Seville's, erasing him from the calendar of a country that celebrates him,
 *      and — because the sanctorale lectionary is keyed on `event_key` too — handing his
 *      readings to the wrong man.
 *
 *      Uniqueness here is therefore NOT "a key may appear only once in the tree". Re-declaring
 *      a key across missals is normal and correct: `StPeterClaver` is declared by the 2002
 *      editio typica, by IT_1983 and by US_2011, each with its own grade for its own calendar.
 *      What must hold is that every declaration of a key denotes the same saint, and the
 *      observable proxy for that is the calendar date. The rule implemented below is:
 *
 *          Within a rite, an event_key declared by more than one SANCTORALE missal file
 *          must carry the same month/day in every one of them.
 *
 *      All eight legitimate cross-missal re-declarations in the tree agree on the date;
 *      `StIsidore` was the sole disagreement.
 *
 *      Scoped to sanctorale files deliberately. The Ambrosian tree declares `Christmas`,
 *      `Circoncisione` and `Epiphany` in BOTH its sanctorale and its temporale — a real,
 *      known overlap that `CalendarHandler::addAmbrosianSanctoraleToCalendar()` guards
 *      explicitly, and that a temporale/sanctorale comparison would flag as a false positive.
 *
 *   3. NO ORPHAN SIDECAR KEYS. Each missal folder may carry `i18n/{locale}.json` and
 *      `lectionary/{locale}.json`, both keyed by the same `event_key`s as the structure file.
 *      A key present in a sidecar but absent from the structure file is dead weight at best,
 *      and at worst the residue of a half-finished rename — which is exactly how a #939
 *      recurrence would look while it was still recoverable.
 *
 * Usage:
 *   php scripts/lint-missals.php      (composer lint:missals)
 *
 * Exit codes:
 *   0  every missal folder follows the naming convention, every shared event_key agrees on
 *      its date, and no sidecar declares a key its structure file does not.
 *   1  at least one invariant is broken; every offending file is named on stderr.
 */

declare(strict_types=1);

// Refuse any entry that is not the CLI. These scripts ship to the server — they are run there per
// the RBAC runbook — and they sit under a path whose `.php` files are handed to php-fpm, so an HTTP
// request can reach them. Inlined per script rather than factored into a shared require: a guard
// that depends on resolving another path has a failure mode that a single constant comparison does
// not. See the same block in lint-jsondata.php and lint-locales.php.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Router;

// Initialize the file-path prefix that JsonData::path() requires.
// Router sets this during HTTP boot; CLI scripts must set it manually.
Router::$apiFilePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;

/** Directory-name prefix that marks a missal folder as a sanctorale (Proprium de Sanctis). */
const SANCTORALE_PREFIX = 'propriumdesanctis';

/** @var string[] $failures human-readable descriptions of every broken invariant */
$failures = [];

$missalFolderCount  = 0;
$sidecarFileCount   = 0;
$sanctoraleRowCount = 0;

/**
 * Decode a JSON file, or record a failure and return null.
 *
 * @param string[] $failures
 * @return mixed decoded value, or null when the file could not be read or parsed
 */
$decodeJson = static function (string $path, array &$failures): mixed {
    $raw = file_get_contents($path);
    if ($raw === false) {
        $failures[] = "could not read {$path}";
        return null;
    }
    try {
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        $failures[] = "could not parse {$path}: {$e->getMessage()}";
        return null;
    }
};

/** Render a path relative to the project root, so failure lines stay readable. */
$relative = static function (string $path): string {
    return str_starts_with($path, Router::$apiFilePath)
        ? substr($path, strlen(Router::$apiFilePath))
        : $path;
};

foreach (Rite::cases() as $rite) {
    $missalsFolder = JsonData::missalsFolderFor($rite)->path();
    if (!is_dir($missalsFolder)) {
        $failures[] = "the {$rite->value} rite declares a missals folder that does not exist: " . $relative($missalsFolder);
        continue;
    }

    $fileTemplate = JsonData::missalFileFor($rite)->value;

    /**
     * Sanctorale rows collected across this rite's missals, so cross-missal identity can be
     * checked per rite (keys are namespaced by rite: the Ambrosian tree has its own
     * `StIsidoreOfSeville`, unrelated to the Roman `StIsidore`).
     *
     * @var array<string,array<int,array{missal:string,month:mixed,day:mixed}>> $sanctoraleRows
     */
    $sanctoraleRows = [];

    $entries = scandir($missalsFolder);
    if ($entries === false) {
        $failures[] = 'could not list ' . $relative($missalsFolder);
        continue;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $missalFolder = $missalsFolder . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($missalFolder)) {
            continue;
        }

        ++$missalFolderCount;

        // ------------------------------------------------------------------
        // 1. {missal_folder}/{missal_folder}.json
        // ------------------------------------------------------------------
        $structureFile = Router::$apiFilePath . strtr($fileTemplate, ['{missal_folder}' => $entry]);

        if (!is_file($structureFile)) {
            $siblings   = array_values(array_filter(
                scandir($missalFolder) ?: [],
                static fn (string $f): bool => str_ends_with($f, '.json')
            ));
            $failures[] = sprintf(
                '%s: expected the data file %s (the {missal_folder}/{missal_folder}.json convention); found instead: %s',
                $relative($missalFolder),
                $entry . '.json',
                $siblings === [] ? '(no .json file at all)' : implode(', ', $siblings)
            );
            continue;
        }

        /** @var mixed $rows */
        $rows = $decodeJson($structureFile, $failures);
        if (!is_array($rows)) {
            if ($rows !== null) {
                $failures[] = $relative($structureFile) . ' did not decode to a JSON array of events';
            }
            continue;
        }

        /** @var array<int,string> $declaredKeys */
        $declaredKeys = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row) || !array_key_exists('event_key', $row) || !is_string($row['event_key'])) {
                $failures[] = $relative($structureFile) . ": row {$index} has no string event_key";
                continue;
            }
            $declaredKeys[] = $row['event_key'];

            if (str_starts_with($entry, SANCTORALE_PREFIX)) {
                ++$sanctoraleRowCount;
                $sanctoraleRows[$row['event_key']][] = [
                    'missal' => $entry,
                    'month'  => $row['month'] ?? null,
                    'day'    => $row['day'] ?? null,
                ];
            }
        }

        // ------------------------------------------------------------------
        // 3. Sidecars declare no key the structure file does not.
        // ------------------------------------------------------------------
        foreach (['i18n', 'lectionary'] as $sidecarFolderName) {
            $sidecarFolder = $missalFolder . DIRECTORY_SEPARATOR . $sidecarFolderName;
            if (!is_dir($sidecarFolder)) {
                continue;
            }
            foreach (glob($sidecarFolder . DIRECTORY_SEPARATOR . '*.json') ?: [] as $sidecarFile) {
                ++$sidecarFileCount;
                /** @var mixed $sidecar */
                $sidecar = $decodeJson($sidecarFile, $failures);
                if (!is_array($sidecar)) {
                    if ($sidecar !== null) {
                        $failures[] = $relative($sidecarFile) . ' did not decode to a JSON object';
                    }
                    continue;
                }
                $orphans = array_diff(array_map('strval', array_keys($sidecar)), $declaredKeys);
                if ($orphans !== []) {
                    $failures[] = sprintf(
                        '%s declares %d event_key(s) that %s does not: %s',
                        $relative($sidecarFile),
                        count($orphans),
                        $entry . '.json',
                        implode(', ', $orphans)
                    );
                }
            }
        }
    }

    // ----------------------------------------------------------------------
    // 2. A shared sanctorale event_key must mean the same saint everywhere,
    //    which shows up as the same month/day in every declaration.
    // ----------------------------------------------------------------------
    foreach ($sanctoraleRows as $eventKey => $declarations) {
        if (count($declarations) < 2) {
            continue;
        }
        $dates = [];
        foreach ($declarations as $declaration) {
            $dates[$declaration['month'] . '-' . $declaration['day']] = true;
        }
        if (count($dates) > 1) {
            $spelled    = implode(
                '; ',
                array_map(
                    static fn (array $d): string => sprintf('%s says %s-%s', $d['missal'], (string) $d['month'], (string) $d['day']),
                    $declarations
                )
            );
            $failures[] = sprintf(
                "rite '%s': event_key '%s' is declared by %d sanctorale missals on DIFFERENT dates (%s) — one event_key cannot denote two saints",
                $rite->value,
                $eventKey,
                count($declarations),
                $spelled
            );
        }
    }
}

if ($failures === []) {
    printf(
        "lint:missals OK — %d missal folder(s) follow {missal_folder}/{missal_folder}.json, %d sanctorale row(s) agree on their dates, %d sidecar file(s) declare no orphan keys.\n",
        $missalFolderCount,
        $sanctoraleRowCount,
        $sidecarFileCount
    );
    exit(0);
}

fwrite(STDERR, 'lint:missals FAILED — the missals tree breaks ' . count($failures) . " invariant(s):\n");
foreach ($failures as $failure) {
    fwrite(STDERR, "  - {$failure}\n");
}
fwrite(
    STDERR,
    "\nNaming: every missal directory must contain a data file named after the directory —\n"
    . "{missal_folder}/{missal_folder}.json. That is what JsonData::MISSAL_FILE and\n"
    . "JsonData::AMBROSIAN_MISSAL_FILE resolve to, and what any tree-walking consumer expects.\n"
    . "Rename the file (not the directory: the directory name carries the edition year).\n"
    . "\nEvent keys: an event_key shared by two sanctorale missals is a claim that both rows are\n"
    . "the same saint, because the calendar merges missal layers by that key alone. Re-declaring a\n"
    . "key to change a saint's grade for a national calendar is fine — the date must match. If the\n"
    . "two rows are different saints, give the newer one its own key (as StIsidoreFarmer was split\n"
    . "from StIsidore in #939) and rename it in the structure file AND in every i18n/ and\n"
    . "lectionary/ sidecar of that missal.\n"
    . "\nOrphan sidecar keys: an i18n or lectionary entry whose event_key no longer exists in the\n"
    . "structure file is usually a rename that stopped half-way. Finish the rename, or drop the entry.\n"
);
exit(1);
