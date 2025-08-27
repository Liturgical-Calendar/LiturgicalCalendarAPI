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
    throw new Exception('Unable to find project root folder, cannot load scripts or environment variables.');
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

if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    ini_set('log_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    ini_set('log_errors', 1);
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}


$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new Health()
        )
    ),
    $_ENV['WS_PORT'] ?? 8080
);

$server->run();
