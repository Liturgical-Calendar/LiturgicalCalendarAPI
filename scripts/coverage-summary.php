#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Read a clover XML file and emit a Markdown-formatted coverage summary
 * (project totals + per-layer breakdown of src/) on stdout.
 *
 * Intended for piping into $GITHUB_STEP_SUMMARY in the CI workflow so the
 * coverage numbers are visible directly on the run's summary page without
 * digging into Codecov.
 *
 * Usage:
 *   php scripts/coverage-summary.php <clover.xml> [<srcRoot>]
 *
 * - <clover.xml>: clover report to read.
 * - <srcRoot>:    optional; absolute path to src/ used to bucket files by
 *                 their top-level subdirectory (Handlers, Models, …). Defaults
 *                 to <repo>/src.
 */

if ($argc < 2 || $argc > 3) {
    fwrite(STDERR, "Usage: {$argv[0]} <clover.xml> [<srcRoot>]\n");
    exit(1);
}

$cloverPath = $argv[1];
$srcRoot    = $argv[2] ?? realpath(__DIR__ . '/../src');

if (!is_string($srcRoot) || !is_dir($srcRoot)) {
    fwrite(STDERR, 'src root not found: ' . var_export($srcRoot, true) . "\n");
    exit(1);
}
if (!is_file($cloverPath) || !is_readable($cloverPath)) {
    fwrite(STDERR, "Missing or unreadable clover: $cloverPath\n");
    exit(1);
}

$xml = simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "Failed to parse clover XML: $cloverPath\n");
    exit(1);
}

$srcRootReal = rtrim($srcRoot, '/');

/** @var array<string,array{stmts:int,covered:int,files:int}> $buckets */
$buckets    = [];
$totalStmts = 0;
$totalCov   = 0;
$totalFiles = 0;

$ingest = function (\SimpleXMLElement $fileEl) use (&$buckets, &$totalStmts, &$totalCov, &$totalFiles, $srcRootReal): void {
    $name = (string) $fileEl['name'];
    if ($name === '' || strpos($name, $srcRootReal) !== 0) {
        return;
    }
    $rel    = ltrim(substr($name, strlen($srcRootReal)), '/');
    $parts  = explode('/', $rel);
    $bucket = ( count($parts) <= 1 ) ? '(root)' : $parts[0];

    $m       = $fileEl->metrics;
    $stmts   = (int) $m['statements'];
    $covered = (int) $m['coveredstatements'];

    if (!isset($buckets[$bucket])) {
        $buckets[$bucket] = ['stmts' => 0, 'covered' => 0, 'files' => 0];
    }
    $buckets[$bucket]['stmts']   += $stmts;
    $buckets[$bucket]['covered'] += $covered;
    $buckets[$bucket]['files']++;
    $totalStmts += $stmts;
    $totalCov   += $covered;
    $totalFiles++;
};

foreach ($xml->project->package as $pkg) {
    foreach ($pkg->file as $fileEl) {
        $ingest($fileEl);
    }
}
foreach ($xml->project->file as $fileEl) {
    $ingest($fileEl);
}

ksort($buckets);

$pct = static fn (int $covered, int $stmts): string => $stmts > 0
    ? sprintf('%.1f%%', 100 * $covered / $stmts)
    : '—';

echo "## Coverage report\n\n";

echo sprintf(
    "**Project total: %s** (%d / %d statements covered across %d files)\n\n",
    $pct($totalCov, $totalStmts),
    $totalCov,
    $totalStmts,
    $totalFiles
);

echo "| Layer | Files | Statements | Covered | Coverage |\n";
echo "| :--- | ---: | ---: | ---: | ---: |\n";
foreach ($buckets as $name => $b) {
    echo sprintf(
        "| `%s` | %d | %d | %d | %s |\n",
        $name,
        $b['files'],
        $b['stmts'],
        $b['covered'],
        $pct($b['covered'], $b['stmts'])
    );
}
