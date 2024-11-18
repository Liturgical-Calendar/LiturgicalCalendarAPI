<?php

/**
 * Liturgical Calendar API main script
 * PHP version 8.3
 * @author  John Romano D'Orazio <priest@johnromanodorazio.com>
 * @link    https://litcal.johnromanodorazio.com
 * @license Apache 2.0 License
 * @version 3.9
 * Date Created: 27 December 2017
 */

use LiturgicalCalendar\Api\Router;

error_reporting(E_ALL);
ini_set('display_errors', 1);

ini_set('date.timezone', 'Europe/Vatican');
require_once 'vendor/autoload.php';

/**
 * Detect server Request Scheme
 */
if (
    (isset($_SERVER['REQUEST_SCHEME']) && !empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] == 'https') ||
    (isset($_SERVER['HTTPS']) && !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ||
    (isset($_SERVER['SERVER_PORT']) && !empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == '443')
) {
    $server_request_scheme = 'https';
} else {
    $server_request_scheme = 'http';
}

/**
 * Detect server name
 */
$server_name = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost';
if (Router::isLocalhost()) {
    $server_name .= ':' . $_SERVER['SERVER_PORT'];
    $concurrentServiceWorkers = getenv('PHP_CLI_SERVER_WORKERS');
    if (false === $concurrentServiceWorkers || (int)$concurrentServiceWorkers < 2) {
        $pre1 = '<pre style="color:red;background-color:#EFEFEF;display:inline-block;padding: 5px;">PHP_CLI_SERVER_WORKERS</pre>';
        $pre2 = sprintf('<pre style="color:red;background-color:#EFEFEF;display:inline-block;padding:5px;">PHP_CLI_SERVER_WORKERS=2 php -S %1$s</pre>', $server_name);
        die("Not enough concurrent service workers.<br>Perhaps set the {$pre1} environment variable to a value greater than 1? E.g. {$pre2}.");
    }
} else {
    $apiVersion = 'dev';
    if (preg_match('/^\/api\/(.*?)\/index.php$/', $_SERVER['SCRIPT_NAME'], $matches)) {
        $apiVersion = $matches[1];
    }
    $server_name = "{$_SERVER['SERVER_NAME']}/api/{$apiVersion}";
}

/**
 * Define the API_BASE_PATH constant based on the server request scheme and the server name
 * !!IMPORTANT!! There are classes that depend on this !!DO NOT REMOVE!!
 * Perhaps we could find a better way to set this in a class such as Core ...
 */
define('API_BASE_PATH', "{$server_request_scheme}://{$server_name}");
define('SCHEMAS_FOLDER', 'jsondata/schemas');
define('TESTS_FOLDER', 'jsondata/tests');

if (false === Router::isLocalhost()) {
    Router::setAllowedOrigins('allowedOrigins.php');
}

Router::route();
die();
