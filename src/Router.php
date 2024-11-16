<?php

namespace LiturgicalCalendar\Api;

use LiturgicalCalendar\Api\Enum\RequestMethod;
use LiturgicalCalendar\Api\Enum\RequestContentType;
use LiturgicalCalendar\Api\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Enum\ReturnType;
use LiturgicalCalendar\Api\Enum\CacheDuration;
use LiturgicalCalendar\Api\Paths\Calendar;
use LiturgicalCalendar\Api\Paths\Easter;
use LiturgicalCalendar\Api\Paths\Events;
use LiturgicalCalendar\Api\Paths\Metadata;
use LiturgicalCalendar\Api\Paths\Tests;
use LiturgicalCalendar\Api\Paths\RegionalData;
use LiturgicalCalendar\Api\Paths\Missals;
use LiturgicalCalendar\Api\Paths\Decrees;
use LiturgicalCalendar\Api\Paths\Schemas;

class Router
{
    public static array $allowedOrigins = [];

    public static function setAllowedOrigins(?string $originsFile = null, ?array $origins = null): void
    {
        if ($originsFile !== null && file_exists($originsFile)) {
            include_once($originsFile);
        }

        // ALLOWED_ORIGINS should be defined in the $originsFile
        if (defined('ALLOWED_ORIGINS') && is_array(ALLOWED_ORIGINS)) {
            if (null !== $origins) {
                self::$allowedOrigins = array_merge(
                    $origins,
                    ALLOWED_ORIGINS
                );
            } else {
                self::$allowedOrigins = ALLOWED_ORIGINS;
            }
        } elseif (null !== $origins) {
            self::$allowedOrigins = $origins;
        }
    }

    private static function buildRequestPathParts(): array
    {
        // 1) The script name will actually include the base path of the API (e.g. /api/{apiVersion}/index.php),
        //      so in order to obtain the base path we remove index.php and are left with /api/{apiVersion}/
        $apiBasePath = str_replace('index.php', '', $_SERVER['SCRIPT_NAME']); //can also use $_SERVER['DOCUMENT_URI'] or $_SERVER['PHP_SELF']
        // 2) remove any request params from the REQUEST_URI
        $requestPath = explode('?', $_SERVER['REQUEST_URI'])[0];
        // 3) remove the API base path (/api/dev/ or /api/v3/ or whatever it is)
        $requestPath = preg_replace('/^' . preg_quote($apiBasePath, '/') . '/', '', $requestPath);
        // 4) remove any trailing slashes from the request path
        $requestPath = preg_replace('/\/$/', '', $requestPath);
        return explode('/', $requestPath);
    }

    /**
     * This is the main entry point of the API. It takes care of determining which
     * endpoint is being requested and delegates the request to the appropriate
     * class.
     *
     * @return void
     */
    public static function route(): void
    {
        if (false === defined('API_BASE_PATH')) {
            define('API_BASE_PATH', "{$_SERVER['REQUEST_SCHEME']}://{$_SERVER['SERVER_NAME']}");
        }
        $requestPathParts = self::buildRequestPathParts();
        $route = array_shift($requestPathParts);

        /**
         * N.B. Classes that can be instantiated and that use the Core,
         * MUST be instantiated before calling Core methods,
         * because the relative class constructors also instantiate the Core for the class.
         */
        switch ($route) {
            case '':
            case 'calendar':
                $LitCalEngine = new Calendar();
                //Calendar::$Core->setAllowedOrigins(self::$allowedOrigins);
                Calendar::$Core->setAllowedRequestMethods([ RequestMethod::GET, RequestMethod::POST, RequestMethod::OPTIONS ]);
                Calendar::$Core->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::FORMDATA ]);
                Calendar::$Core->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::XML, AcceptHeader::ICS, AcceptHeader::YAML ]);
                $LitCalEngine->setAllowedReturnTypes([ ReturnType::JSON, ReturnType::XML, ReturnType::ICS, ReturnType::YAML ]);
                $LitCalEngine->setCacheDuration(CacheDuration::MONTH);
                $LitCalEngine->init($requestPathParts);
                break;
            case 'metadata':
            case 'calendars':
                Metadata::init();
                break;
            case 'tests':
                Tests::init($requestPathParts);
                Tests::$Core->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST,
                    RequestMethod::PUT,
                    RequestMethod::PATCH,
                    RequestMethod::DELETE,
                    RequestMethod::OPTIONS
                ]);
                if (in_array(Tests::$Core->getRequestMethod(), [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)) {
                    Tests::$Core->setAllowedOrigins(self::$allowedOrigins);
                }
                Tests::$Core->setAllowedRequestContentTypes([ RequestContentType::JSON ]);
                Tests::$Core->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::YAML ]);
                Tests::handleRequest();
                break;
            case 'events':
                $Events = new Events();
                Events::$Core->setAllowedRequestMethods([ RequestMethod::GET, RequestMethod::POST, RequestMethod::OPTIONS ]);
                if (in_array(Events::$Core->getRequestMethod(), [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)) {
                    Events::$Core->setAllowedOrigins(self::$allowedOrigins);
                }
                Events::$Core->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::FORMDATA ]);
                Events::$Core->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::YAML ]);
                $Events->init($requestPathParts);
                break;
            case 'data':
                $RegionalData = new RegionalData();
                RegionalData::$Core->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST,
                    RequestMethod::PUT,
                    RequestMethod::PATCH,
                    RequestMethod::DELETE,
                    RequestMethod::OPTIONS
                ]);
                if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                    if (in_array($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'], [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)) {
                        RegionalData::$Core->setAllowedOrigins(self::$allowedOrigins);
                    }
                }
                if (in_array(RegionalData::$Core->getRequestMethod(), [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)) {
                    RegionalData::$Core->setAllowedOrigins(self::$allowedOrigins);
                }
                RegionalData::$Core->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ]);
                RegionalData::$Core->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                $RegionalData->init($requestPathParts);
                break;
            case 'missals':
                Missals::init($requestPathParts);
                Missals::$Core->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST,
                    RequestMethod::PUT,
                    RequestMethod::PATCH,
                    RequestMethod::DELETE,
                    RequestMethod::OPTIONS
                ]);
                if (in_array(Missals::$Core->getRequestMethod(), [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)) {
                    Missals::$Core->setAllowedOrigins(self::$allowedOrigins);
                }
                Missals::$Core->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::YAML, RequestContentType::FORMDATA ]);
                Missals::$Core->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::YAML ]);
                Missals::handleRequest();
                break;
            case 'easter':
                Easter::init();
                break;
            case 'schemas':
                Schemas::retrieve($requestPathParts);
                break;
            case 'decrees':
                Decrees::init($requestPathParts);
                Decrees::$Core->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST,
                    RequestMethod::PUT,
                    RequestMethod::PATCH,
                    RequestMethod::DELETE,
                    RequestMethod::OPTIONS
                ]);
                if (in_array(Decrees::$Core->getRequestMethod(), [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)) {
                    Decrees::$Core->setAllowedOrigins(self::$allowedOrigins);
                }
                Decrees::$Core->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::YAML, RequestContentType::FORMDATA ]);
                Decrees::$Core->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::YAML ]);
                Decrees::handleRequest();
                break;
            default:
                http_response_code(404);
        }
    }
}
