<?php

namespace LiturgicalCalendar\Api;

use LiturgicalCalendar\Api\Enum\RequestMethod;
use LiturgicalCalendar\Api\Enum\RequestContentType;
use LiturgicalCalendar\Api\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Enum\ReturnType;
use LiturgicalCalendar\Api\Enum\CacheDuration;
use LiturgicalCalendar\Api\Paths\CalendarPath;
use LiturgicalCalendar\Api\Paths\EasterPath;
use LiturgicalCalendar\Api\Paths\EventsPath;
use LiturgicalCalendar\Api\Paths\MetadataPath;
use LiturgicalCalendar\Api\Paths\TestsPath;
use LiturgicalCalendar\Api\Paths\RegionalDataPath;
use LiturgicalCalendar\Api\Paths\MissalsPath;
use LiturgicalCalendar\Api\Paths\DecreesPath;
use LiturgicalCalendar\Api\Paths\SchemasPath;

class Router
{
    /** @var string[] */
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
     * @param string[]|null $origins The array of allowed origins.
     * @return void
     */
    public static function setAllowedOrigins(?string $originsFile = null, ?array $origins = null): void
    {
        if ($originsFile !== null && file_exists($originsFile)) {
            include_once($originsFile);
        }

        // ALLOWED_ORIGINS should be defined in the $originsFile
        if (defined('ALLOWED_ORIGINS') && is_array(ALLOWED_ORIGINS) && Utilities::allStrings(ALLOWED_ORIGINS)) {
            if (null !== $origins) {
                /** @var string[] $allowedOrigins */
                $allowedOrigins       = array_merge(
                    $origins,
                    ALLOWED_ORIGINS
                );
                self::$allowedOrigins = $allowedOrigins;
            } else {
                /** @var string[] $allowedOrigins */
                $allowedOrigins       = ALLOWED_ORIGINS;
                self::$allowedOrigins = $allowedOrigins;
            }
        } elseif (null !== $origins) {
            self::$allowedOrigins = $origins;
        }
    }

