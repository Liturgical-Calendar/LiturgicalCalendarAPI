<?php

/**
 * Output requested JSON schema resource
 * PHP version 8.3
 *
 * @package   LitCal
 * @author    John Romano D'Orazio <priest@johnromanodorazio.com>
 * @copyright 2024 John Romano D'Orazio
 * @license   https://www.apache.org/licenses/LICENSE-2.0.txt Apache License 2.0
 * @version   GIT: 3.9
 * @link      https://litcal.johnromanodorazio.com
 */

namespace Johnrdorazio\LitCal;

class Schema
{
    private static function enforceOrigin(): void
    {
        if (
            isset($_SERVER['HTTP_ORIGIN'])
            && in_array($_SERVER['HTTP_ORIGIN'], Router::$allowedOrigins)
        ) {
            header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Max-Age: 86400');    // cache for 1 day
        }
    }

    private static function enforceRequestMethod(): void
    {
        // Access-Control headers are received during OPTIONS requests
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                header("Access-Control-Allow-Methods: GET, OPTIONS");
            }
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
                header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
            }
            exit(0);
        }
    }

    public static function retrieve(): void
    {
        self::enforceOrigin();
        self::enforceRequestMethod();
        if (isset($_GET['schema'])) {
            if (file_exists($_GET['schema'])) {
                header('Content-Type: application/json; charset=utf-8');
                echo file_get_contents($_GET['schema']);
            } else {
                header($_SERVER[ "SERVER_PROTOCOL" ] . " 404 Not Found", true, 404);
                die('File not found');
            }
        } else {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 400 Bad Request", true, 400);
            die('Schema parameter is required');
        }
    }
}
