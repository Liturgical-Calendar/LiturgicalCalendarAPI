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

    /**
     * Set the allowed origins for Cross-Origin Resource Sharing (CORS).
     *
     * This function can read the allowed origins from a file that defines the
     * ALLOWED_ORIGINS constant as an array of strings. If the file is not
     * provided, the function will use the provided array of origins.
     *
     * If the file is provided, the function will merge the provided array of
     * origins with the ones defined in the file. If the provided array is null,
     * the function will use the array from the file.
     *
     * @param string|null $originsFile The path to the file that defines the allowed origins.
     * @param array|null $origins The array of allowed origins.
     * @return void
     */
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

    /**
     * @return array an array of strings where each string is a path segment of the request path
     *
     * Takes the REQUEST_URI and SCRIPT_NAME and parses out the request path segments
     * by removing the API base path and any trailing slashes.
     *
     * For example, if the REQUEST_URI is '/api/dev/calendar/IT/2021' and the
     * SCRIPT_NAME is '/api/dev/index.php', then this method will return
     * ['calendar', 'IT', '2021']
     */
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
     * Returns true if the server is running on localhost.
     *
     * @return bool true if the server is running on localhost, false otherwise
     */
    public static function isLocalhost(): bool
    {
        $localhostAddresses = ['127.0.0.1', '::1', '0.0.0.0'];
        $localhostNames     = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];
        return in_array($_SERVER['SERVER_ADDR'] ?? '', $localhostAddresses) ||
               in_array($_SERVER['REMOTE_ADDR'] ?? '', $localhostAddresses) ||
               in_array($_SERVER['SERVER_NAME'] ?? '', $localhostNames);
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
            define('API_BASE_PATH', Router::determineBasePath());
        }
        $requestPathParts = self::buildRequestPathParts();
        $route            = array_shift($requestPathParts);

        /**
         * N.B. Classes that can be instantiated and that use the Core,
         * MUST be instantiated before calling Core methods,
         * because the relative class constructors also instantiate the Core for the class.
         */
        switch ($route) {
            case '':
            case 'calendar':
                $LitCalEngine = new Calendar();
                // Calendar::$Core will not exist until the Calendar class is instantiated!
                Calendar::$Core->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST,
                    RequestMethod::OPTIONS
                ]);
                Calendar::$Core->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::YAML, RequestContentType::FORMDATA ]);
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
                if (
                    in_array(Tests::$Core->getRequestMethod(), [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)
                    && false === Router::isLocalhost()
                ) {
                    Tests::$Core->setAllowedOrigins(self::$allowedOrigins);
                }
                Tests::$Core->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::YAML ]);
                Tests::$Core->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::YAML ]);
                Tests::handleRequest();
                break;
            case 'events':
                $Events = new Events();
                Events::$Core->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST,
                    RequestMethod::OPTIONS
                ]);
                if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                    if (
                        in_array($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'], [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)
                        && false === Router::isLocalhost()
                    ) {
                        Events::$Core->setAllowedOrigins(self::$allowedOrigins);
                    }
                }
                if (
                    in_array(Events::$Core->getRequestMethod(), [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)
                    && false === Router::isLocalhost()
                ) {
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
                    if (
                        in_array($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'], [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)
                        && false === Router::isLocalhost()
                    ) {
                        RegionalData::$Core->setAllowedOrigins(self::$allowedOrigins);
                    }
                }
                if (
                    in_array(RegionalData::$Core->getRequestMethod(), [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)
                    && false === Router::isLocalhost()
                ) {
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
                if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                    if (
                        in_array($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'], [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)
                        && false === Router::isLocalhost()
                    ) {
                        Missals::$Core->setAllowedOrigins(self::$allowedOrigins);
                    }
                }
                if (
                    in_array(Missals::$Core->getRequestMethod(), [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)
                    && false === Router::isLocalhost()
                ) {
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
                if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                    if (
                        in_array($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'], [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)
                        && false === Router::isLocalhost()
                    ) {
                        Decrees::$Core->setAllowedOrigins(self::$allowedOrigins);
                    }
                }
                if (
                    in_array(Decrees::$Core->getRequestMethod(), [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)
                    && false === Router::isLocalhost()
                ) {
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

    public static function determineBasePath(): string
    {
        /**
         * Detect server Request Scheme
         */
        if (
            (isset($_SERVER['REQUEST_SCHEME']) && !empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] == 'https') ||
            (isset($_SERVER['HTTPS']) && !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ||
            (isset($_SERVER['SERVER_PORT']) && !empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == '443')
        ) {
            $server_request_scheme = 'https';
        } else {
            $server_request_scheme = 'http';
        }

        /**
         * Detect server name or server address if name is not available
         */
        $server_name = isset($_SERVER['SERVER_NAME'])
            ? $_SERVER['SERVER_NAME']
            : (
                isset($_SERVER['SERVER_ADDR'])
                ? $_SERVER['SERVER_ADDR']
                : 'localhost'
            );

        /**
         * Add port to server name when port is not 80 or 443
         */
        if (false === in_array($_SERVER['SERVER_PORT'], ['80', '443'])) {
            $server_name .= ':' . $_SERVER['SERVER_PORT'];
        }

        /**
         * In a localhost instance, ensure that PHP_CLI_SERVER_WORKERS is set to at least 2.
         * In a production instance add `/api/{api_version}` (following the schema of the current production server)
         */
        if (Router::isLocalhost()) {
            $concurrentServiceWorkers = getenv('PHP_CLI_SERVER_WORKERS');
            if (false === $concurrentServiceWorkers || (int)$concurrentServiceWorkers < 2) {
                $pre1 = '<pre style="color:red;background-color:#EFEFEF;display:inline-block;padding: 5px;">PHP_CLI_SERVER_WORKERS</pre>';
                $pre2 = sprintf('<pre style="color:red;background-color:#EFEFEF;display:inline-block;padding:5px;">PHP_CLI_SERVER_WORKERS=2 php -S %1$s</pre>', $server_name);
                die("Not enough concurrent service workers.<br>Perhaps set the {$pre1} environment variable to a value greater than 1? E.g. {$pre2}.");
            }
        } else {
            $apiVersion = 'dev';
            if (preg_match('/^\/api\/(.*?)\/index.php$/', $_SERVER['SCRIPT_NAME'], $matches)) {
                $apiVersion = $matches[1];
            }
            $server_name = "{$_SERVER['SERVER_NAME']}/api/{$apiVersion}";
        }

        return "{$server_request_scheme}://{$server_name}";
    }
}
