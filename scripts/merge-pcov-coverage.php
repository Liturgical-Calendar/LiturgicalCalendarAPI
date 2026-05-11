<?php

declare(strict_types=1);

/**
 * Merge per-request pcov dumps produced by the dev-server instrumentation hook
 * in public/index.php into a clover report produced by PHPUnit's
 * --coverage-clover flag, and emit a unified clover file.
 *
 * Usage:
 *   php scripts/merge-pcov-coverage.php <phpunit-clover.xml> <pcov-dump-dir> <out-clover.xml>
 *
 * - <phpunit-clover.xml>: the clover file PHPUnit generated for in-process tests.
 * - <pcov-dump-dir>:     directory of *.cov files written by public/index.php
 *                        (each file is `serialize(\pcov\collect())`).
 * - <out-clover.xml>:    where to write the merged clover.
 *
 * Behavior:
 *   For each pcov dump, the script union-merges the set of lines marked as
 *   "executed" (state === 1) into the count attribute of the corresponding
 *   `<line>` element in the clover. File-level `<metrics>` are recomputed.
 *   Package and project metrics are recomputed too.
 *
 *   Files / lines that appear in pcov data but NOT in the clover are skipped
 *   silently — the clover defines the authoritative set of executable lines
 *   (driven by PHPUnit's source filter), and a divergence in pcov-seen lines
 *   indicates either dead code or a file outside `<source><include>`.
 */

if ($argc !== 4) {
    fwrite(STDERR, "Usage: {$argv[0]} <phpunit-clover.xml> <pcov-dump-dir> <out-clover.xml>\n");
    exit(1);
}

[$_, $cloverIn, $pcovDir, $cloverOut] = $argv;

if (!is_file($cloverIn) || !is_readable($cloverIn)) {
    fwrite(STDERR, "Missing or unreadable clover input: $cloverIn\n");
    exit(1);
}
if (!is_dir($pcovDir) || !is_readable($pcovDir)) {
    fwrite(STDERR, "Missing or unreadable pcov dump dir: $pcovDir\n");
    exit(1);
}

$xml                     = new DOMDocument();
$xml->preserveWhiteSpace = false;
$xml->formatOutput       = true;
if (!$xml->load($cloverIn)) {
    fwrite(STDERR, "Failed to parse clover XML: $cloverIn\n");
    exit(1);
}

$xpath = new DOMXPath($xml);

// Build path-keyed file lookup from clover.
/** @var array<string,DOMElement> $filesByPath */
$filesByPath = [];
foreach ($xpath->query('//file') as $fileEl) {
    if (!$fileEl instanceof DOMElement) {
        continue;
    }
    $name = $fileEl->getAttribute('name');
    if ($name !== '') {
        $filesByPath[$name] = $fileEl;
    }
}

if ($filesByPath === []) {
    fwrite(STDERR, "Clover contained no <file> entries; nothing to merge into.\n");
    exit(1);
}

// Union the set of executed lines across every pcov dump.
/** @var array<string,array<int,true>> $pcovHits */
$pcovHits  = [];
$dumpFiles = glob(rtrim($pcovDir, '/') . '/*.cov') ?: [];
foreach ($dumpFiles as $dump) {
    $raw = @file_get_contents($dump);
    if ($raw === false || $raw === '') {
        continue;
    }
    $data = @unserialize($raw, ['allowed_classes' => false]);
    if (!is_array($data)) {
        continue;
    }
    foreach ($data as $file => $lines) {
        if (!is_string($file) || !is_array($lines)) {
            continue;
        }
        foreach ($lines as $lineNo => $state) {
            // pcov state codes (from include/pcov.h):
            //   1  = executed
            //  -1  = executable but not executed (covers the line at module load)
            //   0  = not coverable (whitespace / comment)
            // We only union state === 1 hits — uncovered/not-coverable info already
            // lives in the PHPUnit clover.
            if ($state === 1 && is_int($lineNo)) {
                $pcovHits[$file][$lineNo] = true;
            }
        }
    }
}

$mergedFiles = 0;
$liftedLines = 0;
foreach ($pcovHits as $file => $lineSet) {
    if (!isset($filesByPath[$file])) {
        continue;
    }
    $fileEl    = $filesByPath[$file];
    $bumpedAny = false;
    foreach ($xpath->query('./line', $fileEl) as $lineEl) {
        if (!$lineEl instanceof DOMElement) {
            continue;
        }
        $num = (int) $lineEl->getAttribute('num');
        if (!isset($lineSet[$num])) {
            continue;
        }
        $count = (int) $lineEl->getAttribute('count');
        if ($count === 0) {
            $liftedLines++;
            $bumpedAny = true;
        }
        $lineEl->setAttribute('count', (string) max($count, 1));
    }
    if ($bumpedAny) {
        $mergedFiles++;
    }
}

