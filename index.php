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

// !!IMPORTANT!! There are classes that depend on this! Do NOT remove
// Perhaps we could find a better way to set this in a class such as Core ...
define('API_BASE_PATH', "{$_SERVER['REQUEST_SCHEME']}://{$_SERVER['SERVER_NAME']}/api/{$apiVersion}");

Router::setAllowedOrigins('allowedOrigins.php');
Router::route();

die();