    /**
     * @return string[] an array of strings where each string is a path segment of the request path
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
        $scriptName = self::getScriptName();
        $requestURI = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if ('' === $scriptName || '' === $requestURI) {
            throw new \RuntimeException('Each one must examine his own work, and then he will have reason to boast with regard to himself alone, and not with regard to someone else. (Galatians 6:4)');
        }

        $apiBasePath = str_replace('index.php', '', $scriptName);

        // 2) remove any request params from the REQUEST_URI
        $requestPath = explode('?', $requestURI)[0];

        // 3) remove the API base path (/api/dev/ or /api/v3/ or whatever it is)
        $requestPath = preg_replace('/^' . preg_quote($apiBasePath, '/') . '/', '', $requestPath);
        if (null === $requestPath) {
            throw new \RuntimeException('Upon Matthias the lot was cast by the college of the apostles, but where did Matthias go?');
        }

        // 4) remove any trailing slashes from the request path
        $requestPath = preg_replace('/\/$/', '', $requestPath);
        if (null === $requestPath) {
            throw new \RuntimeException('We started with Esau and expected Jacob: where did Jacob go?');
        }

        return explode('/', $requestPath);
    }

    private static function getScriptName(): string
    {
        $scriptName = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])
                        ? $_SERVER['SCRIPT_NAME']
                        : (
                            isset($_SERVER['PHP_SELF']) && is_string($_SERVER['PHP_SELF'])
                                ? $_SERVER['PHP_SELF']
                                : ( isset($_SERVER['DOCUMENT_URI']) && is_string($_SERVER['DOCUMENT_URI']) ? $_SERVER['DOCUMENT_URI'] : '' )
                        );

        return $scriptName;
    }

    private static function getServerName(): string
    {
        $server_name = isset($_SERVER['SERVER_NAME']) && is_string($_SERVER['SERVER_NAME'])
            ? $_SERVER['SERVER_NAME']
            : (
                isset($_SERVER['SERVER_ADDR']) && is_string($_SERVER['SERVER_ADDR'])
                ? $_SERVER['SERVER_ADDR']
                : 'localhost'
            );
        return $server_name;
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
     * @return never
     */
    public static function route(): never
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
                // no break (intentional fallthrough)
            case 'calendar':
                $LitCalEngine = new CalendarPath();
                // CalendarPath::$Core will not exist until the Calendar class is instantiated!
                CalendarPath::$Core->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST,
                    RequestMethod::OPTIONS
                ]);
                CalendarPath::$Core->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::YAML, RequestContentType::FORMDATA ]);
                CalendarPath::$Core->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::XML, AcceptHeader::ICS, AcceptHeader::YAML ]);
                $LitCalEngine->setAllowedReturnTypes([ ReturnType::JSON, ReturnType::XML, ReturnType::ICS, ReturnType::YAML ]);
                $LitCalEngine->setCacheDuration(CacheDuration::MONTH);
                $LitCalEngine->init($requestPathParts);
                // no break (always terminates)
            case 'metadata':
                // no break (intentional fallthrough)
            case 'calendars':
                $Metadata = new MetadataPath();
                // MetadataPath::$Core will not exist until the Metadata class is instantiated!
                MetadataPath::$Core->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST,
                    RequestMethod::OPTIONS
                ]);
                MetadataPath::$Core->setAllowedRequestContentTypes([]);
                MetadataPath::$Core->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::YAML ]);
                $Metadata->init();
                // no break (always terminates)
            case 'tests':
                TestsPath::init($requestPathParts);
                TestsPath::$Core->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST,
                    RequestMethod::PUT,
                    RequestMethod::PATCH,
                    RequestMethod::DELETE,
                    RequestMethod::OPTIONS
                ]);
                if (
                    in_array(TestsPath::$Core->getRequestMethod(), [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)
                    && false === Router::isLocalhost()
                ) {
                    TestsPath::$Core->setAllowedOrigins(self::$allowedOrigins);
                }
                TestsPath::$Core->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::YAML ]);
                TestsPath::$Core->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::YAML ]);
                TestsPath::handleRequest();
                // no break (always terminates)
            case 'events':
                $Events = new EventsPath();
                EventsPath::$Core->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST,
                    RequestMethod::OPTIONS
                ]);
                if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                    if (
                        in_array($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'], [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)
                        && false === Router::isLocalhost()
                    ) {
                        EventsPath::$Core->setAllowedOrigins(self::$allowedOrigins);
                    }
                }
                if (
                    in_array(EventsPath::$Core->getRequestMethod(), [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)
                    && false === Router::isLocalhost()
                ) {
                    EventsPath::$Core->setAllowedOrigins(self::$allowedOrigins);
                }
                EventsPath::$Core->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::FORMDATA ]);
                EventsPath::$Core->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::YAML ]);
                $Events->init($requestPathParts);
                // no break (always terminates)
            case 'data':
                $RegionalData = new RegionalDataPath();
                RegionalDataPath::$Core->setAllowedRequestMethods([
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
                        RegionalDataPath::$Core->setAllowedOrigins(self::$allowedOrigins);
                    }
                }
                if (
                    in_array(RegionalDataPath::$Core->getRequestMethod(), [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)
                    && false === Router::isLocalhost()
                ) {
                    RegionalDataPath::$Core->setAllowedOrigins(self::$allowedOrigins);
                }
                RegionalDataPath::$Core->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ]);
                RegionalDataPath::$Core->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                $RegionalData->init($requestPathParts);
                // no break (always terminates)
            case 'missals':
                MissalsPath::init($requestPathParts);
                MissalsPath::$Core->setAllowedRequestMethods([
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
                        MissalsPath::$Core->setAllowedOrigins(self::$allowedOrigins);
                    }
                }
                if (
                    in_array(MissalsPath::$Core->getRequestMethod(), [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)
                    && false === Router::isLocalhost()
                ) {
                    MissalsPath::$Core->setAllowedOrigins(self::$allowedOrigins);
                }
                MissalsPath::$Core->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::YAML, RequestContentType::FORMDATA ]);
                MissalsPath::$Core->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::YAML ]);
                MissalsPath::handleRequest();
                // no break (always terminates)
            case 'easter':
                EasterPath::init();
                // no break (always terminates)
            case 'schemas':
                SchemasPath::retrieve($requestPathParts);
                // no break (always terminates)
            case 'decrees':
                DecreesPath::init($requestPathParts);
                DecreesPath::$Core->setAllowedRequestMethods([
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
                        DecreesPath::$Core->setAllowedOrigins(self::$allowedOrigins);
                    }
                }
                if (
                    in_array(DecreesPath::$Core->getRequestMethod(), [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)
                    && false === Router::isLocalhost()
                ) {
                    DecreesPath::$Core->setAllowedOrigins(self::$allowedOrigins);
                }
                DecreesPath::$Core->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::YAML, RequestContentType::FORMDATA ]);
                DecreesPath::$Core->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::YAML ]);
                DecreesPath::handleRequest();
                // no break (always terminates)
            default:
                http_response_code(404);
                die();
        }
    }

    public static function determineBasePath(): string
    {
        /**
         * Detect server Request Scheme
         */
        if (
            ( isset($_SERVER['REQUEST_SCHEME']) && !empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] == 'https' ) ||
            ( isset($_SERVER['HTTPS']) && !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ) ||
            ( isset($_SERVER['SERVER_PORT']) && !empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == '443' )
        ) {
            $server_request_scheme = 'https';
        } else {
            $server_request_scheme = 'http';
        }

        /**
         * Detect server name or server address if name is not available
         */
        $server_name = self::getServerName();

        /**
         * Add port to server name when port is not 80 or 443
         */
        if (isset($_SERVER['SERVER_PORT']) && is_string($_SERVER['SERVER_PORT']) && false === in_array($_SERVER['SERVER_PORT'], ['80', '443'])) {
            $server_name .= ':' . $_SERVER['SERVER_PORT'];
        }

        /**
         * In a localhost instance, ensure that PHP_CLI_SERVER_WORKERS is set to at least 2.
         * In a production instance add `/api/{api_version}` (following the schema of the current production server)
         */
        if (Router::isLocalhost()) {
            $concurrentServiceWorkers = getenv('PHP_CLI_SERVER_WORKERS');
            if (false === $concurrentServiceWorkers || (int) $concurrentServiceWorkers < 2) {
                $pre1 = '<pre style="color:red;background-color:#EFEFEF;display:inline-block;padding: 5px;">PHP_CLI_SERVER_WORKERS</pre>';
                $pre2 = sprintf('<pre style="color:red;background-color:#EFEFEF;display:inline-block;padding:5px;">PHP_CLI_SERVER_WORKERS=2 php -S %1$s</pre>', $server_name);
                die("Not enough concurrent service workers.<br>Perhaps set the {$pre1} environment variable to a value greater than 1? E.g. {$pre2}.");
            }
        } else {
            $apiVersion = 'dev';
            $scriptName = self::getScriptName();
            if (preg_match('/^\/api\/(.*?)\/index.php$/', $scriptName, $matches)) {
                $apiVersion = $matches[1];
            }
            $serverName  = self::getServerName();
            $server_name = "{$serverName}/api/{$apiVersion}";
        }

        return "{$server_request_scheme}://{$server_name}";
    }
}
