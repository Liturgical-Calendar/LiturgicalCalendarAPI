<?php

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

include_once('LitCalHealth.php');
include_once('vendor/autoload.php');

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new LitCalHealth()
        )
    ),
    8080
);

$server->run();
