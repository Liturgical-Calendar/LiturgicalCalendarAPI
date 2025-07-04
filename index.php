<?php

/**
 * Liturgical Calendar API main script
 * PHP version 8.3
 * @author  John Romano D'Orazio <priest@johnromanodorazio.com>
 * @link    https://litcal.johnromanodorazio.com
 * @license Apache 2.0 License
 * @version 4.5
 * Date Created: 27 December 2017
 */

use LiturgicalCalendar\Api\Router;

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set("log_errors", 1);
ini_set("error_log", "/tmp/php-error.log");

ini_set('date.timezone', 'Europe/Vatican');
require_once 'vendor/autoload.php';

/**
 * Define the API_BASE_PATH constant based on the server request scheme and the server name
 * !!IMPORTANT!! There are classes that depend on this !!DO NOT REMOVE!!
 * Perhaps we could find a better way to set this in a class such as Core ...
 */
define('API_BASE_PATH', Router::determineBasePath());

if (false === Router::isLocalhost()) {
    Router::setAllowedOrigins('allowedOrigins.php');
}

Router::route();
die();
