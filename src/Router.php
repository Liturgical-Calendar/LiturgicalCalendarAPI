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
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
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
                ])->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::XML,
                    AcceptHeader::ICS,
                    AcceptHeader::YAML
                ])->setAllowedReturnTypes([
                    ReturnTypeParam::JSON,
                    ReturnTypeParam::XML,
                    ReturnTypeParam::ICS,
                    ReturnTypeParam::YAML
                ]);
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
                ])->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
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
                ])->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                if (
                    in_array($request->getMethod(), [ RequestMethod::PUT->value, RequestMethod::PATCH->value, RequestMethod::DELETE->value ], true)
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
                ])->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                if (
                    in_array($request->getMethod(), [ RequestMethod::PUT->value, RequestMethod::PATCH->value, RequestMethod::DELETE->value ], true)
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
                ])->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                self::$handler = $easterHandler;
                break;
            case 'events':
                $eventsHandler = new EventsHandler($requestPathParts);
                $eventsHandler->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST
                ])->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                self::$handler = $eventsHandler;
                break;
            case 'schemas':
                $schemasHandler = new SchemasHandler($requestPathParts);
                $schemasHandler->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST
                ])->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                self::$handler = $schemasHandler;
                break;
            case 'data':
                $regionalDataHandler = new RegionalDataHandler($requestPathParts);
                $regionalDataHandler->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST,
                    RequestMethod::PUT,
                    RequestMethod::PATCH,
                    RequestMethod::DELETE
                ])->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                self::$handler = $regionalDataHandler;
                break;
            case 'tests':
                $testsHandler = new TestsHandler($requestPathParts);
                $testsHandler->setAllowedRequestMethods([
                    RequestMethod::GET,
                    RequestMethod::POST,
                    RequestMethod::PUT,
                    RequestMethod::PATCH,
                    RequestMethod::DELETE
                ])->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                self::$handler = $testsHandler;
                break;
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
        $serverAddress      = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '';
        $remoteAddress      = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $serverName         = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';
        $localhostAddresses = ['127.0.0.1', '::1', '0.0.0.0'];
        $localhostNames     = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];
        return in_array($serverAddress, $localhostAddresses) ||
               in_array($remoteAddress, $localhostAddresses) ||
               in_array($serverName, $localhostNames);
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

    public static function getApiPaths(): void
    {
        // The websocket server will be running in CLI mode,
        //   and there won't be any $_SERVER globals set (except for $_SERVER['argv'}?).
        if (PHP_SAPI === 'cli') {
            /** @var string[] */
            $argv      = $_SERVER['argv'];
            $entryFile = realpath($argv[0]);
            if (false === $entryFile) {
                throw new ServiceUnavailableException('Unable to determine entry file.');
            }

            $entryDir = dirname($entryFile);

            //$relIndexToParentOfSrc = self::relativePath($entryDir, dirname(__DIR__));

            // Build scheme + host + port from environment variables
            $scheme = getenv('API_PROTOCOL') ?: 'http';
            $host   = getenv('API_HOST')   ?: 'localhost';
            $port   = getenv('API_PORT')   ?: '8000';

            $api_full_path = $scheme . '://' . $host;
            if (!in_array($port, [ '80', '443' ])) {
                $api_full_path .= ':' . $port;
            }

            // Path prefix — e.g. "/api/v1" if desired
            $api_base_path = getenv('API_BASE_PATH') ?: '/';

            self::$apiBase     = $api_base_path;
            self::$apiPath     = rtrim($api_full_path . $api_base_path, '/');
            self::$apiFilePath = self::findProjectRoot($entryDir) . '/'; //$relIndexToParentOfSrc;

            return;
        }


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

        $baseDir = basename(__DIR__);
        if (empty($baseDir)) {
            throw new ServiceUnavailableException('Unable to determine base directory.');
        }
        if (
            false === isset($_SERVER['SCRIPT_NAME'])
            || false === isset($_SERVER['SCRIPT_FILENAME'])
            || false === is_string($_SERVER['SCRIPT_NAME'])
            || false === is_string($_SERVER['SCRIPT_FILENAME'])
        ) {
            throw new ServiceUnavailableException('Unable to determine entry file.');
        }
        $scriptName     = $_SERVER['SCRIPT_NAME'];
        $scriptFileName = $_SERVER['SCRIPT_FILENAME'];
        $api_base_path  = explode($baseDir, $scriptName)[0];
        $indexPath      = $scriptFileName;
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
            // If we're using PHP's built-in server, check if we have enough workers to handle the concurrent requests
            if (PHP_SAPI === 'cli-server') {
                $concurrentServiceWorkers = getenv('PHP_CLI_SERVER_WORKERS');
                if (false === $concurrentServiceWorkers || (int) $concurrentServiceWorkers < 2) {
                    $pre1 = '<pre style="color:red;background-color:#EFEFEF;display:inline-block;padding: 5px;">PHP_CLI_SERVER_WORKERS</pre>';
                    $pre2 = sprintf('<pre style="color:red;background-color:#EFEFEF;display:inline-block;padding:5px;">PHP_CLI_SERVER_WORKERS=2 php -S %1$s -t public</pre>', $api_full_path);
                    throw new ServiceUnavailableException("Not enough concurrent service workers.<br>Perhaps set the {$pre1} environment variable to a value greater than 1? E.g. {$pre2}.");
                }
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

    /**
     * Calculate relative path from $from to $to
     */
    private static function relativePath(string $from, string $to): string
    {
        $pathFrom = realpath($from);
        $pathTo   = realpath($to);
        if (false === $pathFrom || false === $pathTo) {
            throw new ServiceUnavailableException('Unable to determine relative path.');
        }
        $fromParts = explode(DIRECTORY_SEPARATOR, $pathFrom);
        $toParts   = explode(DIRECTORY_SEPARATOR, $pathTo);

        // Remove common base
        while (count($fromParts) && count($toParts) && ( $fromParts[0] === $toParts[0] )) {
            array_shift($fromParts);
            array_shift($toParts);
        }

        return str_repeat('..' . DIRECTORY_SEPARATOR, count($fromParts)) . implode(DIRECTORY_SEPARATOR, $toParts);
    }
}
