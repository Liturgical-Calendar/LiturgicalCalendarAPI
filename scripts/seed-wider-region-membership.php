#!/usr/bin/env php
<?php

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
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\WiderRegionMembershipSeeder;

$projectRoot = dirname(__DIR__);
Dotenv::createImmutable(
    $projectRoot,
    ['.env', '.env.local', '.env.development', '.env.test', '.env.staging', '.env.production'],
    false
)->safeLoad();

// Initialize the file-path prefix that JsonData::path() requires.
// Router sets this during HTTP boot; CLI scripts must set it manually.
Router::$apiFilePath = $projectRoot . DIRECTORY_SEPARATOR;

$apply = in_array('--apply', $argv, true);
echo 'Mode: ' . ( $apply ? 'APPLY' : 'DRY RUN (pass --apply to write)' ) . PHP_EOL . PHP_EOL;

// The plan is computed purely from the source files, so the preview/dry-run
// path needs no OpenFGA connection. Only require (and connect to) OpenFGA when
// actually writing tuples with --apply.
$seeder     = new WiderRegionMembershipSeeder();
$nationsDir = JsonData::NATIONAL_CALENDARS_FOLDER->path();
$tuples     = $seeder->computeTuples($nationsDir);

foreach ($tuples as $t) {
    echo "{$t['object']}#member_nation@{$t['user']}" . PHP_EOL;
}

if (!$apply) {
    echo PHP_EOL . sprintf("Planned: %d  Written: 0  (dry run — pass --apply to write)\n", count($tuples));
    exit(0);
}

if (!OpenFgaClient::isConfigured()) {
    fwrite(STDERR, "Error: OpenFGA is not configured. Set OPENFGA_API_URL, OPENFGA_STORE_ID, and OPENFGA_MODEL_ID.\n");
    exit(1);
}

$result = $seeder->seed(OpenFgaClient::fromEnv(), $nationsDir, $apply);
echo PHP_EOL . sprintf("Planned: %d  Written: %d\n", $result['planned'], $result['written']);
exit(0);
