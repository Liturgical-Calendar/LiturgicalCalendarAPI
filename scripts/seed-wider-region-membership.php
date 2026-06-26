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
echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY RUN (pass --apply to write)') . PHP_EOL . PHP_EOL;

if (!OpenFgaClient::isConfigured()) {
    fwrite(STDERR, "Error: OpenFGA is not configured. Set OPENFGA_API_URL, OPENFGA_STORE_ID, and OPENFGA_MODEL_ID.\n");
    exit(1);
}

$client     = OpenFgaClient::fromEnv();
$seeder     = new WiderRegionMembershipSeeder();
$nationsDir = JsonData::NATIONAL_CALENDARS_FOLDER->path();
$tuples     = $seeder->computeTuples($nationsDir);

foreach ($tuples as $t) {
    echo "{$t['object']}#member_nation@{$t['user']}" . PHP_EOL;
}
$result = $seeder->seed($client, $nationsDir, $apply);
echo PHP_EOL . sprintf("Planned: %d  Written: %d\n", $result['planned'], $result['written']);
exit(0);
