<?php

/**
 * Fill the `pages` maps of `lectionary_sources.json` from a source-page map (#1074).
 *
 * The input is the format the passes filling #1072 recorded in the #1074 map comment: a JSON
 * object keyed `section/event_key` (the lectionary folder the readings sit in), each value an
 * object with an optional `usccb` and an optional `cei` leftover. The sidecar keys pages by
 * bare event_key, because the calendar engine flattens the sections: a weekday of Ordinary
 * Time appears in both `feriale_per_annum_I` and `feriale_per_annum_II`, and a Sunday in the
 * three Sunday cycles. So the section becomes a cycle key:
 *
 *   feriale_per_annum_I/X            → pages[X]["I"]
 *   feriale_per_annum_II/X           → pages[X]["II"]
 *   dominicale_et_festivum_{A,B,C}/X → pages[X]["A" | "B" | "C"]
 *   any other section/X              → pages[X], the value as recorded (a string, or an object
 *                                      already keyed by weekday cycle, as for St Blase)
 *
 * Usage:
 *   php scripts/build-lectionary-sources-pages.php MAP.json           dry run: print a summary
 *   php scripts/build-lectionary-sources-pages.php MAP.json --apply   rewrite the pages maps
 *
 * Exit codes: 0 done; 1 bad input or a conflict (two sections giving one key different values).
 */

declare(strict_types=1);

// Refuse any entry that is not the CLI (see lint-missals.php for why this is inlined).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\JsonFormatter;
use LiturgicalCalendar\Api\Router;

Router::$apiFilePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;

$mapFile = $argv[1] ?? null;
$apply   = in_array('--apply', $argv, true);
if ($mapFile === null || !is_file($mapFile)) {
    fwrite(STDERR, "Usage: php scripts/build-lectionary-sources-pages.php MAP.json [--apply]\n");
    exit(1);
}

$map = json_decode((string) file_get_contents($mapFile), true);
if (!is_array($map)) {
    fwrite(STDERR, "{$mapFile} is not a JSON object\n");
    exit(1);
}

$sidecarPath = JsonData::LECTIONARY_SOURCES_FILE->path();
$sidecar     = json_decode((string) file_get_contents($sidecarPath), true);
if (!is_array($sidecar)) {
    fwrite(STDERR, "{$sidecarPath} is not a JSON object\n");
    exit(1);
}

/** @var array<string, array<string, string|array<string, string>>> $pages */
$pages     = [];
$conflicts = [];

foreach ($map as $sectionKey => $leftovers) {
    if (!is_array($leftovers) || substr_count((string) $sectionKey, '/') !== 1) {
        $conflicts[] = "malformed entry {$sectionKey}";
        continue;
    }
    [$section, $eventKey] = explode('/', (string) $sectionKey);
    $cycle                = match (true) {
        $section === 'feriale_per_annum_I'                      => 'I',
        $section === 'feriale_per_annum_II'                     => 'II',
        str_starts_with($section, 'dominicale_et_festivum_')    => substr($section, -1),
        default                                                 => null,
    };
    foreach ($leftovers as $source => $value) {
        if (!isset($sidecar[$source])) {
            $conflicts[] = "{$sectionKey}: unknown source {$source}";
            continue;
        }
        $current = $pages[$source][$eventKey] ?? null;
        if ($cycle === null) {
            if ($current !== null && $current !== $value) {
                $conflicts[] = "{$source}/{$eventKey}: " . json_encode($current) . ' vs ' . json_encode($value) . " (from {$section})";
                continue;
            }
            $pages[$source][$eventKey] = $value;
            continue;
        }
        if (!is_string($value) || ( $current !== null && !is_array($current) )) {
            $conflicts[] = "{$source}/{$eventKey}: a cycle value from {$section} next to a plain value";
            continue;
        }
        $merged         = is_array($current) ? $current : [];
        $merged[$cycle] = $value;
        ksort($merged);
        $pages[$source][$eventKey] = $merged;
    }
}

if ($conflicts !== []) {
    fwrite(STDERR, "Conflicts:\n  " . implode("\n  ", $conflicts) . "\n");
    exit(1);
}

foreach ($sidecar as $source => $definition) {
    $sourcePages = $pages[$source] ?? [];
    ksort($sourcePages);
    $byCycle = count(array_filter($sourcePages, 'is_array'));
    echo "{$source}: " . count($sourcePages) . " pages ({$byCycle} keyed by cycle)\n";
    if (is_array($definition)) {
        $definition['pages'] = (object) $sourcePages;
        $sidecar[$source]    = $definition;
    }
}

if (!$apply) {
    echo "Dry run: pass --apply to write {$sidecarPath}\n";
    exit(0);
}

ksort($sidecar);
file_put_contents($sidecarPath, JsonFormatter::encode($sidecar) . PHP_EOL);
echo "Wrote {$sidecarPath}\n";
