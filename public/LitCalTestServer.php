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
