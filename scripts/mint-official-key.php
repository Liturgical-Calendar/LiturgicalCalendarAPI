#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Repositories\ApiKeyRepository;

/**
 * Mint a first-party "official UI" API key.
 *
 * First-party keys belong to a single system application (name "LitCal Official UIs",
 * is_system=true). They are read-scoped with a very high rate limit so the official project UIs
 * (the test runner, frontend, components, …) are exempt from the unauthenticated per-IP rate
 * limit. Because is_system is set ONLY here — never by the user-facing application or
 * access-request flows — users can never mint an ungated key.
 *
 * Usage:
 *   php scripts/mint-official-key.php --name=<label> [--rate-limit=<int>] [--owner=<zitadel_user_id>]
 *
 *   --name        Required. Human-readable label for the key (e.g. "test-runner").
 *   --rate-limit  Optional. Requests/hour for the key. Default 1000000 (effectively unlimited;
 *                 the rate limiter has no "unlimited" sentinel, and 0/null falls back to default).
 *   --owner       Zitadel user id that will own the official application. Required only the first
 *                 time, when the official application does not yet exist; ignored afterward.
 *
 * The plaintext key is printed ONCE and never stored — copy it immediately into the consuming
 * UI's environment (e.g. WS_API_KEY in the WebSocket server's .env) and restart that service.
 */

const OFFICIAL_APP_NAME  = 'LitCal Official UIs';
const DEFAULT_RATE_LIMIT = 1000000;

$projectRoot = dirname(__DIR__);
Dotenv::createImmutable(
    $projectRoot,
    ['.env', '.env.local', '.env.development', '.env.test', '.env.staging', '.env.production'],
    false
)->safeLoad();

// Parse "--flag=value" arguments.
$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
        $args[$m[1]] = $m[2];
    }
}

$name  = $args['name'] ?? null;
$owner = $args['owner'] ?? null;

if (!is_string($name) || $name === '') {
    fwrite(STDERR, "Error: --name=<label> is required (e.g. --name=test-runner).\n");
    exit(1);
}

$rateLimitPerHour = DEFAULT_RATE_LIMIT;
if (isset($args['rate-limit'])) {
    if (!ctype_digit($args['rate-limit']) || (int) $args['rate-limit'] < 1) {
        fwrite(STDERR, "Error: --rate-limit must be a positive integer.\n");
        exit(1);
    }
    $rateLimitPerHour = (int) $args['rate-limit'];
}

if (!Connection::isConfigured()) {
    fwrite(STDERR, "Error: database is not configured (set DB_HOST, DB_NAME, DB_USER, DB_PASSWORD).\n");
    exit(1);
}

$pdo = Connection::getInstance();

// Find or create the single first-party "official" application.
$stmt = $pdo->prepare(
    'SELECT id, zitadel_user_id FROM applications
     WHERE name = :name AND is_system = TRUE
     LIMIT 1'
);
$stmt->execute(['name' => OFFICIAL_APP_NAME]);
/** @var array{id: string, zitadel_user_id: string}|false $app */
$app = $stmt->fetch();

if ($app !== false) {
    $appId = $app['id'];
    echo 'Reusing official application "' . OFFICIAL_APP_NAME . "\" ({$appId}).\n";
} else {
    if (!is_string($owner) || $owner === '') {
        fwrite(
            STDERR,
            "Error: the official application does not exist yet; pass --owner=<zitadel_user_id> to create it.\n"
        );
        exit(1);
    }

    // Atomic create-or-reuse: the partial unique index uq_applications_system_name guarantees a
    // single first-party system application per name, so concurrent runs converge on one row.
    $insert = $pdo->prepare(
        "INSERT INTO applications (zitadel_user_id, name, description, status, requested_scope, is_active, is_system)
         VALUES (:owner, :name, :description, 'approved', 'read', TRUE, TRUE)
         ON CONFLICT (name) WHERE is_system DO NOTHING
         RETURNING id"
    );
    $insert->execute([
        'owner'       => $owner,
        'name'        => OFFICIAL_APP_NAME,
        'description' => 'First-party application for official LitCal project UIs (read-only, rate-limit-exempt). Managed via scripts/mint-official-key.php.',
    ]);
    $appId   = $insert->fetchColumn();
    $created = is_string($appId);

    if (!$created) {
        // A concurrent run inserted it first (ON CONFLICT DO NOTHING returned no row) — re-select.
        $reselect = $pdo->prepare('SELECT id FROM applications WHERE name = :name AND is_system = TRUE LIMIT 1');
        $reselect->execute(['name' => OFFICIAL_APP_NAME]);
        $appId = $reselect->fetchColumn();
    }

    if (!is_string($appId)) {
        fwrite(STDERR, "Error: failed to create or locate the official application.\n");
        exit(1);
    }

    echo $created
        ? 'Created official application "' . OFFICIAL_APP_NAME . "\" ({$appId}) owned by {$owner}.\n"
        : 'Reusing official application "' . OFFICIAL_APP_NAME . "\" ({$appId}) (created concurrently).\n";
}

$repo   = new ApiKeyRepository($pdo);
$result = $repo->generate($appId, $name, 'read', $rateLimitPerHour);

$record    = $result['record'];
$keyPrefix = is_array($record) && isset($record['key_prefix']) && is_string($record['key_prefix'])
    ? $record['key_prefix']
    : '(unknown)';

echo PHP_EOL;
echo "Minted official API key:\n";
echo "  name:        {$name}\n";
echo "  scope:       read\n";
echo "  rate_limit:  {$rateLimitPerHour}/hour\n";
echo "  key_prefix:  {$keyPrefix}\n";
echo PHP_EOL;
echo "  KEY (shown once — copy it now):\n";
echo "  {$result['key']}\n";
echo PHP_EOL;
echo "Set it in the consuming UI's environment (e.g. WS_API_KEY=<key> in the WebSocket server's .env),\n";
echo "then restart that service.\n";
exit(0);
