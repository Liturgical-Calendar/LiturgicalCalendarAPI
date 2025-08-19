<?php

namespace LiturgicalCalendar\Api;

use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Enum\CacheDuration;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Handlers\EasterHandler;
use LiturgicalCalendar\Api\Handlers\EventsHandler;
use LiturgicalCalendar\Api\Handlers\MetadataHandler;
use LiturgicalCalendar\Api\Handlers\TestsHandler;
use LiturgicalCalendar\Api\Handlers\RegionalDataHandler;
use LiturgicalCalendar\Api\Handlers\MissalsHandler;
use LiturgicalCalendar\Api\Handlers\DecreesHandler;
use LiturgicalCalendar\Api\Handlers\SchemasHandler;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Middleware\ErrorHandlingMiddleware;
use LiturgicalCalendar\Api\Http\Server\MiddlewarePipeline;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Router
{
    public static string $apiBase;
    public static string $apiPath;
    public static string $apiFilePath;
    private static RequestHandlerInterface $handler;

    /**
     * This is the main entry point of the API. It takes care of determining which
     * endpoint is being requested and delegates the request to the appropriate
     * class.
     *
     * @return never
     */
    public static function route(): never
    {
        self::getApiPaths();
        $request          = self::retrieveRequest();
        $path             = $request->getUri()->getPath();
        $pathParams       = ltrim($path, self::$apiBase);
        $requestPathParts = explode('/', $pathParams);
        $route            = array_shift($requestPathParts);

        // The very first response that will need to be submitted by the API,
        // is the response to pre-flight requests.
        // However the preflight response headers will depend on whether the endpoint sets allowed Request Methods,
        // so we should leave the responsibility of handling the preflight response to each endpoint.

        /**
         * N.B. Classes that can be instantiated and that use the Core,
         * MUST be instantiated before calling Core methods,
         * because the relative class constructors also instantiate the Core for the class.
         */
        switch ($route) {
            case '':
                // no break (intentional fallthrough)
            case 'calendar':
                $calendarHandler = new CalendarHandler($requestPathParts);
                $calendarHandler->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST
                ]);
                $calendarHandler->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::YAML, RequestContentType::FORMDATA, RequestContentType::MULTIPART ]);
                $calendarHandler->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::XML, AcceptHeader::ICS, AcceptHeader::YAML ]);
                $calendarHandler->setAllowedReturnTypes([ ReturnTypeParam::JSON, ReturnTypeParam::XML, ReturnTypeParam::ICS, ReturnTypeParam::YAML ]);
                $calendarHandler->setCacheDuration(CacheDuration::MONTH);
                self::$handler = $calendarHandler;
                break;
            case 'metadata':
                // no break (intentional fallthrough)
            case 'calendars':
                $metadataHandler = new MetadataHandler();
                $metadataHandler->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST
                ]);
                $metadataHandler->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::YAML, RequestContentType::FORMDATA, RequestContentType::MULTIPART ]);
                $metadataHandler->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::YAML ]);
                self::$handler = $metadataHandler;
                break;
            case 'missals':
                $missalsHandler = new MissalsHandler($requestPathParts);
                $missalsHandler->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST,
                    RequestMethod::PUT,
                    RequestMethod::PATCH,
                    RequestMethod::DELETE
                ]);
                $missalsHandler->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::YAML, RequestContentType::FORMDATA, RequestContentType::MULTIPART ]);
                $missalsHandler->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::YAML ]);
                if (
                    in_array($request->getMethod(), [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)
                    && false === Router::isLocalhost()
                ) {
                    $missalsHandler->setAllowedOriginsFromFile('allowedOrigins.txt');
                }
                self::$handler = $missalsHandler;
                break;
            case 'decrees':
                $decreesHandler = new DecreesHandler($requestPathParts);
                $decreesHandler->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST,
                    RequestMethod::PUT,
                    RequestMethod::PATCH,
                    RequestMethod::DELETE
                ]);
                $decreesHandler->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::YAML, RequestContentType::FORMDATA, RequestContentType::MULTIPART ]);
                $decreesHandler->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::YAML ]);
                if (
                    in_array($request->getMethod(), [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)
                    && false === Router::isLocalhost()
                ) {
                    $decreesHandler->setAllowedOriginsFromFile('allowedOrigins.txt');
                }
                self::$handler = $decreesHandler;
                break;
            case 'easter':
                $easterHandler = new EasterHandler();
                $easterHandler->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST
                ]);
                $easterHandler->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::YAML, RequestContentType::FORMDATA, RequestContentType::MULTIPART ]);
                $easterHandler->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::YAML ]);
                self::$handler = $easterHandler;
                break;
            case 'events':
                $eventsHandler = new EventsHandler($requestPathParts);
                $eventsHandler->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST
                ]);
                $eventsHandler->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::FORMDATA, RequestContentType::MULTIPART ]);
                $eventsHandler->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::YAML ]);

                self::$handler = $eventsHandler;
                break;
            case 'schemas':
                $SchemasHandler = new SchemasHandler($requestPathParts);
                $SchemasHandler->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST
                ]);
                $SchemasHandler->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::FORMDATA, RequestContentType::MULTIPART ]);
                $SchemasHandler->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::YAML ]);

                self::$handler = $SchemasHandler;
                break;
            /*
            case 'tests':
                TestsHandler::init($requestPathParts);
                TestsHandler::$Core->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST,
                    RequestMethod::PUT,
                    RequestMethod::PATCH,
                    RequestMethod::DELETE
                ]);
                if (
                    in_array(TestsHandler::$Core->getRequestMethod(), [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)
                    && false === Router::isLocalhost()
                ) {
                    //TestsHandler::$Core->setAllowedOrigins(self::$allowedOrigins);
                }
                TestsHandler::$Core->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::YAML ]);
                TestsHandler::$Core->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::YAML ]);
                TestsHandler::handleRequest();
                // no break (always terminates)
            case 'data':
                $RegionalData = new RegionalDataHandler();
                RegionalDataHandler::$Core->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST,
                    RequestMethod::PUT,
                    RequestMethod::PATCH,
                    RequestMethod::DELETE
                ]);
                if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                    if (
                        in_array($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'], [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)
                        && false === Router::isLocalhost()
                    ) {
                        //RegionalDataHandler::$Core->setAllowedOrigins(self::$allowedOrigins);
                    }
                }
                if (
                    in_array(RegionalDataHandler::$Core->getRequestMethod(), [ RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE ], true)
                    && false === Router::isLocalhost()
                ) {
                    //RegionalDataHandler::$Core->setAllowedOrigins(self::$allowedOrigins);
                }
                RegionalDataHandler::$Core->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ]);
                RegionalDataHandler::$Core->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                $RegionalData->init($requestPathParts);
                // no break (always terminates)
            */
            default:
                $response = new Response(StatusCode::NOT_FOUND->value, [], null, $request->getProtocolVersion(), StatusCode::NOT_FOUND->reason());
                Router::emitResponse($response);
        }

        $psr17Factory = new Psr17Factory();
        $debug        = ( Router::isLocalhost() || isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'development' );
        $pipeline     = new MiddlewarePipeline(self::$handler);
        $pipeline->pipe(new ErrorHandlingMiddleware($psr17Factory, $debug)); // outermost middleware
        //$pipeline->pipe(new LoggingMiddleware());       // innermost middleware

        $response = $pipeline->handle($request);
        Router::emitResponse($response);
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

    private static function retrieveRequest(): ServerRequestInterface
    {
        $psr17Factory = new Psr17Factory();
        $creator      = new ServerRequestCreator(
            $psr17Factory, // ServerRequestFactory
            $psr17Factory, // UriFactory
            $psr17Factory, // UploadedFileFactory
            $psr17Factory  // StreamFactory
        );
        return $creator->fromGlobals();
    }

    private static function emitResponse(ResponseInterface $response): never
    {
        $sapiEmitter = new SapiEmitter();
        $sapiEmitter->emit($response);
        die();
    }

    private static function getApiPaths(): void
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

        $api_full_path = $server_request_scheme . '://';

        /**
         * Detect server name or server address if name is not available
         */
        $api_full_path .= isset($_SERVER['SERVER_NAME']) && is_string($_SERVER['SERVER_NAME'])
            ? $_SERVER['SERVER_NAME']
            : (
                isset($_SERVER['SERVER_ADDR']) && is_string($_SERVER['SERVER_ADDR'])
                ? $_SERVER['SERVER_ADDR']
                : 'localhost'
            );


        /**
         * Add port to api full path when port is not 80 or 443
         */
        if (isset($_SERVER['SERVER_PORT']) && is_string($_SERVER['SERVER_PORT']) && false === in_array($_SERVER['SERVER_PORT'], ['80', '443'])) {
            $api_full_path .= ':' . $_SERVER['SERVER_PORT'];
        }

        $api_base_path = explode(basename(__DIR__), $_SERVER['SCRIPT_NAME'])[0];
        $indexPath     = $_SERVER['SCRIPT_FILENAME'];
        //$projectRoot   = self::findProjectRoot(dirname($indexPath)); // walk upward from index.php
        //$relRootToSrc  = self::relativePath($projectRoot, __DIR__);
        //$relIndexToSrc = self::relativePath(dirname($indexPath), __DIR__);
        $relIndexToParentOfSrc = self::relativePath(dirname($indexPath), dirname(__DIR__));

        /**
         * In a localhost instance, ensure that PHP_CLI_SERVER_WORKERS is set to at least 2.
         * In a production instance add `/api/{api_version}` (following the schema of the current production server)
         */
        if (Router::isLocalhost()) {
            $api_base_path = '/';
            // Check if we have enough workers to handle the concurrent requests
            $concurrentServiceWorkers = getenv('PHP_CLI_SERVER_WORKERS');
            if (false === $concurrentServiceWorkers || (int) $concurrentServiceWorkers < 2) {
                $pre1 = '<pre style="color:red;background-color:#EFEFEF;display:inline-block;padding: 5px;">PHP_CLI_SERVER_WORKERS</pre>';
                $pre2 = sprintf('<pre style="color:red;background-color:#EFEFEF;display:inline-block;padding:5px;">PHP_CLI_SERVER_WORKERS=2 php -S %1$s -t public</pre>', $api_full_path);
                die("Not enough concurrent service workers.<br>Perhaps set the {$pre1} environment variable to a value greater than 1? E.g. {$pre2}.");
            }
        } else {
            $api_full_path = $api_full_path . rtrim($api_base_path, '/');
        }

        self::$apiBase     = $api_base_path;
        self::$apiPath     = $api_full_path;
        self::$apiFilePath = $relIndexToParentOfSrc;
    }

    /**
     * Walk upward from a start path to find project root (where composer.json is).
     */
    /*
    private static function findProjectRoot(string $startPath): ?string
    {
        $path = realpath($startPath);
        while ($path !== false && $path !== DIRECTORY_SEPARATOR) {
            if (file_exists($path . DIRECTORY_SEPARATOR . 'composer.json')) {
                return $path;
            }
            $path = dirname($path);
        }
        return null;
    }
    */

    /**
     * Calculate relative path from $from to $to
     */
    private static function relativePath(string $from, string $to): string
    {
        $fromParts = explode(DIRECTORY_SEPARATOR, realpath($from));
        $toParts   = explode(DIRECTORY_SEPARATOR, realpath($to));

        // Remove common base
        while (count($fromParts) && count($toParts) && ( $fromParts[0] === $toParts[0] )) {
            array_shift($fromParts);
            array_shift($toParts);
        }

        return str_repeat('..' . DIRECTORY_SEPARATOR, count($fromParts)) . implode(DIRECTORY_SEPARATOR, $toParts);
    }
}
