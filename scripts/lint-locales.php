#!/usr/bin/env php
<?php

/**
 * Guard the promise made by jsondata/supportedLocales.json: every locale this
 * repository declares officially supported must actually have the resources an
 * official locale requires.
 *
 * Why this is a build gate and not merely a report: declaring a locale official
 * is not a label change, it changes behaviour. `ReadingsMap::getReadings()`
 * throws for an official locale and degrades quietly for every other one
 * (#904), so a commit that removes a lectionary corpus, or empties a decreed
 * event's name, turns a silent gap into a 500 on the next deploy — for a
 * calendar the API advertises as supported. That is precisely the failure that
 * took the Croatian calendar down, discovered in production rather than in CI.
 *
 * Three checks, in order of what they can hide:
 *
 *   1. The curated resource parses, and the list the API is actually using came
 *      FROM it. {@see SupportedLocales} falls back to a built-in list when the
 *      resource cannot be read — correct at runtime, but in CI it would mean
 *      silently guarding a list nobody wrote. A guard that can pass without
 *      reading the file it exists to guard is not a guard.
 *   2. Every official locale is ready, via {@see LocaleReadinessChecker}.
 *   3. Advisory only, never gating: any locale recorded in the resource's
 *      `candidates` block that now passes every probe, i.e. is promotable.
 *      Completing a candidate's data is an improvement, so it must never turn
 *      the build red — it just should not go unnoticed either.
 *
 * The same assertion is also made from PHPUnit
 * (phpunit_tests/Services/Locale/LocaleReadinessCheckerTest.php), which pins the
 * checker's behaviour. This script is the data gate: it runs in seconds without
 * a database or a PHPUnit bootstrap, it is what an editor runs locally before
 * touching lectionary or decrees data, and it names the exact files and event
 * keys that are missing rather than an assertion diff.
 *
 * Usage:
 *   php scripts/lint-locales.php      (composer lint:locales)
 *
 * Exit codes:
 *   0  every officially supported locale has every required resource.
 *   1  the curated resource is unusable, or at least one official locale is incomplete.
 */

declare(strict_types=1);

// Refuse any entry that is not the CLI. These scripts ship to the server — they are run there per
// the RBAC runbook — and they sit under a path whose `.php` files are handed to php-fpm, so an HTTP
// request can reach them. Inlined per script rather than factored into a shared require: a guard
// that depends on resolving another path has a failure mode that a single constant comparison does
// not. See the same block in lint-jsondata.php.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\Locale\LocaleReadinessChecker;
use LiturgicalCalendar\Api\Services\Locale\LocaleReadinessReport;
use LiturgicalCalendar\Api\Services\SupportedLocales;

// Initialize the file-path prefix that JsonData::path() requires.
// Router sets this during HTTP boot; CLI scripts must set it manually.
Router::$apiFilePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;

$fail = static function (string $message): never {
    fwrite(STDERR, 'lint:locales FAILED — ' . $message . "\n");
    exit(1);
};

// ---------------------------------------------------------------------------
// 1. The curated resource itself.
// ---------------------------------------------------------------------------

$resourcePath = JsonData::SUPPORTED_LOCALES_FILE->path();

if (!is_file($resourcePath)) {
    $fail(JsonData::SUPPORTED_LOCALES_FILE->value . " is missing.\n"
        . "It is the single source of truth for which locales the API promises to serve completely.\n"
        . 'Without it every deployment silently falls back to the built-in list in SupportedLocales::FALLBACK.');
}

$raw = file_get_contents($resourcePath);
if (false === $raw) {
    $fail(JsonData::SUPPORTED_LOCALES_FILE->value . ' could not be read.');
}

try {
    /** @var mixed $resource */
    $resource = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    $fail(JsonData::SUPPORTED_LOCALES_FILE->value . ' is not valid JSON: ' . $e->getMessage());
}

if (!is_array($resource) || !isset($resource['general_roman_calendar']) || !is_array($resource['general_roman_calendar'])) {
    $fail(JsonData::SUPPORTED_LOCALES_FILE->value . ' has no general_roman_calendar object.');
}