// Recompute per-file <metrics>.
$totalStmts        = 0;
$totalCovStmts     = 0;
$totalMethods      = 0;
$totalCovMethods   = 0;
$totalConditionals = 0;
$totalCovConds     = 0;
$totalLines        = 0;
$totalNcloc        = 0;
$totalClasses      = 0;
foreach ($filesByPath as $fileEl) {
    $fileStmts      = 0;
    $fileCovStmts   = 0;
    $fileMethods    = 0;
    $fileCovMethods = 0;
    $fileConds      = 0;
    $fileCovConds   = 0;
    foreach ($xpath->query('./line', $fileEl) as $lineEl) {
        if (!$lineEl instanceof DOMElement) {
            continue;
        }
        $type  = $lineEl->getAttribute('type');
        $count = (int) $lineEl->getAttribute('count');
        if ($type === 'stmt') {
            $fileStmts++;
            if ($count > 0) {
                $fileCovStmts++;
            }
        } elseif ($type === 'method') {
            $fileMethods++;
            if ($count > 0) {
                $fileCovMethods++;
            }
        } elseif ($type === 'cond') {
            $fileConds++;
            if ($count > 0) {
                $fileCovConds++;
            }
        }
    }
    $metricsEl = $xpath->query('./metrics', $fileEl)->item(0);
    if ($metricsEl instanceof DOMElement) {
        $metricsEl->setAttribute('statements', (string) $fileStmts);
        $metricsEl->setAttribute('coveredstatements', (string) $fileCovStmts);
        $metricsEl->setAttribute('methods', (string) $fileMethods);
        $metricsEl->setAttribute('coveredmethods', (string) $fileCovMethods);
        $metricsEl->setAttribute('conditionals', (string) $fileConds);
        $metricsEl->setAttribute('coveredconditionals', (string) $fileCovConds);
        // 'elements' / 'coveredelements' = sum of the three categories.
        $elements = $fileStmts + $fileMethods + $fileConds;
        $covEls   = $fileCovStmts + $fileCovMethods + $fileCovConds;
        $metricsEl->setAttribute('elements', (string) $elements);
        $metricsEl->setAttribute('coveredelements', (string) $covEls);

        // Preserve loc/ncloc/classes if present; aggregate for project totals.
        $totalLines   += (int) $metricsEl->getAttribute('loc');
        $totalNcloc   += (int) $metricsEl->getAttribute('ncloc');
        $totalClasses += (int) $metricsEl->getAttribute('classes');
    }
    $totalStmts        += $fileStmts;
    $totalCovStmts     += $fileCovStmts;
    $totalMethods      += $fileMethods;
    $totalCovMethods   += $fileCovMethods;
    $totalConditionals += $fileConds;
    $totalCovConds     += $fileCovConds;
}

// Recompute <project>/<metrics>.
$projectMetricsList = $xpath->query('/coverage/project/metrics');
$projectMetricsEl   = $projectMetricsList instanceof DOMNodeList ? $projectMetricsList->item(0) : null;
if ($projectMetricsEl instanceof DOMElement) {
    $projectMetricsEl->setAttribute('statements', (string) $totalStmts);
    $projectMetricsEl->setAttribute('coveredstatements', (string) $totalCovStmts);
    $projectMetricsEl->setAttribute('methods', (string) $totalMethods);
    $projectMetricsEl->setAttribute('coveredmethods', (string) $totalCovMethods);
    $projectMetricsEl->setAttribute('conditionals', (string) $totalConditionals);
    $projectMetricsEl->setAttribute('coveredconditionals', (string) $totalCovConds);
    $projectMetricsEl->setAttribute('elements', (string) ( $totalStmts + $totalMethods + $totalConditionals ));
    $projectMetricsEl->setAttribute(
        'coveredelements',
        (string) ( $totalCovStmts + $totalCovMethods + $totalCovConds )
    );
    if ($projectMetricsEl->hasAttribute('loc')) {
        $projectMetricsEl->setAttribute('loc', (string) $totalLines);
    }
    if ($projectMetricsEl->hasAttribute('ncloc')) {
        $projectMetricsEl->setAttribute('ncloc', (string) $totalNcloc);
    }
    if ($projectMetricsEl->hasAttribute('classes')) {
        $projectMetricsEl->setAttribute('classes', (string) $totalClasses);
    }
    if ($projectMetricsEl->hasAttribute('files')) {
        $projectMetricsEl->setAttribute('files', (string) count($filesByPath));
    }
}

@mkdir(dirname($cloverOut), 0755, true);
if (false === $xml->save($cloverOut)) {
    fwrite(STDERR, "Failed to write merged clover: $cloverOut\n");
    exit(1);
}

$dumpCount = count($dumpFiles);
$pctBefore = '';
$pctAfter  = '';
if ($totalStmts > 0) {
    $pctAfter = sprintf(' (%.1f%%)', 100 * $totalCovStmts / $totalStmts);
}
fprintf(
    STDERR,
    "Merged %d pcov dump(s) from %s into %s\n  -> lifted %d previously-uncovered line(s) across %d file(s)\n  -> project statements: %d covered / %d total%s\n",
    $dumpCount,
    $pcovDir,
    $cloverOut,
    $liftedLines,
    $mergedFiles,
    $totalCovStmts,
    $totalStmts,
    $pctAfter
);
