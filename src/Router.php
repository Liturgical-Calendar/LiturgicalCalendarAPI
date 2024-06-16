<?php

namespace Johnrdorazio\LitCal;

use Johnrdorazio\LitCal\Enum\RequestMethod;
use Johnrdorazio\LitCal\Enum\RequestContentType;
use Johnrdorazio\LitCal\Enum\AcceptHeader;
use Johnrdorazio\LitCal\Enum\ReturnType;
use Johnrdorazio\LitCal\Enum\CacheDuration;
use Johnrdorazio\LitCal\Paths\Calendar;
use Johnrdorazio\LitCal\Paths\Easter;
use Johnrdorazio\LitCal\Paths\Events;
use Johnrdorazio\LitCal\Paths\Metadata;
use Johnrdorazio\LitCal\Paths\Tests;
use Johnrdorazio\LitCal\Paths\RegionalData;

class Router
{
    public static array $allowedOrigins = [];

    public static function setAllowedOrigins(?string $originsFile = null, ?array $origins = null): void
    {
        if ($originsFile !== null && file_exists($originsFile)) {
            include_once($originsFile);
        }

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

    public static function route(): void
    {
        $requestPathParts = self::buildRequestPathParts();
        $route = array_shift($requestPathParts);
        switch ($route) {
            case '':
            case 'calendar':
                $LitCalEngine = new Calendar();
                $LitCalEngine->APICore->setAllowedOrigins(self::$allowedOrigins);
                $LitCalEngine->APICore->setAllowedRequestMethods([ RequestMethod::GET, RequestMethod::POST, RequestMethod::OPTIONS ]);
                $LitCalEngine->APICore->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::FORMDATA ]);
                $LitCalEngine->APICore->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::XML, AcceptHeader::ICS, AcceptHeader::YAML ]);
                $LitCalEngine->setAllowedReturnTypes([ ReturnType::JSON, ReturnType::XML, ReturnType::ICS, ReturnType::YAML ]);
                $LitCalEngine->setCacheDuration(CacheDuration::MONTH);
                $LitCalEngine->init(); //TODO: pass in $requestPathParts and use the path information for our settings
                break;
            case 'metadata':
            case 'calendars':
                Metadata::init();
                break;
            case 'tests':
                Tests::init($requestPathParts);
                Tests::$APICore->setAllowedOrigins(self::$allowedOrigins);
                Tests::$APICore->setAllowedRequestMethods([ RequestMethod::GET, RequestMethod::POST, RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE, RequestMethod::OPTIONS]);
                Tests::$APICore->setAllowedRequestContentTypes([ RequestContentType::JSON ]);
                Tests::$APICore->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::YAML ]);
                Tests::handleRequest();
                break;
            case 'events':
                $Events = new Events();
                $Events::$APICore->setAllowedOrigins(self::$allowedOrigins);
                $Events::$APICore->setAllowedRequestMethods([ RequestMethod::GET, RequestMethod::POST, RequestMethod::OPTIONS ]);
                $Events::$APICore->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::FORMDATA ]);
                $Events::$APICore->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::YAML ]);
                $Events->init($requestPathParts);
                break;
            case 'data':
                $RegionalData = new RegionalData();
                $RegionalData::$APICore->setAllowedOrigins(self::$allowedOrigins);
                $RegionalData::$APICore->setAllowedReferers(
                    array_map(
                        function ($el) {
                            return $el . "/";
                        },
                        self::$allowedOrigins
                    )
                );
                $RegionalData::$APICore->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST,
                    RequestMethod::PUT,
                    RequestMethod::PATCH,
                    RequestMethod::DELETE,
                    RequestMethod::OPTIONS
                ]);
                $RegionalData::$APICore->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ]);
                $RegionalData::$APICore->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                $RegionalData->init($requestPathParts);
                break;
            case 'easter':
                Easter::init();
                break;
            case 'schemas':
                Schema::retrieve();
                break;
            default:
                http_response_code(404);
        }
    }
}
