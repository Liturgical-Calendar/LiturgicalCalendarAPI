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
use LiturgicalCalendar\Api\Services\WiderRegionMembershipReconciler;
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

// Reconciling — not just seeding — means the plan now diffs against what OpenFGA already holds
// (to prune regions a nation no longer declares, and nations whose file is gone entirely), so
// even the dry run needs a live OpenFGA connection.
if (!OpenFgaClient::isConfigured()) {
    fwrite(STDERR, "Error: OpenFGA is not configured. Set OPENFGA_API_URL, OPENFGA_STORE_ID, and OPENFGA_MODEL_ID.\n");
    exit(1);
}

// Scheduled daily with --apply (see docs/ops/rbac-create-governance-runbook.md), so a failure must reach the cron log
// with a reason and a non-zero exit, not a stack trace: in particular the refusal to reconcile a nations folder that
// is missing or empty, which would otherwise prune every member_nation tuple.
try {
    $result = ( new WiderRegionMembershipSeeder() )->reconcile(
        new WiderRegionMembershipReconciler(OpenFgaClient::fromEnv()),
        JsonData::NATIONAL_CALENDARS_FOLDER->path(),
        $apply
    );
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
foreach ($result['writes'] as $t) {
    echo "+ {$t}" . PHP_EOL;
}
foreach ($result['deletes'] as $t) {
    echo "- {$t}" . PHP_EOL;
}
echo PHP_EOL . sprintf(
    "%s: %d writes, %d deletes%s\n",
    $apply ? 'Applied' : 'Planned',
    count($result['writes']),
    count($result['deletes']),
    $apply ? '' : ' (dry run - pass --apply to write)'
);
exit(0);