$grc      = $resource['general_roman_calendar'];
$declared = isset($grc['official']) && is_array($grc['official'])
    ? array_values(array_filter($grc['official'], static fn (mixed $v): bool => is_string($v) && $v !== ''))
    : [];

if ($declared === []) {
    $fail(JsonData::SUPPORTED_LOCALES_FILE->value . " declares no official locales.\n"
        . 'An empty list makes every locale unofficial, which disables strict serving everywhere rather than nowhere.');
}

// The list the code is actually using must be the list on disk. If it is not, the
// service fell back, and every check below would be verifying a list nobody curated.
$inUse = SupportedLocales::official();
if ($inUse !== $declared) {
    $fail("the declared official locales and the ones SupportedLocales is using differ.\n"
        . '  declared in ' . JsonData::SUPPORTED_LOCALES_FILE->value . ': ' . implode(', ', $declared) . "\n"
        . '  SupportedLocales::official():                    ' . implode(', ', $inUse) . "\n"
        . 'This means the resource could not be read and the built-in fallback was used.');
}

// ---------------------------------------------------------------------------
// 2. Every official locale is complete. This is the gate.
// ---------------------------------------------------------------------------

$checker = new LocaleReadinessChecker();
$reports = $checker->checkOfficialLocales();

/** @var list<LocaleReadinessReport> $incomplete */
$incomplete = array_values(array_filter(
    $reports,
    static fn (LocaleReadinessReport $report): bool => !$report->ready()
));

foreach ($reports as $report) {
    echo '  ' . $report->describe() . "\n";
}

if ($incomplete !== []) {
    fwrite(STDERR, "\nlint:locales FAILED — the following officially supported locale(s) are incomplete:\n");
    foreach ($incomplete as $report) {
        fwrite(STDERR, '  ' . $report->locale . ":\n");
        foreach ($report->failures() as $check) {
            fwrite(STDERR, '    - ' . $check->name . ': ' . $check->summary . "\n");
            foreach ($check->missing as $missing) {
                fwrite(STDERR, '        ' . $missing . "\n");
            }
        }
    }
    fwrite(
        STDERR,
        "\nAn officially supported locale is served STRICTLY: a missing readings entry throws rather than\n"
        . "degrading, so this will answer 500 in production for the affected events, not just render a gap.\n"
        . "\nThere are exactly two correct fixes, and choosing between them is a governance decision:\n"
        . "  - supply the missing data listed above, or\n"
        . '  - remove the locale from the "official" list in ' . JsonData::SUPPORTED_LOCALES_FILE->value . ",\n"
        . "    which restores graceful degradation for it and reduces the published contract.\n"
        . "\nDo not relax the checker to make this pass: every probe here corresponds to something the\n"
        . "calendar reads at request time.\n"
    );
    exit(1);
}

// ---------------------------------------------------------------------------
// 3. Advisory: candidates that have become promotable, and advisory probes that
//    an official locale does not meet. Neither is ever gating.
// ---------------------------------------------------------------------------

$candidates = isset($grc['candidates']) && is_array($grc['candidates']) ? $grc['candidates'] : [];
$promotable = [];
foreach (array_keys($candidates) as $candidate) {
    $locale = (string) $candidate;
    if ($checker->check($locale)->ready()) {
        $promotable[] = $locale;
    }
}

if ($promotable !== []) {
    echo "\nNote — the following candidate locale(s) now pass every readiness probe and could be promoted:\n";
    foreach ($promotable as $locale) {
        echo '  - ' . $locale . "\n";
    }
    echo "Promotion is a governance decision, so this is a note, not a failure. Add the locale to the\n"
        . '"official" list in ' . JsonData::SUPPORTED_LOCALES_FILE->value . " and drop its candidates entry.\n";
}

$advisories = [];
foreach ($reports as $report) {
    foreach ($report->advisories() as $check) {
        $advisories[] = '  - ' . $report->locale . ': ' . $check->name . ' — ' . $check->summary;
    }
}

if ($advisories !== []) {
    echo "\nAdvisory (never gating) — content gaps in locales that are otherwise complete:\n"
        . implode("\n", $advisories) . "\n";
}

echo "\nlint:locales OK — all " . count($reports) . ' officially supported locale(s) ('
    . implode(', ', $inUse) . ") have every required resource.\n";
exit(0);
