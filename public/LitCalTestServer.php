<?php

// We start from the folder the current script is running in
$projectFolder = __DIR__;

// And if composer.json is not there, we start to look for it in the parent directories
$level = 0;
while (true) {
    if (file_exists($projectFolder . DIRECTORY_SEPARATOR . 'composer.json')) {
        break;
    }

    // Don't look more than 4 levels up
    if ($level > 4) {
        $projectFolder = null;
        break;
    }

    $parentDir = dirname($projectFolder);
    if ($parentDir === $projectFolder) { // reached the system root folder
        $projectFolder = null;
        break;
    }

    ++$level;
    $projectFolder = $parentDir;
}

if (null === $projectFolder) {
    throw new RuntimeException('Unable to find project root folder, cannot load scripts or environment variables.');
}

require_once $projectFolder . '/vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use LiturgicalCalendar\Api\Health;
use Dotenv\Dotenv;

$dotenv = Dotenv::createMutable($projectFolder, ['.env', '.env.local', '.env.development', '.env.production'], false);
$dotenv->safeLoad();
$dotenv->ifPresent(['API_PROTOCOL', 'API_HOST'])->notEmpty();
$dotenv->ifPresent(['API_PORT'])->isInteger();
$dotenv->ifPresent(['APP_ENV'])->notEmpty()->allowedValues(['development', 'production']);
$dotenv->ifPresent(['WS_PROTOCOL', 'WS_HOST'])->notEmpty();
$dotenv->ifPresent(['WS_PORT'])->isInteger();

$logsFolder = $projectFolder . DIRECTORY_SEPARATOR . 'logs';
if (!file_exists($logsFolder)) {
    mkdir($logsFolder);
}
$logFile = $logsFolder . DIRECTORY_SEPARATOR . 'php-error-litcaltestserver.log';

if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'development') {
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
]) ?: 8080;

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
