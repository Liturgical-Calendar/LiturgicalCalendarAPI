<?php

// phpcs:disable PSR1.Files.SideEffects
/**
 * Liturgical Calendar API main script
 * PHP version 8.3
 * @author  John Romano D'Orazio <priest@johnromanodorazio.com>
 * @link    https://litcal.johnromanodorazio.com
 * @license Apache 2.0 License
 * @version 4.5
 * Date Created: 27 December 2017
 */
require_once 'vendor/autoload.php';

use LiturgicalCalendar\Api\Router;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__, ['.env', '.env.local', '.env.development', '.env.production'], false);
$dotenv->ifPresent(['APP_ENV'])->notEmpty()->allowedValues(['development', 'production']);
$dotenv->safeLoad();

if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', 'php-error.log');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    ini_set('log_errors', 1);
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

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
