<?php

// phpcs:disable PSR1.Files.SideEffects

require_once 'vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use LiturgicalCalendar\Api\Health;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__, ['.env', '.env.local', '.env.development', '.env.production'], false);
$dotenv->ifPresent(['API_PROTOCOL', 'API_HOST'])->notEmpty();
$dotenv->ifPresent(['API_PORT'])->isInteger();
$dotenv->ifPresent(['APP_ENV'])->notEmpty()->allowedValues(['development', 'production']);
$dotenv->ifPresent(['WS_PROTOCOL', 'WS_HOST'])->notEmpty();
$dotenv->ifPresent(['WS_PORT'])->isInteger();
$dotenv->safeLoad();
$API_PROTOCOL = $_ENV['API_PROTOCOL'] ?? 'https';
$API_HOST     = $_ENV['API_HOST'] ?? 'litcal.johnromanodorazio.com';
$API_PORT     = $_ENV['API_PORT'] ?? 443;

if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    ini_set('log_errors', 1);
    error_reporting(E_ALL);
    define('API_BASE_PATH', "{$API_PROTOCOL}://{$API_HOST}:{$API_PORT}");
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    ini_set('log_errors', 1);
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    $apiVersion = basename(__DIR__);
    define('API_BASE_PATH', "{$API_PROTOCOL}://{$API_HOST}/api/{$apiVersion}");
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
