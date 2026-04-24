#!/usr/bin/env php
<?php

/**
 * Migration Script: user_calendar_permissions → OpenFGA relationship tuples
 *
 * Reads all rows from the user_calendar_permissions table in PostgreSQL and
 * creates corresponding relationship tuples in OpenFGA.
 *
 * Mapping:
 *   calendar_type "national"    → object type "national_calendar"
 *   calendar_type "diocesan"    → object type "diocesan_calendar"
 *   calendar_type "widerregion" → object type "wider_region"
 *   permission "write"          → relation "editor"
 *   permission "read"           → relation "viewer"
 *
 * Usage:
 *   php scripts/migrate-permissions-to-openfga.php                # Dry run (default)
 *   php scripts/migrate-permissions-to-openfga.php --execute      # Actually write tuples
 *   php scripts/migrate-permissions-to-openfga.php --rollback     # Delete migrated tuples
 *
 * Prerequisites:
 *   - PostgreSQL database configured (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD)
 *   - OpenFGA configured (OPENFGA_API_URL, OPENFGA_STORE_ID, OPENFGA_MODEL_ID)
 *   - Both services accessible from where this script runs
 */

declare(strict_types=1);

// Find project root by looking for composer.json
$dir = __DIR__;
while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
    $dir = dirname($dir);
}

if (!file_exists($dir . '/vendor/autoload.php')) {
    fwrite(STDERR, "Error: Could not find vendor/autoload.php. Run 'composer install' first.\n");
    exit(1);
}

require_once $dir . '/vendor/autoload.php';

// Load environment variables
$dotenvFiles = ['.env.local', '.env.development', '.env'];
foreach ($dotenvFiles as $envFile) {
    $path = $dir . '/' . $envFile;
    if (file_exists($path)) {
        $dotenv = \Dotenv\Dotenv::createImmutable($dir, $envFile);
        $dotenv->safeLoad();
        break;
    }
}

// --- Configuration ---

/** @var array<string, string> Map calendar_type DB values to OpenFGA object types */
$calendarTypeMap = [
    'national'    => 'national_calendar',
    'diocesan'    => 'diocesan_calendar',
    'widerregion' => 'wider_region',
];

/** @var array<string, string> Map permission DB values to OpenFGA relations */
$permissionMap = [
    'write' => 'editor',
    'read'  => 'viewer',
];

// --- Parse arguments ---

$args     = array_slice($argv, 1);
$execute  = in_array('--execute', $args, true);
$rollback = in_array('--rollback', $args, true);

if ($execute && $rollback) {
    fwrite(STDERR, "Error: Cannot use --execute and --rollback together.\n");
    exit(1);
}

$mode = $rollback ? 'ROLLBACK' : ( $execute ? 'EXECUTE' : 'DRY RUN' );

// --- Colors ---

$green  = "\033[0;32m";
$yellow = "\033[1;33m";
$red    = "\033[0;31m";
$blue   = "\033[0;34m";
$nc     = "\033[0m";

echo "{$blue}========================================{$nc}\n";
echo "{$blue}  Permission Migration to OpenFGA{$nc}\n";
echo "{$blue}  Mode: {$yellow}{$mode}{$nc}\n";
echo "{$blue}========================================{$nc}\n\n";

// --- Connect to PostgreSQL ---

$dbHost = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '';
$dbPort = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '5432';
$dbName = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: '';
$dbUser = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: '';
$dbPass = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '';

if (!is_string($dbHost) || $dbHost === '' || !is_string($dbName) || $dbName === '') {
    fwrite(STDERR, "{$red}Error: Database not configured. Set DB_HOST, DB_NAME, DB_USER, DB_PASSWORD.{$nc}\n");
    exit(1);
}

$dbPort = is_string($dbPort) ? $dbPort : '5432';
$dbUser = is_string($dbUser) ? $dbUser : '';
$dbPass = is_string($dbPass) ? $dbPass : '';

try {
    $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "{$green}Connected to PostgreSQL ({$dbName}@{$dbHost}:{$dbPort}){$nc}\n";
} catch (PDOException $e) {
    fwrite(STDERR, "{$red}Database connection failed: {$e->getMessage()}{$nc}\n");
    exit(1);
}

// --- Connect to OpenFGA ---

if (!\LiturgicalCalendar\Api\Services\OpenFgaClient::isConfigured()) {
    fwrite(STDERR, "{$red}Error: OpenFGA not configured. Set OPENFGA_API_URL, OPENFGA_STORE_ID, OPENFGA_MODEL_ID.{$nc}\n");
    exit(1);
}

