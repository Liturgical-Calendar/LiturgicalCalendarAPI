#!/usr/bin/env php
<?php

/**
 * Idempotent migration: wider region ids (issue #1018).
 *
 *   wider_region:roman/Europe → wider_region:roman/europe   (likewise Americas, Asia, `Middle East` → middle-east)
 *
 * Grants (`editor`, `admin`, `viewer`) and `member_nation` tuples name the region in their object; both sides of every
 * tuple are mapped. The default is a dry run; `--apply` copies, and `--apply --prune` also deletes the originals.
 * Prune only once every deployment runs merged code, since until then the old ids keep authorizing a rollback.
 *
 * Usage:
 *   php scripts/migrate-wider-region-ids.php [--apply [--prune]]
 *
 * Safety: a tuple is never deleted before its replacement is written; an existing replacement and an already-missing
 * original are both benign, so the script is safe to re-run.
 *
 * Required environment variables (loaded from .env* files if present):
 *   OPENFGA_API_URL, OPENFGA_STORE_ID, OPENFGA_MODEL_ID
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
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\WiderRegionIdTupleMigration;

$projectRoot = dirname(__DIR__);

$dotenv = Dotenv::createImmutable(
    $projectRoot,
    ['.env', '.env.local', '.env.development', '.env.test', '.env.staging', '.env.production'],
    false
);
$dotenv->safeLoad();

$apply = in_array('--apply', $argv, true);
$prune = in_array('--prune', $argv, true);

if ($prune && !$apply) {
    fwrite(STDERR, "Error: --prune requires --apply.\n");
    exit(2);
}

if (!OpenFgaClient::isConfigured()) {
    fwrite(STDERR, "Error: OpenFGA is not configured. Set OPENFGA_API_URL, OPENFGA_STORE_ID, and OPENFGA_MODEL_ID.\n");
    exit(1);
}

$migration = new WiderRegionIdTupleMigration(OpenFgaClient::fromEnv());
if (!$apply) {
    foreach ($migration->plan() as $step) {
        echo "- {$step['from']['user']} {$step['from']['relation']} {$step['from']['object']}" . PHP_EOL;
        echo "+ {$step['to']['user']} {$step['to']['relation']} {$step['to']['object']}" . PHP_EOL;
    }
    echo 'Dry run. Pass --apply to copy, and --apply --prune to also delete the originals.' . PHP_EOL;
    exit(0);
}
$result = $migration->apply($prune);
printf("copied=%d pruned=%d\n", $result['copied'], $result['pruned']);
