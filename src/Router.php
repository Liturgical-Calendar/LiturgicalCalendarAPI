<?php

namespace Johnrdorazio\LitCal;

use Johnrdorazio\LitCal\API;
use Johnrdorazio\LitCal\Metadata;
use Johnrdorazio\LitCal\TestsIndex;
use Johnrdorazio\LitCal\AllEvents;
use Johnrdorazio\LitCal\RegionalData;
use Johnrdorazio\LitCal\Easter;
use Johnrdorazio\LitCal\enum\RequestMethod;
use Johnrdorazio\LitCal\enum\RequestContentType;
use Johnrdorazio\LitCal\enum\AcceptHeader;
use Johnrdorazio\LitCal\enum\ReturnType;
use Johnrdorazio\LitCal\enum\CacheDuration;

class Router
{
    private static array $allowedOrigins = [];

    public static function setAllowedOrigins(?string $originsFile = null, ?array $origins = null): void
    {
        if (file_exists($originsFile)) {
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

    public static function route(): void
    {
        $requestPath = explode('?', $_SERVER['REQUEST_URI'])[0];
        $requestPath = preg_replace('/^\/api\/(?:dev|v[4-9])/', '', $requestPath);

        switch ($requestPath) {
            case '/':
                $LitCalEngine = new API();
                $LitCalEngine->APICore->setAllowedOrigins(self::$allowedOrigins);
                $LitCalEngine->APICore->setAllowedRequestMethods([ RequestMethod::GET, RequestMethod::POST, RequestMethod::OPTIONS ]);
                $LitCalEngine->APICore->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::FORMDATA ]);
                $LitCalEngine->APICore->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::XML, AcceptHeader::ICS, AcceptHeader::YML ]);
                $LitCalEngine->setAllowedReturnTypes([ ReturnType::JSON, ReturnType::XML, ReturnType::ICS, ReturnType::YML ]);
                $LitCalEngine->setCacheDuration(CacheDuration::MONTH);
                $LitCalEngine->init();
                break;
            case '/metadata':
            case '/metadata/':
                Metadata::init();
                break;
            case '/testsindex':
            case '/testsindex/':
                echo TestsIndex::handleRequest();
                break;
            case '/allevents':
            case '/allevents/':
                AllEvents::init();
                break;
            case '/regionaldata':
            case '/regionaldata/':
                $LitCalRegionalData = new RegionalData();
                $LitCalRegionalData->APICore->setAllowedOrigins(self::$allowedOrigins);
                $LitCalRegionalData->APICore->setAllowedReferers(
                    array_map(
                        function ($el) {
                            return $el . "/";
                        },
                        self::$allowedOrigins
                    )
                );
                $LitCalRegionalData->APICore->setAllowedAcceptHeaders([AcceptHeader::JSON]);
                $LitCalRegionalData->APICore->setAllowedRequestContentTypes([RequestContentType::JSON, RequestContentType::FORMDATA]);
                $LitCalRegionalData->init();
                break;
            case '/easter':
            case '/easter/':
                Easter::init();
                break;
            default:
                http_response_code(404);
        }
    }
}
