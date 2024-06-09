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

// error_reporting(E_ALL);
// ini_set('display_errors', 1);
ini_set('date.timezone', 'Europe/Vatican');
require_once 'vendor/autoload.php';

use LitCal\Router;

Router::setAllowedOrigins('allowedOrigins.php');
Router::route();

die();
