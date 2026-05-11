<?php

/**
 * Liturgical Calendar API main script
 * PHP version 8.4
 * @author  John Romano D'Orazio <priest@johnromanodorazio.com>
 * @link    https://litcal.johnromanodorazio.com
 * @license Apache 2.0 License
 * @version 5.0
 * Date Created: 27 December 2017
 */

declare(strict_types=1);

// Locate autoloader by walking up the directory tree
// We start from the folder the current script is running in
$projectFolder  = __DIR__;
$autoloaderPath = null;

// Walk up directories looking for vendor/autoload.php
$level = 0;
while (true) {
    $candidatePath = $projectFolder . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

    if (file_exists($candidatePath)) {
        $autoloaderPath = $candidatePath;
        break;
    }

    // Don't look more than 4 levels up
    if ($level > 4) {
        break;
    }

    $parentDir = dirname($projectFolder);
    if ($parentDir === $projectFolder) { // Reached the filesystem root
        break;
    }

    ++$level;
    $projectFolder = $parentDir;
}

if (null === $autoloaderPath) {
    die('Error: Unable to locate vendor/autoload.php. Please run `composer install` in the project root.');
}

require_once $autoloaderPath;

use LiturgicalCalendar\Api\Router;
use Dotenv\Dotenv;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;

$dotenv = Dotenv::createImmutable($projectFolder, ['.env', '.env.local', '.env.development', '.env.test', '.env.staging', '.env.production'], false);

if (Router::isLocalhost()) {
    // In development environment if no .env file is present we don't want to throw an error
    $dotenv->safeLoad();
} else {
    // In production environment we want to throw an error if no .env file is present
    $dotenv->load();
    // In production environment these variables are required, in development they will be inferred if not set
    $dotenv->required(['API_BASE_PATH', 'APP_ENV']);
}

$dotenv->ifPresent(['API_PROTOCOL', 'API_HOST'])->notEmpty();
// API_BASE_PATH can be empty for local development
$dotenv->ifPresent(['API_PROTOCOL'])->allowedValues(['http', 'https']);
$dotenv->ifPresent(['API_PORT'])->isInteger();
$dotenv->ifPresent(['APP_ENV'])->notEmpty()->allowedValues(['development', 'test', 'staging', 'production']);
$dotenv->ifPresent(['CORS_ALLOWED_ORIGINS'])->notEmpty();

// Optional: when running under the integration-test coverage harness, instrument
// the server with pcov so HTTP integration tests contribute to coverage. Gated
// on three signals so this never fires in production by accident:
//   1) the pcov extension is loaded,
//   2) APP_ENV=test,
//   3) PCOV_SERVER_COVERAGE_DIR points at a writable directory.
// Each request appends a serialized \pcov\collect() snapshot under that dir;
// scripts/merge-pcov-coverage.php folds those snapshots into the clover report.
// Read PCOV_SERVER_COVERAGE_DIR via getenv() rather than $_ENV because the
// orchestration layer (CI workflow / coverage helper script) passes it through
// the shell environment, and PHP's default variables_order ("GPCS") leaves
// $_ENV empty for arbitrary shell variables. APP_ENV is checked the same way
// for symmetry (it usually comes from .env.local but may also be exported).
$pcovServerCoverageDir = getenv('PCOV_SERVER_COVERAGE_DIR');
$pcovAppEnv            = $_ENV['APP_ENV'] ?? getenv('APP_ENV');
if (
    extension_loaded('pcov')
    && $pcovAppEnv === 'test'
    && is_string($pcovServerCoverageDir) && $pcovServerCoverageDir !== ''
) {
    \pcov\start();
    $pcovCoverageDir = $pcovServerCoverageDir;
    register_shutdown_function(static function () use ($pcovCoverageDir): void {
        try {
            \pcov\stop();
            $data = \pcov\collect();
            if ($data === []) {
                return;
            }
            if (!is_dir($pcovCoverageDir) && !@mkdir($pcovCoverageDir, 0755, true) && !is_dir($pcovCoverageDir)) {
                return;
            }
            $file = sprintf(
                '%s/pcov-%d-%s.cov',
                rtrim($pcovCoverageDir, '/'),
                getmypid(),
                bin2hex(random_bytes(8))
            );
            @file_put_contents($file, serialize($data), LOCK_EX);
        } catch (\Throwable) {
            // Coverage instrumentation must never crash a response. Swallow.
        }
    });
}

$logsFolder = $projectFolder . DIRECTORY_SEPARATOR . 'logs';
if (!file_exists($logsFolder)) {
    if (!mkdir($logsFolder, 0755, true)) {
        throw new RuntimeException('Failed to create logs directory: ' . $logsFolder);
    }
}

$logFile = $logsFolder . DIRECTORY_SEPARATOR . 'litcalapi-error.log';

/**
 * TIMEZONE DESIGN DECISION:
 *
 * The PHP default timezone is set to Europe/Vatican as the authoritative liturgical timezone.
 * However, all internal DateTime calculations explicitly use UTC for consistency:
 *
 * - src/DateTime.php::fromFormat() creates dates in UTC
 * - All calendar calculations in CalendarHandler use explicit UTC timezone
 * - All IntlDateFormatter instances use UTC for consistent formatting
 *
 * This is intentional and correct because:
 * 1. Liturgical events are all-day events (time is always 00:00:00)
 * 2. UTC avoids daylight saving time complications in date arithmetic
 * 3. The date portion remains identical whether interpreted as UTC or Europe/Vatican
 *    since midnight UTC and midnight CET/CEST yield the same calendar date for all-day events
 *
 * The Europe/Vatican default serves as:
 * - A safety net for any code that doesn't specify a timezone explicitly
 * - The appropriate timezone for iCal output (X-WR-TIMEZONE header)
 * - Semantic correctness (Vatican is the liturgical authority)
 *
 * DO NOT attempt to "align" all timezone usages - the mix of UTC (internal) and
 * Europe/Vatican (default/external) is by design.
 */
ini_set('date.timezone', 'Europe/Vatican');

if (
    Router::isLocalhost()
    || ( isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'development' )
) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', $logFile);
    error_reporting(E_ALL);
    // Get current time with microseconds
    $microtime = microtime(true);
    $dt        = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $microtime));
    // Check for errors
    if ($dt === false) {
        $errors = DateTimeImmutable::getLastErrors();
        throw new RuntimeException('Failed to create DateTimeImmutable: ' . print_r($errors, true));
    }
    // Convert to Europe/Vatican timezone
    $dt        = $dt->setTimezone(new DateTimeZone('Europe/Vatican'));
    $timestamp = $dt->format('H:i:s.u');
    $pid       = getmypid();
    $pidLogger = LoggerFactory::create('api-pid', $logsFolder, 30, true, false, false);
    $pidLogger->info('Liturgical Calendar API handled by process with PID (' . $pid . ') at ' . $timestamp);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', $logFile);
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

$router = new Router();
$router->route();
