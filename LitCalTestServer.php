<?php

// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

require_once 'vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use LitCal\Health;

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new Health()
        )
    ),
    8080
);

$server->run();
