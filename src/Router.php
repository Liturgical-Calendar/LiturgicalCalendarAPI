<?php

namespace LiturgicalCalendar\Api;

use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Enum\CacheDuration;
use LiturgicalCalendar\Api\Enum\CalendarType;
use LiturgicalCalendar\Api\Enum\PathCategory;
use LiturgicalCalendar\Api\Enum\PermissionLevel;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Handlers\EasterHandler;
use LiturgicalCalendar\Api\Handlers\EventsHandler;
use LiturgicalCalendar\Api\Handlers\MetadataHandler;
use LiturgicalCalendar\Api\Handlers\TestsHandler;
use LiturgicalCalendar\Api\Handlers\RegionalDataHandler;
use LiturgicalCalendar\Api\Handlers\MissalsHandler;
use LiturgicalCalendar\Api\Handlers\DecreesHandler;
use LiturgicalCalendar\Api\Handlers\SchemasHandler;
use LiturgicalCalendar\Api\Handlers\TemporaleHandler;
use LiturgicalCalendar\Api\Handlers\Auth\LoginHandler;
use LiturgicalCalendar\Api\Handlers\Auth\LogoutHandler;
use LiturgicalCalendar\Api\Handlers\Auth\MeHandler;
use LiturgicalCalendar\Api\Handlers\Auth\RefreshHandler;
use LiturgicalCalendar\Api\Handlers\Auth\RoleRequestHandler;
use LiturgicalCalendar\Api\Handlers\Admin\ApplicationAdminHandler;
use LiturgicalCalendar\Api\Handlers\Admin\NotificationsHandler;
use LiturgicalCalendar\Api\Handlers\Admin\RoleRequestAdminHandler;
use LiturgicalCalendar\Api\Handlers\Admin\UsersHandler;
use LiturgicalCalendar\Api\Handlers\ApplicationsHandler;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Http\Middleware\AuthorizationMiddleware;
use LiturgicalCalendar\Api\Http\Middleware\ErrorHandlingMiddleware;
use LiturgicalCalendar\Api\Http\Middleware\HttpsEnforcementMiddleware;
use LiturgicalCalendar\Api\Http\Middleware\JwtAuthMiddleware;
use LiturgicalCalendar\Api\Http\Middleware\LoggingMiddleware;
use LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware;
use LiturgicalCalendar\Api\Http\Middleware\OidcAvailabilityMiddleware;
use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Repositories\CalendarPermissionRepository;
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
    private const MIN_YEAR = 1969;  // exlusive minimum (first year supported is 1970)
    private const MAX_YEAR = 10000; // exclusive maximum (last year supported is 9999)

    private RequestHandlerInterface $handler;
    private Psr17Factory $psr17Factory;
    private ServerRequestInterface $request;
    private string $requestId;
    private ResponseInterface $response;
    private static bool $debug;

    public function __construct()
    {
        if (
            false === isset(self::$apiBase)
            || false === isset(self::$apiPath)
            || false === isset(self::$apiFilePath)
        ) {
            self::getApiPaths();
        }

        if (false === isset(self::$debug)) {
            self::$debug = ( Router::isLocalhost() || isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'development' );
        }

        $this->psr17Factory = new Psr17Factory();
        $request            = $this->retrieveRequest();
        try {
            $this->requestId = bin2hex(random_bytes(8)); // 16 hex chars
        } catch (\Throwable) {
            $this->requestId = uniqid('lit');
        }
        $this->request = $request->withAttribute('request_id', $this->requestId);
    }

    /**
     * Route the incoming HTTP request to the appropriate API endpoint, execute the configured middleware pipeline, and emit the HTTP response.
     *
     * The method selects and configures a per-endpoint request handler based on the request path, applies middlewares (including error handling, logging, and conditional JWT authentication for protected data modification routes), runs the pipeline, appends the X-Request-Id header to the final response, and terminates execution by emitting the response.
     *
     * @return never Terminates execution after emitting the HTTP response.
     */
    public function route(): never
    {
        $path             = $this->request->getUri()->getPath();
        $pathParams       = str_starts_with($path, self::$apiBase)
            ? substr($path, strlen(self::$apiBase))
            : $path;
        $pathParams       = rtrim($pathParams, '/');
        $requestPathParts = explode('/', $pathParams);
        $route            = array_shift($requestPathParts);

        // Parse allowed origins from environment (comma-separated list, or '*' for all)
        // This is used for both handler-level CORS and error response CORS
        $allowedOriginsEnv = isset($_ENV['CORS_ALLOWED_ORIGINS']) && is_string($_ENV['CORS_ALLOWED_ORIGINS'])
            ? $_ENV['CORS_ALLOWED_ORIGINS']
            : null;
        $allowedOrigins    = Utilities::parseCorsAllowedOrigins($allowedOriginsEnv);

        // The very first response that will need to be submitted by the API,
        // is the response to pre-flight requests.
        // However the preflight response headers will depend on whether the endpoint sets allowed Request Methods,
        // so we should leave the responsibility of handling the preflight response to each endpoint.

        switch ($route) {
            case '':
                // no break (intentional fallthrough)
            case 'calendar':
                $calendarHandler = new CalendarHandler($requestPathParts);
                if (count($requestPathParts) === 0) {
                    $calendarHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST
                    ]);
                } elseif (count($requestPathParts) === 1 && is_numeric($requestPathParts[0]) && $requestPathParts[0] > self::MIN_YEAR && $requestPathParts[0] < self::MAX_YEAR) {
                    $calendarHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST
                    ]);
                } elseif (count($requestPathParts) === 2 && in_array($requestPathParts[0], PathCategory::values(), true)) {
                    $calendarHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST
                    ]);
                } elseif (count($requestPathParts) === 3 && in_array($requestPathParts[0], PathCategory::values(), true) && is_numeric($requestPathParts[2]) && $requestPathParts[2] > self::MIN_YEAR && $requestPathParts[2] < self::MAX_YEAR) {
                    $calendarHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST
                    ]);
                } else {
                    $calendarHandler->setAllowedRequestMethods([]);
                }
                $calendarHandler->setAllowedRequestContentTypes([
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
                $this->handler = $calendarHandler;
                break;
            case 'metadata':
                // no break (intentional fallthrough)
            case 'calendars':
                $metadataHandler = new MetadataHandler();
                if (count($requestPathParts) === 0) {
                    $metadataHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST
                    ]);
                } else {
                    $metadataHandler->setAllowedRequestMethods([]);
                }
                $metadataHandler->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                $this->handler = $metadataHandler;
                break;
            case 'missals':
                $missalsHandler = new MissalsHandler($requestPathParts);
                if (count($requestPathParts) === 0) {
                    $missalsHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST,
                        RequestMethod::PUT
                    ]);
                } elseif (count($requestPathParts) === 1) {
                    $missalsHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST,
                        RequestMethod::PATCH,
                        RequestMethod::DELETE
                    ]);
                } else {
                    $missalsHandler->setAllowedRequestMethods([]);
                }
                $missalsHandler->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                if (
                    in_array($this->request->getMethod(), [ RequestMethod::PUT->value, RequestMethod::PATCH->value, RequestMethod::DELETE->value ], true)
                    && false === Router::isLocalhost()
                ) {
                    $missalsHandler->setAllowedOrigins($allowedOrigins);
                }
                $this->handler = $missalsHandler;
                break;
            case 'decrees':
                $decreesHandler = new DecreesHandler($requestPathParts);
                if (count($requestPathParts) === 0) {
                    $decreesHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST,
                        RequestMethod::PUT
                    ]);
                } elseif (count($requestPathParts) === 1) {
                    $decreesHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST,
                        RequestMethod::PATCH,
                        RequestMethod::DELETE
                    ]);
                } else {
                    $decreesHandler->setAllowedRequestMethods([]);
                }
                $decreesHandler->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                if (
                    in_array($this->request->getMethod(), [ RequestMethod::PUT->value, RequestMethod::PATCH->value, RequestMethod::DELETE->value ], true)
                    && false === Router::isLocalhost()
                ) {
                    $decreesHandler->setAllowedOrigins($allowedOrigins);
                }
                $this->handler = $decreesHandler;
                break;
            case 'easter':
                $easterHandler = new EasterHandler();
                if (count($requestPathParts) === 0) {
                    $easterHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST
                    ]);
                } else {
                    $easterHandler->setAllowedRequestMethods([]);
                }
                $easterHandler->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                $this->handler = $easterHandler;
                break;
            case 'events':
                $eventsHandler = new EventsHandler($requestPathParts);
                if (count($requestPathParts) === 0) {
                    $eventsHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST
                    ]);
                } elseif (count($requestPathParts) === 2 && in_array($requestPathParts[0], [PathCategory::NATION->value, PathCategory::DIOCESE->value], true)) {
                    $eventsHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST
                    ]);
                } else {
                    $eventsHandler->setAllowedRequestMethods([]);
                }
                $eventsHandler->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                $this->handler = $eventsHandler;
                break;
            case 'schemas':
                $schemasHandler = new SchemasHandler($requestPathParts);
                if (count($requestPathParts) === 0 || count($requestPathParts) === 1) {
                    $schemasHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST
                    ]);
                } else {
                    $schemasHandler->setAllowedRequestMethods([]);
                }
                $schemasHandler->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                $this->handler = $schemasHandler;
                break;
            case 'auth':
                // Handle authentication routes
                if (count($requestPathParts) >= 1) {
                    $authRoute = $requestPathParts[0];
                    if ($authRoute === 'login') {
                        $loginHandler  = new LoginHandler();
                        $this->handler = $loginHandler;
                    } elseif ($authRoute === 'logout') {
                        $logoutHandler = new LogoutHandler();
                        $this->handler = $logoutHandler;
                    } elseif ($authRoute === 'refresh') {
                        $refreshHandler = new RefreshHandler();
                        $this->handler  = $refreshHandler;
                    } elseif ($authRoute === 'me') {
                        $meHandler     = new MeHandler();
                        $this->handler = $meHandler;
                    } elseif ($authRoute === 'role-requests') {
                        // Role request routes for authenticated users
                        // POST /auth/role-requests - Create new request
                        // GET /auth/role-requests - Get user's own requests
                        // GET /auth/role-requests/status - Check if user needs to request a role
                        $roleRequestHandler = new RoleRequestHandler();
                        $this->handler      = $roleRequestHandler;
                    } else {
                        $this->response = new Response(StatusCode::NOT_FOUND->value, [], null, $this->request->getProtocolVersion(), StatusCode::NOT_FOUND->reason());
                        $this->emitResponse();
                    }
                } else {
                    $this->response = new Response(StatusCode::NOT_FOUND->value, [], null, $this->request->getProtocolVersion(), StatusCode::NOT_FOUND->reason());
                    $this->emitResponse();
                }
                break;
            case 'admin':
                // Handle admin routes
                if (count($requestPathParts) >= 1) {
                    $adminRoute = $requestPathParts[0];
                    if ($adminRoute === 'role-requests') {
                        // Admin role request management routes
                        // GET /admin/role-requests - List all pending requests
                        // POST /admin/role-requests/{id}/approve - Approve a request
                        // POST /admin/role-requests/{id}/reject - Reject a request
                        $roleRequestAdminHandler = new RoleRequestAdminHandler();
                        $this->handler           = $roleRequestAdminHandler;
                    } elseif ($adminRoute === 'notifications') {
                        // Admin notifications route
                        // GET /admin/notifications - Get counts of pending items
                        $notificationsHandler = new NotificationsHandler();
                        $this->handler        = $notificationsHandler;
                    } elseif ($adminRoute === 'users') {
                        // Admin users management routes
                        // GET /admin/users - List all users with roles
                        // DELETE /admin/users/{userId}/roles/{role} - Revoke a role
                        $usersHandler  = new UsersHandler();
                        $this->handler = $usersHandler;
                    } elseif ($adminRoute === 'applications') {
                        // Admin application management routes
                        // GET /admin/applications - List all applications (optional status filter)
                        // GET /admin/applications/pending - List pending applications
                        // GET /admin/applications/{uuid} - Get single application details
                        // POST /admin/applications/{uuid}/approve - Approve an application
                        // POST /admin/applications/{uuid}/reject - Reject an application
                        // POST /admin/applications/{uuid}/revoke - Revoke an approved application
                        $applicationAdminHandler = new ApplicationAdminHandler();
                        $this->handler           = $applicationAdminHandler;
                    } else {
                        $this->response = new Response(StatusCode::NOT_FOUND->value, [], null, $this->request->getProtocolVersion(), StatusCode::NOT_FOUND->reason());
                        $this->emitResponse();
                    }
                } else {
                    $this->response = new Response(StatusCode::NOT_FOUND->value, [], null, $this->request->getProtocolVersion(), StatusCode::NOT_FOUND->reason());
                    $this->emitResponse();
                }
                break;
            case 'applications':
                // Developer applications and API keys management
                // GET /applications - List user's applications
                // POST /applications - Create new application
                // GET /applications/{uuid} - Get single application
                // PATCH /applications/{uuid} - Update application
                // DELETE /applications/{uuid} - Delete application
                // GET /applications/{uuid}/keys - List keys for application
                // POST /applications/{uuid}/keys - Generate new API key
                // DELETE /applications/{uuid}/keys/{keyId} - Revoke/delete key
                // POST /applications/{uuid}/keys/{keyId}/rotate - Rotate key
                $applicationsHandler = new ApplicationsHandler();
                $this->handler       = $applicationsHandler;
                break;
            case 'data':
                $regionalDataHandler = new RegionalDataHandler($requestPathParts);
                $pathCount           = count($requestPathParts);
                $firstInCategory     = $pathCount > 0 && in_array($requestPathParts[0], PathCategory::values(), true);
                $allowedMethods      = match (true) {
                    $pathCount === 0 => [],
                    $pathCount === 1 && !$firstInCategory => [],
                    $pathCount === 1 && $firstInCategory => [RequestMethod::PUT],
                    $pathCount === 2 && $firstInCategory => [
                        RequestMethod::GET,
                        RequestMethod::POST,
                        RequestMethod::PATCH,
                        RequestMethod::DELETE
                    ],
                    $pathCount === 3 && $firstInCategory => [
                        RequestMethod::GET,
                        RequestMethod::POST
                    ],
                    default => []
                };
                $regionalDataHandler->setAllowedRequestMethods($allowedMethods);
                $regionalDataHandler->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                if (
                    in_array($this->request->getMethod(), [ RequestMethod::PUT->value, RequestMethod::PATCH->value, RequestMethod::DELETE->value ], true)
                    && false === Router::isLocalhost()
                ) {
                    $regionalDataHandler->setAllowedOrigins($allowedOrigins);
                }
                $this->handler = $regionalDataHandler;
                break;
            case 'tests':
                $testsHandler = new TestsHandler($requestPathParts);
                if (count($requestPathParts) === 0) {
                    $testsHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST,
                        RequestMethod::PUT
                    ]);
                } elseif (count($requestPathParts) === 1) {
                    $testsHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST,
                        RequestMethod::PATCH,
                        RequestMethod::DELETE
                    ]);
                } else {
                    $testsHandler->setAllowedRequestMethods([]);
                }
                $testsHandler->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                $this->handler = $testsHandler;
                break;
            case 'temporale':
                $temporaleHandler = new TemporaleHandler($requestPathParts);
                if (count($requestPathParts) === 0) {
                    // /temporale - list all, create/replace, update
                    $temporaleHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST,
                        RequestMethod::PUT,
                        RequestMethod::PATCH
                    ]);
                } elseif (count($requestPathParts) === 1) {
                    // /temporale/{event_key} - delete specific event
                    $temporaleHandler->setAllowedRequestMethods([RequestMethod::DELETE]);
                } else {
                    $temporaleHandler->setAllowedRequestMethods([]);
                }
                $temporaleHandler->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                    RequestContentType::YAML,
                    RequestContentType::FORMDATA
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                if (
                    in_array($this->request->getMethod(), [ RequestMethod::PUT->value, RequestMethod::PATCH->value, RequestMethod::DELETE->value ], true)
                    && false === Router::isLocalhost()
                ) {
                    $temporaleHandler->setAllowedOrigins($allowedOrigins);
                }
                $this->handler = $temporaleHandler;
                break;
            default:
                $this->response = new Response(StatusCode::NOT_FOUND->value, [], null, $this->request->getProtocolVersion(), StatusCode::NOT_FOUND->reason());
                $this->emitResponse();
        }

        $pipeline = new MiddlewarePipeline($this->handler);
        $pipeline->pipe(new ErrorHandlingMiddleware($this->psr17Factory, self::$debug, $allowedOrigins)); // outermost middleware
        $pipeline->pipe(new LoggingMiddleware(self::$debug));

        // Apply HTTPS enforcement middleware for auth, admin, and applications routes in production
        if (in_array($route, ['auth', 'admin', 'applications'], true)) {
            $pipeline->pipe(new HttpsEnforcementMiddleware());
        }

        // Apply OIDC authentication for role-requests routes (auth), admin, and applications
        // These routes need the oidc_user attribute set before the handler checks authentication
        if (
            ( $route === 'auth' && count($requestPathParts) >= 1 && $requestPathParts[0] === 'role-requests' )
            || $route === 'admin'
            || $route === 'applications'
        ) {
            // Check OIDC availability within the pipeline so ErrorHandlingMiddleware catches exceptions
            // and CORS headers are applied properly
            $pipeline->pipe(new OidcAvailabilityMiddleware(
                self::isOidcConfigured(),
                'OIDC authentication is not configured. These features require Zitadel integration.'
            ));

            if (self::isOidcConfigured()) {
                $pipeline->pipe(OidcAuthMiddleware::fromEnv());
            }
        }

        // Apply authentication middleware for protected routes
        if (
            in_array($route, ['data', 'tests', 'temporale'], true)
            && in_array($this->request->getMethod(), [RequestMethod::PUT->value, RequestMethod::PATCH->value, RequestMethod::DELETE->value], true)
        ) {
            // Use OIDC (Zitadel) authentication if configured, otherwise fall back to JWT
            if (self::isOidcConfigured()) {
                $pipeline->pipe(OidcAuthMiddleware::fromEnv());
            } else {
                // Fall back to legacy JWT authentication
                // JwtAuthMiddleware sets oidc_user attribute for compatibility with AuthorizationMiddleware
                $pipeline->pipe(new JwtAuthMiddleware());
            }

            // Apply authorization middleware with role-based access control
            // (shared between OIDC and JWT paths)
            $this->configureAuthorizationPipeline($pipeline, $route, $requestPathParts);
        }

        $this->response = $pipeline->handle($this->request)
            ->withHeader('X-Request-Id', $this->requestId);
        $this->emitResponse();
    }

    /**
     * Configure authorization middleware for the pipeline.
     *
     * Extracts common authorization logic to avoid duplication between OIDC and JWT paths.
     *
     * @param MiddlewarePipeline $pipeline The middleware pipeline to configure
     * @param string $route The current route being handled
     * @param array<int, string> $requestPathParts The parsed path parts after the route
     */
    private function configureAuthorizationPipeline(
        MiddlewarePipeline $pipeline,
        string $route,
        array $requestPathParts
    ): void {
        if (!Connection::isConfigured()) {
            throw new ServiceUnavailableException(
                'Database not configured. Protected routes require database connection.'
            );
        }

        $permissionRepo = new CalendarPermissionRepository();

        // Determine required role and calendar type based on route
        $calendarType = null;
        if ($route === 'data' && count($requestPathParts) >= 2) {
            $calendarType = match ($requestPathParts[0]) {
                PathCategory::NATION->value      => CalendarType::NATIONAL,
                PathCategory::DIOCESE->value     => CalendarType::DIOCESAN,
                PathCategory::WIDERREGION->value => CalendarType::WIDERREGION,
                default                          => null
            };

            // Set calendar_id attribute for AuthorizationMiddleware
            $this->request = $this->request->withAttribute('calendar_id', $requestPathParts[1] ?? null);
        }

        if ($route === 'data') {
            $pipeline->pipe(new AuthorizationMiddleware(
                $permissionRepo,
                'calendar_editor',
                $calendarType,
                'calendar_id',
                PermissionLevel::WRITE
            ));
        } elseif ($route === 'tests') {
            $pipeline->pipe(AuthorizationMiddleware::forTestEditor($permissionRepo));
        } elseif ($route === 'temporale') {
            // Temporale requires admin role (General Roman Calendar)
            $pipeline->pipe(AuthorizationMiddleware::forAdmin($permissionRepo));
        }
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

    /**
     * Check if OIDC (Zitadel) authentication is configured.
     *
     * @return bool true if ZITADEL_ISSUER and ZITADEL_CLIENT_ID are set
     */
    public static function isOidcConfigured(): bool
    {
        return OidcAuthMiddleware::isConfigured();
    }

    private function retrieveRequest(): ServerRequestInterface
    {
        $creator = new ServerRequestCreator(
            $this->psr17Factory, // ServerRequestFactory
            $this->psr17Factory, // UriFactory
            $this->psr17Factory, // UploadedFileFactory
            $this->psr17Factory  // StreamFactory
        );
        return $creator->fromGlobals();
    }

    private function emitResponse(): never
    {
        $sapiEmitter = new SapiEmitter();
        $sapiEmitter->emit($this->response);
        die();
    }

    public static function detectRequestScheme(): string
    {
        if (
            ( isset($_SERVER['REQUEST_SCHEME']) && !empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] == 'https' ) ||
            ( isset($_SERVER['HTTPS']) && !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ) ||
            ( isset($_SERVER['SERVER_PORT']) && !empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == '443' )
        ) {
            return 'https';
        }

        return 'http';
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
                throw new \RuntimeException('Unable to determine entry file.');
            }

            $entryDir = dirname($entryFile);

            // Build scheme + host + port from environment variables
            $scheme = isset($_ENV['API_PROTOCOL']) && is_string($_ENV['API_PROTOCOL']) ? $_ENV['API_PROTOCOL'] : self::detectRequestScheme();
            $host   = isset($_ENV['API_HOST'])     && is_string($_ENV['API_HOST'])     ? $_ENV['API_HOST']     : 'localhost';
            $port   = isset($_ENV['API_PORT'])     && is_string($_ENV['API_PORT'])     ? $_ENV['API_PORT']     : '8000';

            $api_full_path = $scheme . '://' . $host;
            if (!in_array($port, [ '80', '443' ], true)) {
                $api_full_path .= ':' . $port;
            }

            // Path prefix — e.g. "/api/v1/" if desired
            $api_base_path = isset($_ENV['API_BASE_PATH']) && is_string($_ENV['API_BASE_PATH']) ? $_ENV['API_BASE_PATH'] : '/';

            self::$apiBase = $api_base_path;
            self::$apiPath = rtrim($api_full_path . $api_base_path, '/');
            $projectRoot   = self::findProjectRoot($entryDir);
            if (null === $projectRoot) {
                throw new \RuntimeException('Unable to find project root folder.');
            }
            self::$apiFilePath = $projectRoot . DIRECTORY_SEPARATOR;
            return;
        }


        /**
         * Detect server Request Scheme
         */
        $api_full_path = self::detectRequestScheme() . '://';

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

        if (
            false === isset($_SERVER['SCRIPT_FILENAME'])
            || false === is_string($_SERVER['SCRIPT_FILENAME'])
        ) {
            throw new ServiceUnavailableException('Unable to determine entry file.');
        }
        if (false === Router::isLocalhost() && ( false === isset($_ENV['API_BASE_PATH']) || false === is_string($_ENV['API_BASE_PATH']) || empty($_ENV['API_BASE_PATH']) )) {
            throw new ServiceUnavailableException('The API_BASE_PATH environment variable must be set in production environments.');
        } else {
            /** @var string $api_base_path */
            $api_base_path = $_ENV['API_BASE_PATH'];
        }

        $indexPath             = $_SERVER['SCRIPT_FILENAME'];
        $relIndexToParentOfSrc = self::relativePath(dirname($indexPath), dirname(__DIR__));

        /**
         * In a localhost instance, we ensure that PHP_CLI_SERVER_WORKERS is set to at least 2. Recommend setting it to 6 for best results.
         * In a production instance we add the environment API_BASE_PATH to the full path.
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

        // Ensure trailing slash on base path if not set in the environment
        if (substr($api_base_path, -1) !== '/') {
            $api_base_path .= '/';
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
        $projectFolder = realpath($startPath);
        if (false === $projectFolder) {
            return null;
        }

        $level = 0;
        while (true) {
            if (file_exists($projectFolder . DIRECTORY_SEPARATOR . 'composer.json')) {
                break;
            }

            // Don't look more than 4 levels up
            if ($level > 4) {
                $projectFolder = null;
                break;
            }

            $parentDir = dirname($projectFolder);
            if ($parentDir === $projectFolder) { // reached the system root folder
                $projectFolder = null;
                break;
            }

            ++$level;
            $projectFolder = $parentDir;
        }

        return $projectFolder;
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
