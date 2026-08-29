#!/usr/bin/env php
<?php

/**
 * Guard against source-data churn: re-encode every guarded file through the
 * exact canonicalizer that owns its format, and fail if the committed file
 * differs from that canonical form.
 *
 * Two families are covered, for two different reasons:
 *   - the decrees source files, whose canonicalizer is their write-path handler
 *   - jsondata/schemas/openapi.json, whose canonicalizer is a textual escaping
 *     rule (#907); see the comment above $canonicalOpenApi for why it is not a
 *     re-encode and why the direction is literal rather than escaped.
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

// Canonical encoding for openapi.json: non-ASCII written literally, never as
// \uXXXX, and nothing else touched.
//
// The direction is not arbitrary — two independent authorities agree on it:
//
//   1. Redocly, the tool that lints and bundles this document, emits literal
//      UTF-8 with two-space indentation. `redocly bundle` on this file produces
//      835 literal non-ASCII characters and zero escapes. Escaping would put the
//      file permanently at odds with its own toolchain.
//   2. Every other schema in jsondata/schemas is already literal — CommonDef.json
//      alone holds 720 non-ASCII characters and zero escapes.
//
// It also just reads better: the file carries Japanese liturgical titles, and
// `\u30a8\u30b3...` tells a reviewer nothing.
//
// Deliberately textual rather than a re-encode. JsonFormatter::encode() emits
// four-space indentation, while this document is two-space per the OpenAPI
// convention; canonicalising through it would rewrite the whole file (534 KB to
// 672 KB) for no gain.
//
// What this actually guards (#907) is MIXED escaping. openapi.json carried the em
// dash both ways, 58 escaped and 6 literal, so no encoder could reproduce it:
// round-tripping rewrote unrelated descriptions whichever setting was used, and
// one attempt produced a 13,919-line diff for what should have been a 220-line
// addition. One convention makes the file reproducible again.
//
// Control characters, quote, backslash and solidus are left alone: JSON requires
// the first to stay escaped, and the others are never emitted as \uXXXX here.
$canonicalOpenApi = static function (string $raw): string {
    $unescaped = preg_replace_callback(
        '/\\\\u([0-9a-fA-F]{4})/',
        static function (array $m): string {
            $codepoint = (int) hexdec($m[1]);

            if ($codepoint < 0x20 || in_array($codepoint, [0x22, 0x5C, 0x2F], true)) {
                return $m[0];
            }

            // Lone surrogate halves cannot stand alone as characters; leave the
            // pair escaped rather than producing invalid UTF-8.
            if ($codepoint >= 0xD800 && $codepoint <= 0xDFFF) {
                return $m[0];
            }

            return mb_chr($codepoint, 'UTF-8');
        },
        $raw
    );

    if (!is_string($unescaped)) {
        throw new \RuntimeException('openapi.json could not be scanned for escaped characters');
    }

    return $unescaped;
};

$decreesFile     = JsonData::DECREES_FILE->path();
$i18nFiles       = glob(JsonData::DECREES_I18N_FOLDER->path() . '/*.json') ?: [];
$lectionaryFiles = glob(JsonData::LECTIONARY_DECREES_FOLDER->path() . '/*.json') ?: [];

/** @var array<int,array{path:string,canonicalize:\Closure(string):string}> $checks */
$checks = [
    ['path' => $decreesFile, 'canonicalize' => $canonicalDecreesFile],
    ['path' => JsonData::SCHEMAS_FOLDER->path() . '/openapi.json', 'canonicalize' => $canonicalOpenApi],
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
    echo 'lint:jsondata OK — ' . count($checks) . " file(s) match their canonical encoding.\n";
    exit(0);
}

fwrite(STDERR, "lint:jsondata FAILED — the following file(s) are not in their canonical encoding:\n");
foreach ($drifted as $path) {
    $rel = str_starts_with($path, Router::$apiFilePath) ? substr($path, strlen(Router::$apiFilePath)) : $path;
    fwrite(STDERR, "  - {$rel}\n");
}
fwrite(
    STDERR,
    "\nFor the decrees source files this means a future write through the decrees admin API (DecreesHandler)\n"
    . "though no data changed, appearing as spurious diffs. Fix by re-running the normalizer that produced these\n"
    . "exact rules: re-encode decrees.json via JsonFormatter::encode(array_values(\$decoded)) . PHP_EOL, and each\n"
    . "i18n/*.json / lectionary/*.json sidecar via ksort(\$decoded); JsonFormatter::encode(\$decoded) . PHP_EOL,\n"
    . "then commit the result.\n"
    . "\nFor jsondata/schemas/openapi.json the rule is different and much simpler: non-ASCII characters must be\n"
    . "written LITERALLY, never as \\uXXXX escapes. That matches what `redocly bundle` emits and what every\n"
    . "other schema in this folder already does. Mixed escaping is what made the document impossible to\n"
    . "round-trip without rewriting unrelated lines (#907). Replace any escape with the character it denotes.\n"
    . "\nDo not hand-edit formatting beyond that — let the canonicalizer produce the bytes.\n"
);
exit(1);
