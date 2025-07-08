<?php

/**
 * Output requested JSON schema resource
 *
 * @author    John Romano D'Orazio <priest@johnromanodorazio.com>
 * @copyright 2024 John Romano D'Orazio
 * @license   https://www.apache.org/licenses/LICENSE-2.0.txt Apache License 2.0
 * @link      https://litcal.johnromanodorazio.com
 */

namespace LiturgicalCalendar\Api\Paths;

use LiturgicalCalendar\Api\Router;

class Schemas
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
                header('Access-Control-Allow-Methods: GET, OPTIONS');
            }
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
                header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
            }
            exit(0);
        }
    }

    /**
     * Retrieves JSON schema resources based on the provided request path parts.
     *
     * This function enforces the origin and request method headers before proceeding.
     * Depending on the number of path parameters provided, it either returns an index
     * of available JSON schema files or the contents of a specified schema file.
     *
     * @param string[] $requestPathParts An array of path parts derived from the request URI.
     *                                   If empty, the function returns an index of all schemas.
     *                                   If containing one element, it attempts to return the
     *                                   specified schema file's content.
     *
     * @return void Outputs the JSON schema index or the contents of a specific schema.
     *              If the schema file is not found, it responds with a 404 error.
     */
    public static function retrieve(array $requestPathParts = []): void
    {
        self::enforceOrigin();
        self::enforceRequestMethod();
        $pathParamCount = count($requestPathParts);
        switch ($pathParamCount) {
            case 0:
                $schemaIndex                 = new \stdClass();
                $schemaIndex->litcal_schemas = [];
                $it                          = new \DirectoryIterator('glob://jsondata/schemas/*.json');
                foreach ($it as $f) {
                    $schemaIndex->litcal_schemas[] = API_BASE_PATH . '/jsondata/schemas/' . $f->getFilename();
                }
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($schemaIndex);
                die();
            case 1:
                if (file_exists('jsondata/schemas/' . $requestPathParts[0])) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo file_get_contents('jsondata/schemas/' . $requestPathParts[0]);
                    die();
                } else {
                    header($_SERVER[ 'SERVER_PROTOCOL' ] . ' 404 Not Found', true, 404);
                    die("Schema file '{$requestPathParts[0]}' not found");
                }
        }
    }
}
