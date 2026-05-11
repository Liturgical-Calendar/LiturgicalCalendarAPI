<?php

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

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use LiturgicalCalendar\Api\Health;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable($projectFolder, ['.env', '.env.local', '.env.development', '.env.test', '.env.staging', '.env.production'], false);
$dotenv->safeLoad();
$dotenv->ifPresent(['API_PROTOCOL', 'API_HOST'])->notEmpty();
$dotenv->ifPresent(['API_PORT'])->isInteger();
$dotenv->ifPresent(['APP_ENV'])->notEmpty()->allowedValues(['development', 'test', 'staging', 'production']);
$dotenv->ifPresent(['WS_PROTOCOL', 'WS_HOST'])->notEmpty();
$dotenv->ifPresent(['WS_PORT'])->isInteger();
// Redis configuration for caching (socket takes precedence over TCP)
$dotenv->ifPresent(['REDIS_SOCKET', 'REDIS_HOST'])->notEmpty();
$dotenv->ifPresent(['REDIS_PORT'])->isInteger();

// Optional: instrument the WebSocket server with pcov so the LitTestRunner /
// validation paths under src/Test/ contribute to the merged coverage report.
// Same triple gate as public/index.php (extension + APP_ENV=test +
// PCOV_SERVER_COVERAGE_DIR), so production stays dormant. Unlike the HTTP
// server's pre-forked workers, this is a single long-running Ratchet loop —
// one \pcov\start() at boot covers every message, and we dump on shutdown
// (Ratchet exits cleanly on SIGTERM, so register_shutdown_function fires).
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
                '%s/pcov-ws-%d-%s.cov',
                rtrim($pcovCoverageDir, '/'),
                getmypid(),
                bin2hex(random_bytes(8))
            );
            @file_put_contents($file, serialize($data), LOCK_EX);
        } catch (\Throwable) {
            // Coverage instrumentation must never crash the server. Swallow.
        }
    });

    // Ratchet's event loop installs its own SIGTERM/SIGINT handler that
    // sometimes exits without invoking shutdown handlers. Re-arm the signal
    // so the per-server-shutdown dump fires reliably under `composer ws:stop`
    // (which sends SIGTERM via the PID file).
    if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        $shutdownHandler = static function (): void {
            exit(0);
        };
        pcntl_signal(SIGTERM, $shutdownHandler);
        pcntl_signal(SIGINT, $shutdownHandler);
    }
}

$logsFolder = $projectFolder . DIRECTORY_SEPARATOR . 'logs';
if (!file_exists($logsFolder)) {
    mkdir($logsFolder);
}
$logFile = $logsFolder . DIRECTORY_SEPARATOR . 'php-error-litcaltestserver.log';

if (isset($_ENV['APP_ENV']) && in_array($_ENV['APP_ENV'], ['development', 'test'], true)) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', $logFile);
    error_reporting(E_ALL);
    $pid = getmypid();
    file_put_contents($logsFolder . DIRECTORY_SEPARATOR . 'ratchet-pid.log', $pid . ' started ' . date('H:i:s.u') . PHP_EOL, FILE_APPEND);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', $logFile);
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

ini_set('date.timezone', 'Europe/Vatican');

$wsHost = $_ENV['WS_HOST'] ?? '127.0.0.1';
$wsPort = filter_var($_ENV['WS_PORT'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 65535],
]) ?: 8082;

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new Health()
        )
    ),
    $wsPort,
    $wsHost
);

$server->run();
