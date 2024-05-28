<?php

// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

require_once 'vendor/autoload.php';
require_once 'includes/enums/LitSchema.php';
require_once 'includes/enums/ICSErrorLevel.php';
require_once 'includes/LitTest.php';
require_once 'includes/Health.php';
/*$testsFolder = dirname(__FILE__) . '/tests';
$it = new DirectoryIterator("glob://$testsFolder/*Test.php");
foreach ($it as $f) {
    $fileName = $f->getFilename();
    require_once 'tests/' . $fileName;
}
*/

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
