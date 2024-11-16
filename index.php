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

$apiVersion = 'dev';
if (preg_match('/^\/api\/(.*?)\/index.php$/', $_SERVER['SCRIPT_NAME'], $matches)) {
    $apiVersion = $matches[1];
}

if (
    (isset($_SERVER['REQUEST_SCHEME']) && !empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] == 'https') ||
    (isset($_SERVER['HTTPS']) && !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ||
    (isset($_SERVER['SERVER_PORT']) && !empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == '443')
) {
    $server_request_scheme = 'https';
} else {
    $server_request_scheme = 'http';
}

$server_name = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost';
if ('localhost' === $server_name) {
    $server_name .= ':' . $_SERVER['SERVER_PORT'];
    $concurrentServiceWorkers = getenv('PHP_CLI_SERVER_WORKERS');
    if (false === $concurrentServiceWorkers || (int)$concurrentServiceWorkers < 2) {
        die("We detected that there are not enough concurrent service workers. Perhaps set the `PHP_CLI_SERVER_WORKERS` environment variable to a value greater than 1? E.g. `PHP_CLI_SERVER_WORKERS=2 php -S $server_name`.");
    }
} else {
    $server_name = "{$_SERVER['SERVER_NAME']}/api/{$apiVersion}";
}

// !!IMPORTANT!! There are classes that depend on this! Do NOT remove
// Perhaps we could find a better way to set this in a class such as Core ...
define('API_BASE_PATH', "{$server_request_scheme}://{$server_name}");

Router::setAllowedOrigins('allowedOrigins.php');
Router::route();

die();
