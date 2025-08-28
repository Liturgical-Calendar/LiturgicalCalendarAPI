<?php

/**
 * Liturgical Calendar API main script
 * PHP version 8.4
 * @author  John Romano D'Orazio <priest@johnromanodorazio.com>
 * @link    https://litcal.johnromanodorazio.com
 * @license Apache 2.0 License
 * @version 5.0
 * Date Created: 27 December 2017
 */

declare(strict_types=1);

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

use LiturgicalCalendar\Api\Router;
use Dotenv\Dotenv;

$dotenv = Dotenv::createMutable($projectFolder, ['.env', '.env.local', '.env.development', '.env.production'], false);

if (Router::isLocalhost()) {
    // In development environment if no .env file is present we don't want to throw an error
    $dotenv->safeLoad();
} else {
    // In production environment we want to throw an error if no .env file is present
    $dotenv->load();
    // In production environment these variables are required, in development they will be inferred if not set
    $dotenv->required(['API_BASE_PATH']);
}

$dotenv->ifPresent(['API_PROTOCOL', 'API_HOST', 'API_BASE_PATH'])->notEmpty();
$dotenv->ifPresent(['API_PROTOCOL'])->allowedValues(['http', 'https']);
$dotenv->ifPresent(['API_PORT'])->isInteger();
$dotenv->ifPresent(['APP_ENV'])->notEmpty()->allowedValues(['development', 'production']);

if (
    Router::isLocalhost()
    || ( isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'development' )
) {
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

Router::route();
