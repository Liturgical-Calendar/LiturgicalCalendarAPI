#!/usr/bin/env php
<?php

declare(strict_types=1);

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
