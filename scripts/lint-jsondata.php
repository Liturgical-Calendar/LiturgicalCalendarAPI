#!/usr/bin/env php
<?php

/**
 * Guard against source-data churn: re-encode every decrees source file
 * through the exact canonicalizer its write-path handler uses, and fail if
 * the committed file differs from that canonical form.
 *
 * Background: DecreesHandler's write path always re-encodes what it writes
 * (JsonFormatter::encode(), plus ksort() for the i18n/lectionary sidecars).
 * If a committed source file isn't already in that canonical form, every
 * write-path request that happens to touch it produces a spurious diff —
 * for months this was misdiagnosed as "whitespace churn". This script
 * catches that class of drift in CI before it lands, and points at the fix.
 *
 * Usage:
 *   php scripts/lint-jsondata.php
 *
 * Exit codes:
 *   0  every checked file already matches its canonical encoding.
 *   1  at least one file has drifted; drifting files are named on stdout.
 *
 * IMPORTANT — scope:
 *   This script currently covers ONLY the decrees family
 *   (jsondata/sourcedata/rite/roman/decrees/**), whose canonical form is
 *   verified against DecreesHandler::saveDecreesDatabase(), ::distributeI18n(),
 *   and ::distributeReadings(). A survey found the canonical form is NOT
 *   uniform across jsondata (e.g. TestsHandler writes tests with
 *   JsonFormatter::encode($payload, false) — escaped unicode, no trailing
 *   newline — a different rule entirely). Do NOT widen the glob below to
 *   "all of jsondata" without first verifying the canonical form file-family
 *   by file-family; a single global rule would just create the same churn
 *   somewhere new.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\JsonFormatter;
use LiturgicalCalendar\Api\Router;

// Initialize the file-path prefix that JsonData::path() requires.
// Router sets this during HTTP boot; CLI scripts must set it manually.
Router::$apiFilePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;

// Canonical encoding for decrees.json: a re-indexed JSON list, no key sorting
// (it's a list, not a map). Mirrors DecreesHandler::saveDecreesDatabase().
$canonicalDecreesFile = static function (string $raw): string {
    /** @var mixed $decoded */
    $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new \RuntimeException('decrees.json did not decode to a JSON array');
    }
    return JsonFormatter::encode(array_values($decoded)) . PHP_EOL;
};

// Canonical encoding for decrees i18n/lectionary sidecars: key-sorted map.
// Mirrors DecreesHandler::distributeI18n() and ::distributeReadings().
$canonicalDecreesSidecar = static function (string $raw): string {
    /** @var array<string,mixed> $decoded */
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new \RuntimeException('sidecar file did not decode to a JSON object');
    }
    ksort($decoded);
    return JsonFormatter::encode($decoded) . PHP_EOL;
};

$decreesFile     = JsonData::DECREES_FILE->path();
$i18nFiles       = glob(JsonData::DECREES_I18N_FOLDER->path() . '/*.json') ?: [];
$lectionaryFiles = glob(JsonData::LECTIONARY_DECREES_FOLDER->path() . '/*.json') ?: [];

/** @var array<int,array{path:string,canonicalize:\Closure(string):string}> $checks */
$checks = [
    ['path' => $decreesFile, 'canonicalize' => $canonicalDecreesFile],
];
foreach ($i18nFiles as $f) {
    $checks[] = ['path' => $f, 'canonicalize' => $canonicalDecreesSidecar];
}
foreach ($lectionaryFiles as $f) {
    $checks[] = ['path' => $f, 'canonicalize' => $canonicalDecreesSidecar];
}

/** @var string[] $drifted paths whose on-disk content differs from its canonical encoding */
$drifted = [];
foreach ($checks as $check) {
    $path = $check['path'];
    $raw  = file_get_contents($path);
    if ($raw === false) {
        fwrite(STDERR, "Could not read {$path}\n");
        $drifted[] = $path;
        continue;
    }
    try {
        $canonical = ( $check['canonicalize'] )($raw);
    } catch (\Throwable $e) {
        fwrite(STDERR, "Could not canonicalize {$path}: {$e->getMessage()}\n");
        $drifted[] = $path;
        continue;
    }
    if ($raw !== $canonical) {
        $drifted[] = $path;
    }
}

if ($drifted === []) {
    echo 'lint:jsondata OK — ' . count($checks) . " decrees source file(s) match their write-path canonical encoding.\n";
    exit(0);
}

fwrite(STDERR, "lint:jsondata FAILED — the following file(s) are not in their write-path canonical encoding:\n");
foreach ($drifted as $path) {
    $rel = str_starts_with($path, Router::$apiFilePath) ? substr($path, strlen(Router::$apiFilePath)) : $path;
    fwrite(STDERR, "  - {$rel}\n");
}
fwrite(
    STDERR,
    "\nThis means a future write through the decrees admin API (DecreesHandler) would rewrite these files even\n"
    . "though no data changed, appearing as spurious diffs. Fix by re-running the normalizer that produced these\n"
    . "exact rules: re-encode decrees.json via JsonFormatter::encode(array_values(\$decoded)) . PHP_EOL, and each\n"
    . "i18n/*.json / lectionary/*.json sidecar via ksort(\$decoded); JsonFormatter::encode(\$decoded) . PHP_EOL,\n"
    . "then commit the result. Do not hand-edit formatting — let the canonicalizer produce the bytes.\n"
);
exit(1);