try {
    $fga = \LiturgicalCalendar\Api\Services\OpenFgaClient::fromEnv();
    echo "{$green}Connected to OpenFGA{$nc}\n\n";
} catch (RuntimeException $e) {
    fwrite(STDERR, "{$red}OpenFGA connection failed: {$e->getMessage()}{$nc}\n");
    exit(1);
}

// --- Read permissions from database ---

echo "{$yellow}Reading permissions from user_calendar_permissions...{$nc}\n";

$stmt = $pdo->query(
    'SELECT zitadel_user_id, calendar_type, calendar_id, permission, granted_at, granted_by
     FROM user_calendar_permissions
     ORDER BY calendar_type, calendar_id, zitadel_user_id'
);

if ($stmt === false) {
    fwrite(STDERR, "{$red}Failed to query user_calendar_permissions{$nc}\n");
    exit(1);
}

/** @var array<int, array<string, string|null>> $rows */
$rows  = $stmt->fetchAll();
$total = count($rows);

echo "{$green}Found {$total} permission(s) to migrate{$nc}\n\n";

if ($total === 0) {
    echo "{$yellow}Nothing to migrate.{$nc}\n";
    exit(0);
}

// --- Process each permission ---

$migrated = 0;
$skipped  = 0;
$errors   = 0;

foreach ($rows as $i => $row) {
    $userId       = $row['zitadel_user_id'] ?? '';
    $calendarType = $row['calendar_type'] ?? '';
    $calendarId   = $row['calendar_id'] ?? '';
    $permission   = $row['permission'] ?? '';
    $grantedAt    = $row['granted_at'] ?? 'unknown';
    $num          = $i + 1;

    // Map calendar type to OpenFGA object type
    $objectType = $calendarTypeMap[$calendarType] ?? null;
    if ($objectType === null) {
        echo "  {$red}[{$num}/{$total}] SKIP: Unknown calendar_type '{$calendarType}' for user {$userId}{$nc}\n";
        $skipped++;
        continue;
    }

    // Map permission to OpenFGA relation
    $relation = $permissionMap[$permission] ?? null;
    if ($relation === null) {
        echo "  {$red}[{$num}/{$total}] SKIP: Unknown permission '{$permission}' for user {$userId}{$nc}\n";
        $skipped++;
        continue;
    }

    $fgaUser   = "user:{$userId}";
    $fgaObject = "{$objectType}:{$calendarId}";

    echo "  [{$num}/{$total}] {$fgaUser} → {$relation} → {$fgaObject} (granted {$grantedAt})";

    if (!$execute && !$rollback) {
        echo " {$yellow}[DRY RUN]{$nc}\n";
        $migrated++;
        continue;
    }

    try {
        if ($rollback) {
            $fga->deleteTuple($fgaUser, $relation, $fgaObject);
            echo " {$green}[DELETED]{$nc}\n";
        } else {
            $fga->writeTuple($fgaUser, $relation, $fgaObject);
            echo " {$green}[MIGRATED]{$nc}\n";
        }
        $migrated++;
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        // Tuple already exists is not a fatal error during migration
        if (str_contains($msg, 'cannot write a tuple which already exists')) {
            echo " {$yellow}[ALREADY EXISTS]{$nc}\n";
            $migrated++;
        } elseif ($rollback && str_contains($msg, 'cannot delete a tuple which does not exist')) {
            echo " {$yellow}[NOT FOUND]{$nc}\n";
            $migrated++;
        } else {
            echo " {$red}[ERROR: {$msg}]{$nc}\n";
            $errors++;
        }
    }
}

// --- Summary ---

echo "\n{$blue}========================================{$nc}\n";
echo "{$blue}  Migration Summary ({$mode}){$nc}\n";
echo "{$blue}========================================{$nc}\n\n";
echo "  Total permissions:  {$total}\n";

$verb = $rollback ? 'Rolled back' : ( $execute ? 'Migrated' : 'Would migrate' );
echo "  {$verb}:          {$green}{$migrated}{$nc}\n";

if ($skipped > 0) {
    echo "  Skipped:            {$yellow}{$skipped}{$nc}\n";
}
if ($errors > 0) {
    echo "  Errors:             {$red}{$errors}{$nc}\n";
}

echo "\n";

if (!$execute && !$rollback) {
    echo "{$yellow}This was a dry run. Use --execute to write tuples to OpenFGA.{$nc}\n";
    echo "{$yellow}Use --rollback to delete migrated tuples from OpenFGA.{$nc}\n\n";
}

if ($errors > 0) {
    exit(1);
}
