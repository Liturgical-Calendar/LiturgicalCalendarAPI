<?php

namespace LiturgicalCalendar\Api;

use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Enum\PathCategory;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Handlers\EasterHandler;
use LiturgicalCalendar\Api\Handlers\EventsHandler;
use LiturgicalCalendar\Api\Handlers\LectionaryHandler;
use LiturgicalCalendar\Api\Handlers\MetadataHandler;
use LiturgicalCalendar\Api\Handlers\TestsHandler;
use LiturgicalCalendar\Api\Handlers\RegionalDataHandler;
use LiturgicalCalendar\Api\Handlers\MissalsHandler;
use LiturgicalCalendar\Api\Handlers\DecreesHandler;
use LiturgicalCalendar\Api\Handlers\SchemasHandler;
use LiturgicalCalendar\Api\Handlers\ValidationsHandler;
use LiturgicalCalendar\Api\Handlers\TemporaleHandler;
use LiturgicalCalendar\Api\Handlers\Auth\LoginHandler;
use LiturgicalCalendar\Api\Handlers\Auth\LogoutHandler;
use LiturgicalCalendar\Api\Handlers\Auth\MeHandler;
use LiturgicalCalendar\Api\Handlers\Auth\RefreshHandler;
use LiturgicalCalendar\Api\Handlers\Auth\AccessRequestHandler;
use LiturgicalCalendar\Api\Handlers\Auth\EmailVerificationHandler;
use LiturgicalCalendar\Api\Handlers\Admin\AccessRequestAdminHandler;
use LiturgicalCalendar\Api\Handlers\Admin\ApplicationAdminHandler;
use LiturgicalCalendar\Api\Handlers\Admin\ChangeRequestAdminHandler;
use LiturgicalCalendar\Api\Handlers\Admin\NotificationsHandler as AdminNotificationsHandler;
use LiturgicalCalendar\Api\Handlers\Admin\LocalesAdminHandler;
use LiturgicalCalendar\Api\Handlers\Admin\OutboxAdminHandler;
use LiturgicalCalendar\Api\Handlers\Auth\AdminScopesHandler;
use LiturgicalCalendar\Api\Handlers\Auth\DashboardScopesHandler;
use LiturgicalCalendar\Api\Handlers\Auth\TestScopesHandler;
use LiturgicalCalendar\Api\Handlers\Auth\NotificationsHandler;
use LiturgicalCalendar\Api\Handlers\Auth\ChangeRequestHandler;
use LiturgicalCalendar\Api\Handlers\Admin\PermissionAdminHandler;
use LiturgicalCalendar\Api\Handlers\Admin\UsersHandler;
use LiturgicalCalendar\Api\Handlers\ApplicationsHandler;
use LiturgicalCalendar\Api\Handlers\Ops\HealthHandler;
use LiturgicalCalendar\Api\Handlers\Ops\MigrateHandler;
use LiturgicalCalendar\Api\Handlers\Ops\OpcacheResetHandler;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Http\Middleware\AuthorizationMiddleware;
use LiturgicalCalendar\Api\Http\Middleware\DeployTokenMiddleware;
use LiturgicalCalendar\Api\Http\Middleware\ErrorHandlingMiddleware;
use LiturgicalCalendar\Api\Http\Middleware\HttpsEnforcementMiddleware;
use LiturgicalCalendar\Api\Http\Middleware\JwtAuthMiddleware;
use LiturgicalCalendar\Api\Http\Middleware\JsonBodyParserMiddleware;
use LiturgicalCalendar\Api\Http\Middleware\LoggingMiddleware;
use LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware;
use LiturgicalCalendar\Api\Http\Middleware\ApiKeyMiddleware;
use LiturgicalCalendar\Api\Http\Middleware\ApiKeyRateLimitMiddleware;
use LiturgicalCalendar\Api\Http\Middleware\OidcAvailabilityMiddleware;
use LiturgicalCalendar\Api\Http\Middleware\OpenFgaAuthorizationMiddleware;
use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\TestScopeResolver;
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
     * Determines the liturgical rite from an optional leading rite segment on the
     * calendar or events route, stripping that segment from the request-path parts
     * when present.
     *
     * The calendar route (`calendar`, or the empty root route), the events route
     * (`events`), the regional-data route (`data`) and the lectionary route
     * (`lectionary`) carry a rite segment. A leading
     * segment whose value is a valid
     * {@see Rite} case (`roman`, `ambrosian`) selects that rite and is removed from
     * `$requestPathParts` so the remaining 0/1/2/3-part shape parsing runs identically
     * to an un-prefixed request; `/calendar` and `/calendar/roman` are therefore
     * equivalent, symmetric with `/calendar/ambrosian` (and likewise for `/events`).
     * Absence of a rite segment defaults to Roman. No nation, diocese or path-category
     * identifier collides with a rite value, so this is unambiguous.
     *
     * `data` takes the segment on every method it accepts, but {@see self::canonicalRiteUrl()}
     * advertises the explicit form only for its read methods (`GET` and `POST`). `/data` is also
     * an admin write surface, and a `Link: rel="canonical"` on a `PUT` is noise: it would name a
     * canonical representation of a request that is not a representation at all (#848).
     *
     * Of the two equivalent forms, the explicit one is canonical: a request that omits the
     * rite segment is answered with a `Link: rel="canonical"` header naming the explicit URL
     * (see {@see self::canonicalRiteUrl()}).
     *
     * A missal id can never be mistaken for a rite segment on `/missals`: rite values are
     * lowercase (`roman`, `ambrosian`), missal ids are upper-case (`EDITIO_TYPICA_1970`,
     * `EDITIO_2024`), and `Rite::tryFrom()` only matches the former — the same argument
     * {@see self::extractTestsRite()} makes for test names.
     *
     * @param string       $route            the first path segment (the endpoint), already shifted off
     * @param list<string> $requestPathParts the remaining path segments; the rite segment is removed in place when present
     */
    public static function extractRiteSegment(string $route, array &$requestPathParts): Rite
    {
        if ($route === 'calendar' || $route === '' || $route === 'events' || $route === 'data' || $route === 'lectionary' || $route === 'missals') {
            $maybeRite = Rite::tryFrom((string) ( $requestPathParts[0] ?? '' ));
            if ($maybeRite !== null) {
                array_shift($requestPathParts);
                return $maybeRite;
            }
        }

        return Rite::default();
    }

    /**
     * Resolve the optional leading rite segment on the `/tests` route.
     *
     * `/tests` deliberately does not go through {@see self::extractRiteSegment()}. That
     * helper resolves an absent segment to the default rite, which is right for `/calendar`,
     * `/events` and `/data` — every one of those addresses a single calendar, so "no rite
     * stated" can only sensibly mean "the default one". `/tests` has a *collection* whose
     * historical meaning is "every test regardless of rite", so it needs a third state:
     * null means all rites, and is distinct from an explicit `roman`.
     *
     * A test name can never be mistaken for a rite: `LitCalTest.json` requires names to end
     * in `Test` and the collection globs `*Test.json`, so neither `roman` nor `ambrosian`
     * can name a test.
     *
     * @param list<string> $requestPathParts the path segments following the route; the rite segment is removed in place when present
     * @return Rite|null the rite named by the leading segment, or null when none is present
     */
    public static function extractTestsRite(array &$requestPathParts): ?Rite
    {
        $maybeRite = Rite::tryFrom((string) ( $requestPathParts[0] ?? '' ));
        if ($maybeRite !== null) {
            array_shift($requestPathParts);
            return $maybeRite;
        }

        return null;
    }

    /**
     * Build the absolute canonical URL for a request that omitted the optional rite segment.
     *
     * The explicit rite form (`/calendar/roman/2026`, `/calendar/ambrosian/2026`) is canonical;
     * the bare form (`/calendar/2026`) is retained for backwards compatibility and advertises the
     * canonical form through an RFC 6596 `Link: rel="canonical"` header rather than a redirect.
     *
     * A redirect is deliberately not used: these routes accept POST, a 301/302 would downgrade
     * POST to GET and silently drop the request body, and per the Fetch standard a browser treats
     * any redirect response to a preflighted cross-origin request as a network error — which would
     * break the browser clients that build the bare paths (liturgy-components-js `PathBuilder`).
     *
     * Returns null when no canonical form applies: the request already carried an explicit rite
     * segment, the route carries no rite segment at all, or the request method is not a read
     * method. Only `GET` and `POST` qualify, `POST` because all three route families accept it as
     * the "retrieve with parameters in a request body" spelling of `GET`. That scoping is what
     * lets `/data` take part: it is a mixed read/write surface, and `rel="canonical"` on a `PUT`
     * would describe nothing the request is doing (#848). The same rule keeps the header off a
     * CORS preflight, which is an `OPTIONS` control response rather than a representation of the
     * resource, so no separate preflight guard is needed at the call site.
     *
     * @param string       $route               the first path segment (the endpoint), already shifted off
     * @param string       $method              the request method; only the read methods advertise a canonical form
     * @param bool         $riteSegmentExplicit whether the request already carried a rite segment
     * @param Rite         $rite                the rite resolved for this request
     * @param list<string> $pathParts           the path segments following the route, rite segment already stripped
     * @param string       $query               the raw request query string, preserved on the canonical URL
     */
    public static function canonicalRiteUrl(string $route, string $method, bool $riteSegmentExplicit, Rite $rite, array $pathParts, string $query = ''): ?string
    {
        // The root route resolves to the calendar handler, but canonicalising `/` to
        // `/calendar/roman` would rename the endpoint rather than merely make the rite explicit.
        if ($riteSegmentExplicit || false === in_array($route, ['calendar', 'events', 'data', 'lectionary', 'missals'], true)) {
            return null;
        }

        // Read methods only, which is both what `/data`'s write surface requires and what keeps
        // the header off a CORS preflight (see the note above).
        if (false === in_array($method, [RequestMethod::GET->value, RequestMethod::POST->value], true)) {
            return null;
        }

        $url = self::$apiPath . '/' . implode('/', array_merge([$route, $rite->value], $pathParts));

        return '' === $query ? $url : $url . '?' . $query;
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

        // An optional leading rite segment on the calendar route selects the rite;
        // it is stripped so the existing 0/1/2/3-part shape parsing below runs
        // unchanged on the remainder (see extractRiteSegment()).
        $partCountBeforeRite = count($requestPathParts);
        $rite                = self::extractRiteSegment($route, $requestPathParts);
        $riteSegmentExplicit = count($requestPathParts) < $partCountBeforeRite;

        // Snapshot the post-rite remainder for the canonical URL below: the handlers configured
        // in the switch may consume $requestPathParts, and the canonical form has to mirror the
        // request as it arrived.
        $canonicalPathParts = $requestPathParts;

        // Parse allowed origins from environment (comma-separated list, or '*' for all)
        // This is used for both handler-level CORS and error response CORS
        $allowedOriginsEnv = isset($_ENV['CORS_ALLOWED_ORIGINS']) && is_string($_ENV['CORS_ALLOWED_ORIGINS'])
            ? $_ENV['CORS_ALLOWED_ORIGINS']
            : null;
        $allowedOrigins    = Utilities::parseCorsAllowedOrigins($allowedOriginsEnv);

        // Tri-state rite resolved by the 'tests' case below (see extractTestsRite()):
        // null means "all rites" until that case runs, and stays null for every other
        // route. Declared here so configureAuthorizationPipeline() can be passed the
        // resolved value further down.
        $testsRite = null;

        // The very first response that will need to be submitted by the API,
        // is the response to pre-flight requests.
        // However the preflight response headers will depend on whether the endpoint sets allowed Request Methods,
        // so we should leave the responsibility of handling the preflight response to each endpoint.

        switch ($route) {
            case '':
                // no break (intentional fallthrough)
            case 'calendar':
                $calendarHandler = new CalendarHandler($requestPathParts, $rite);
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
                $missalsHandler = new MissalsHandler($requestPathParts, $rite);
                if (count($requestPathParts) === 0) {
                    $missalsHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST
                    ]);
                } elseif (count($requestPathParts) === 1) {
                    // `/missals/{missal_id}` reads one Missal's sanctorale rows. It is deliberately
                    // NOT writable: a sanctorale entry is spread across the structure file, one
                    // name per locale and one set of readings per locale, and a whole-file replace
                    // can express a rename — which orphans every sidecar entry silently (#943).
                    // Writes address one entry, at `/missals/{missal_id}/{event_key}` below.
                    $missalsHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST
                    ]);
                } elseif (count($requestPathParts) === 2 && $requestPathParts[1] === 'i18n') {
                    // `/missals/{missal_id}/i18n` is the read-only aggregated-translations sidecar (#941).
                    $missalsHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST
                    ]);
                } elseif (count($requestPathParts) === 2) {
                    // `/missals/{missal_id}/{event_key}` — one sanctorale entry (#943). Write-only:
                    // the entry's own read shape is already served by the two routes above, and
                    // adding a third spelling of the same rows would be a second thing to keep in
                    // step with them for no gain.
                    $missalsHandler->setAllowedRequestMethods([
                        RequestMethod::PUT,
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
                    Router::restrictsOriginsForWrite(
                        $this->request->getMethod(),
                        $this->request->getHeaderLine('Access-Control-Request-Method')
                    )
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
                        RequestMethod::POST
                    ]);
                } elseif (count($requestPathParts) === 1) {
                    $decreesHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST,
                        RequestMethod::PUT,
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
                    Router::restrictsOriginsForWrite(
                        $this->request->getMethod(),
                        $this->request->getHeaderLine('Access-Control-Request-Method')
                    )
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
                $eventsHandler = new EventsHandler($requestPathParts, $rite);
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
            case 'lectionary':
                // Read-only surface over the curated lectionary source data (#942): GET only,
                // one path part for a section index and two for a single event_key within it.
                $lectionaryHandler = new LectionaryHandler($requestPathParts, $rite);
                if (count($requestPathParts) <= 2) {
                    $lectionaryHandler->setAllowedRequestMethods([RequestMethod::GET]);
                } else {
                    $lectionaryHandler->setAllowedRequestMethods([]);
                }
                $lectionaryHandler->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                $this->handler = $lectionaryHandler;
                break;
            case 'validations':
                $validationsHandler = new ValidationsHandler($requestPathParts);
                $validationsHandler->setAllowedRequestMethods([RequestMethod::GET]);
                $this->handler = $validationsHandler;
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
                    } elseif ($authRoute === 'access-requests') {
                        // Unified access request routes for authenticated users
                        // POST /auth/access-requests - Submit access request (role + permissions)
                        // GET /auth/access-requests - View own requests
                        // GET /auth/access-requests/status - Check access status
                        $accessRequestHandler = new AccessRequestHandler();
                        $this->handler        = $accessRequestHandler;
                    } elseif ($authRoute === 'email-verification') {
                        // Email verification routes for authenticated users
                        // POST /auth/email-verification/resend - Resend verification email
                        $emailVerificationHandler = new EmailVerificationHandler();
                        $this->handler            = $emailVerificationHandler;
                    } elseif ($authRoute === 'notifications') {
                        // User notifications routes (issue #573)
                        // GET  /auth/notifications        - Inbox + unread badge
                        // POST /auth/notifications/seen   - Mark inbox seen
                        $notificationsHandler = new NotificationsHandler();
                        $this->handler        = $notificationsHandler;
                    } elseif ($authRoute === 'admin-scopes') {
                        // GET /auth/admin-scopes - Report caller's global/resource admin status + scopes
                        $adminScopesHandler = new AdminScopesHandler();
                        $this->handler      = $adminScopesHandler;
                    } elseif ($authRoute === 'change-requests') {
                        // GET  /auth/change-requests                    - The caller's own change requests
                        // GET  /auth/change-requests/{batchId}           - One of them, with its proposed file content
                        // POST /auth/change-requests/{batchId}/withdraw - Withdraw one of them
                        $this->handler = new ChangeRequestHandler($requestPathParts);
                    } elseif ($authRoute === 'test-scopes') {
                        // GET /auth/test-scopes - Report caller's test editor/admin scopes
                        $testScopesHandler = new TestScopesHandler();
                        $this->handler     = $testScopesHandler;
                    } elseif ($authRoute === 'dashboard-scopes') {
                        // GET /auth/dashboard-scopes - Batched admin/viewer scopes for the frontend admin dashboard
                        $dashboardScopesHandler = new DashboardScopesHandler();
                        $this->handler          = $dashboardScopesHandler;
                    } else {
                        $this->response = new Response(StatusCode::NOT_FOUND->value, [], null, $this->request->getProtocolVersion(), StatusCode::NOT_FOUND->reason());
                        $this->emitResponse();
                    }
                } else {
                    $this->response = new Response(StatusCode::NOT_FOUND->value, [], null, $this->request->getProtocolVersion(), StatusCode::NOT_FOUND->reason());
                    $this->emitResponse();
                }
                Router::restrictOriginsForPrivateRoute($this->handler, $allowedOrigins);
                break;
            case 'admin':
                // Handle admin routes
                if (count($requestPathParts) >= 1) {
                    $adminRoute = $requestPathParts[0];
                    if ($adminRoute === 'access-requests') {
                        // Unified access request management routes
                        // GET  /admin/access-requests - List requests
                        // POST /admin/access-requests/{id}/approve - Approve (role + tuples)
                        // POST /admin/access-requests/{id}/reject - Reject
                        // POST /admin/access-requests/{id}/revoke - Revoke (role + tuples)
                        $accessRequestAdminHandler = new AccessRequestAdminHandler();
                        $this->handler             = $accessRequestAdminHandler;
                    } elseif ($adminRoute === 'notifications') {
                        // Admin notifications route
                        // GET /admin/notifications - Get counts of pending items
                        $notificationsHandler = new AdminNotificationsHandler();
                        $this->handler        = $notificationsHandler;
                    } elseif ($adminRoute === 'users') {
                        // Admin users management routes
                        // GET /admin/users - List all users with roles
                        // DELETE /admin/users/{userId}/roles/{role} - Revoke a role
                        $usersHandler  = new UsersHandler();
                        $this->handler = $usersHandler;
                    } elseif ($adminRoute === 'permissions') {
                        // Admin permission management routes (OpenFGA)
                        // GET    /admin/permissions       - List permissions (with filters)
                        // POST   /admin/permissions       - Grant permission
                        // DELETE /admin/permissions       - Revoke permission
                        // GET    /admin/permissions/check - Check a specific permission
                        $permissionAdminHandler = new PermissionAdminHandler();
                        $this->handler          = $permissionAdminHandler;
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
                    } elseif ($adminRoute === 'locales') {
                        // Supported-locale curation (#904, #926)
                        // GET  /admin/locales                  - Candidate locales, official flag, readiness
                        // GET  /admin/locales/{locale}         - Full readiness report for one locale
                        // POST /admin/locales/{locale}/promote - Add the locale to the official set
                        // POST /admin/locales/{locale}/demote  - Remove it again
                        $localesAdminHandler = new LocalesAdminHandler($requestPathParts);
                        $this->handler       = $localesAdminHandler;
                    } elseif ($adminRoute === 'outbox') {
                        // Admin outbox management routes
                        // GET  /admin/outbox?status=…&summary=…  - List/summary
                        // POST /admin/outbox/{id}/retry           - Reset failed_terminal row to pending
                        $outboxAdminHandler = new OutboxAdminHandler();
                        $this->handler      = $outboxAdminHandler;
                    } elseif ($adminRoute === 'change-requests') {
                        // Source-data change request review queue and history
                        // GET  /admin/change-requests                   - Review queue and history
                        // GET  /admin/change-requests/{batchId}          - One batch, with its proposed file content
                        // POST /admin/change-requests/{batchId}/approve - Approve a batch
                        // POST /admin/change-requests/{batchId}/reject  - Reject a batch
                        $this->handler = new ChangeRequestAdminHandler($requestPathParts);
                    } else {
                        $this->response = new Response(StatusCode::NOT_FOUND->value, [], null, $this->request->getProtocolVersion(), StatusCode::NOT_FOUND->reason());
                        $this->emitResponse();
                    }
                } else {
                    $this->response = new Response(StatusCode::NOT_FOUND->value, [], null, $this->request->getProtocolVersion(), StatusCode::NOT_FOUND->reason());
                    $this->emitResponse();
                }
                Router::restrictOriginsForPrivateRoute($this->handler, $allowedOrigins);
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
                $regionalDataHandler = new RegionalDataHandler($requestPathParts, $rite);
                $pathCount           = count($requestPathParts);
                $firstInCategory     = $pathCount > 0 && in_array($requestPathParts[0], PathCategory::values(), true);
                $allowedMethods      = match (true) {
                    $pathCount === 2 && $firstInCategory => [
                        RequestMethod::GET,
                        RequestMethod::POST,
                        RequestMethod::PUT,
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
                    Router::restrictsOriginsForWrite(
                        $this->request->getMethod(),
                        $this->request->getHeaderLine('Access-Control-Request-Method')
                    )
                    && false === Router::isLocalhost()
                ) {
                    $regionalDataHandler->setAllowedOrigins($allowedOrigins);
                }
                $this->handler = $regionalDataHandler;
                break;
            case 'tests':
                // Strip the rite segment BEFORE the count-based method wiring below, so
                // /tests/ambrosian behaves exactly like /tests (collection) and
                // /tests/ambrosian/{name} exactly like a one-part item route.
                $testsRite    = self::extractTestsRite($requestPathParts);
                $testsHandler = new TestsHandler($requestPathParts, $testsRite);
                if (count($requestPathParts) === 0) {
                    $testsHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST
                    ]);
                } elseif (count($requestPathParts) === 1) {
                    $testsHandler->setAllowedRequestMethods([
                        RequestMethod::GET,
                        RequestMethod::POST,
                        RequestMethod::PUT,
                        RequestMethod::PATCH,
                        RequestMethod::DELETE
                    ]);
                } else {
                    $testsHandler->setAllowedRequestMethods([]);
                }
                // Request bodies are JSON-only (issue #790 follow-up): OpenFgaAuthorizationMiddleware's
                // scope resolvers read getParsedBody(), which JsonBodyParserMiddleware only populates
                // for application/json — a YAML or form-urlencoded PUT/PATCH body was never actually
                // authorizable, it just failed inconsistently (403 when OpenFGA is configured, silently
                // "worked" when it is not) instead of failing predictably. openapi.json already declares
                // only application/json for these request bodies, so this makes the handler match what
                // was already the documented and only-working contract. Response content types are
                // unaffected — YAML remains a supported Accept header below.
                $testsHandler->setAllowedRequestContentTypes([
                    RequestContentType::JSON,
                ])->setAllowedAcceptHeaders([
                    AcceptHeader::JSON,
                    AcceptHeader::YAML
                ]);
                // TestsHandler allows credentials (cookie-authenticated writes from the
                // admin-tests page) but was the only such route never given the allow-list,
                // so every origin was echoed back with Access-Control-Allow-Credentials.
                // AUTHENTICATION_ROADMAP lists "other write operations" as origin-specific;
                // /tests PUT/PATCH/DELETE are exactly that.
                if (
                    Router::restrictsOriginsForWrite(
                        $this->request->getMethod(),
                        $this->request->getHeaderLine('Access-Control-Request-Method')
                    )
                    && false === Router::isLocalhost()
                ) {
                    $testsHandler->setAllowedOrigins($allowedOrigins);
                }
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
                    Router::restrictsOriginsForWrite(
                        $this->request->getMethod(),
                        $this->request->getHeaderLine('Access-Control-Request-Method')
                    )
                    && false === Router::isLocalhost()
                ) {
                    $temporaleHandler->setAllowedOrigins($allowedOrigins);
                }
                $this->handler = $temporaleHandler;
                break;
            case 'health':
                $healthHandler = new HealthHandler();
                $healthHandler->setAllowedRequestMethods([RequestMethod::GET]);
                $this->handler = $healthHandler;
                break;
            case '_ops':
                if (count($requestPathParts) === 1 && $requestPathParts[0] === 'migrate') {
                    $migrateHandler = new MigrateHandler();
                    $migrateHandler->setAllowedRequestMethods([RequestMethod::POST]);
                    $this->handler = $migrateHandler;
                } elseif (count($requestPathParts) === 2 && $requestPathParts[0] === 'migrate' && $requestPathParts[1] === 'status') {
                    $migrateHandler = new MigrateHandler();
                    $migrateHandler->setAllowedRequestMethods([RequestMethod::GET]);
                    $this->handler = $migrateHandler;
                } elseif (count($requestPathParts) === 1 && $requestPathParts[0] === 'opcache-reset') {
                    $opcacheResetHandler = new OpcacheResetHandler();
                    $opcacheResetHandler->setAllowedRequestMethods([RequestMethod::POST]);
                    $this->handler = $opcacheResetHandler;
                } else {
                    $this->response = new Response(StatusCode::NOT_FOUND->value, [], null, $this->request->getProtocolVersion(), StatusCode::NOT_FOUND->reason());
                    $this->emitResponse();
                }
                break;
            default:
                $this->response = new Response(StatusCode::NOT_FOUND->value, [], null, $this->request->getProtocolVersion(), StatusCode::NOT_FOUND->reason());
                $this->emitResponse();
        }

        $pipeline = new MiddlewarePipeline($this->handler);
        $pipeline->pipe(new ErrorHandlingMiddleware($this->psr17Factory, self::$debug, $allowedOrigins)); // outermost middleware
        $pipeline->pipe(new LoggingMiddleware(self::$debug));

        // Parse JSON request bodies into getParsedBody() for all routes
        $pipeline->pipe(new JsonBodyParserMiddleware());

        // Apply API key validation and rate limiting for public API routes.
        // /health is an unauthenticated monitoring endpoint — exclude it from
        // the API-key check but KEEP IP-based rate limiting, because /health
        // touches openfga_outbox and PG on every request; without rate
        // limiting an unauthenticated flood could exhaust DB capacity.
        $skipAuthRoutes = ['auth', 'admin', 'applications', '_ops', 'health'];
        if (!in_array($route, $skipAuthRoutes, true)) {
            if (Connection::isConfigured()) {
                $pipeline->pipe(new ApiKeyMiddleware(
                    new \LiturgicalCalendar\Api\Repositories\ApiKeyRepository(),
                    new \LiturgicalCalendar\Api\Repositories\AuditLogRepository()
                ));
                $pipeline->pipe(ApiKeyRateLimitMiddleware::fromEnv());
            } else {
                // Without a database, API key validation is unavailable.
                // Apply IP-only rate limiting for unauthenticated requests.
                $pipeline->pipe(ApiKeyRateLimitMiddleware::fromEnv());
            }
        } elseif ($route === 'health') {
            // /health still needs IP-based throttling — see comment above.
            $pipeline->pipe(ApiKeyRateLimitMiddleware::fromEnv());
        }

        // Apply HTTPS enforcement middleware for auth, admin, and applications routes in production
        if (in_array($route, ['auth', 'admin', 'applications', '_ops'], true)) {
            $pipeline->pipe(new HttpsEnforcementMiddleware());
        }

        // Deploy token authentication for /_ops routes.
        if ($route === '_ops') {
            $pipeline->pipe(new DeployTokenMiddleware());
        }

        // Apply OIDC authentication for auth routes (access-requests, email-verification, notifications), admin, and applications
        // These routes need the oidc_user attribute set before the handler checks authentication
        if (
            ( $route === 'auth' && count($requestPathParts) >= 1 && in_array($requestPathParts[0], ['access-requests', 'email-verification', 'notifications', 'admin-scopes', 'test-scopes', 'dashboard-scopes', 'change-requests'], true) )
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
            in_array($route, ['data', 'tests', 'temporale', 'missals', 'decrees'], true)
            && in_array($this->request->getMethod(), [RequestMethod::PUT->value, RequestMethod::PATCH->value, RequestMethod::DELETE->value], true)
        ) {
            // Use OIDC (Zitadel) authentication if configured (with legacy JWT fallback),
            // otherwise use JWT-only authentication
            if (self::isOidcConfigured()) {
                $pipeline->pipe(OidcAuthMiddleware::fromEnv(jwtFallback: true));
            } else {
                $pipeline->pipe(new JwtAuthMiddleware());
            }

            // Apply authorization middleware with role-based access control
            // (shared between OIDC and JWT paths)
            $this->configureAuthorizationPipeline($pipeline, $route, $requestPathParts, $rite, $testsRite);
        }

        $this->response = $pipeline->handle($this->request)
            ->withHeader('X-Request-Id', $this->requestId);

        // A request that omitted the optional rite segment advertises the canonical explicit-rite
        // form (RFC 6596), on read methods only — canonicalRiteUrl() applies that rule, which is
        // also what keeps the header off a CORS preflight. Only on success: pointing at a
        // canonical URL for a request that did not resolve would just name an equally invalid URL.
        $canonicalUrl = self::canonicalRiteUrl(
            $route,
            $this->request->getMethod(),
            $riteSegmentExplicit,
            $rite,
            $canonicalPathParts,
            $this->request->getUri()->getQuery()
        );
        // 304 is included alongside 2xx: a resolved conditional request stands in for the 200 it
        // would otherwise have been and describes the same resource, so a client driving its own
        // conditional requests should not lose the canonical URL merely because its cache was
        // still fresh.
        $status = $this->response->getStatusCode();
        if (null !== $canonicalUrl && ( ( $status >= 200 && $status < 300 ) || $status === StatusCode::NOT_MODIFIED->value )) {
            $this->response = $this->response->withHeader('Link', '<' . $canonicalUrl . '>; rel="canonical"');
        }

        // A cross-origin browser client can only read the CORS-safelisted response headers, so
        // every header this API sets itself is invisible to JavaScript unless named here (Fetch
        // standard). Without this, a browser client cannot quote `X-Request-Id` in a bug report,
        // nor echo an `ETag` back as `If-None-Match` when driving its own conditional requests.
        // `ETag` and `Link` are only advertised on the responses that actually carry them.
        $exposedHeaders = ['X-Request-Id'];
        foreach (['ETag', 'Link'] as $conditionalHeader) {
            if ($this->response->hasHeader($conditionalHeader)) {
                $exposedHeaders[] = $conditionalHeader;
            }
        }
        $this->response = $this->response->withHeader('Access-Control-Expose-Headers', implode(', ', $exposedHeaders));

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
     * @param Rite $rite The rite selected by the route's optional rite segment; qualifies the
     *                   calendar object ids the FGA middleware checks against (issue #786)
     * @param Rite|null $testsRite The rite resolved by {@see Router::extractTestsRite()} for the
     *                             `tests` route only; null means "all rites" (or n/a for other routes)
     */
    private function configureAuthorizationPipeline(
        MiddlewarePipeline $pipeline,
        string $route,
        array $requestPathParts,
        Rite $rite = Rite::ROMAN,
        ?Rite $testsRite = null
    ): void {
        // Cache a single OpenFGA client for the pipeline (avoid multiple fromEnv calls)
        $fgaClient = OpenFgaClient::isConfigured() ? OpenFgaClient::fromEnv() : null;

        // OpenFgaAuthorizationMiddleware reads the 'oidc_user' attribute, which is
        // only populated by OidcAuthMiddleware (including its JWT-fallback path).
        // The pure JwtAuthMiddleware path does not set 'oidc_user', so skip the
        // FGA middleware there to avoid an unconditional UnauthorizedException.
        $oidcAvailable = self::isOidcConfigured();

        // Role-based authorization (Zitadel roles)
        if ($route === 'data') {
            $pipeline->pipe(AuthorizationMiddleware::forCalendarEditor());

            // Set calendar_id attribute for OpenFGA check
            if (count($requestPathParts) >= 2) {
                $this->request = $this->request->withAttribute('calendar_id', $requestPathParts[1]);
            }

            // OpenFGA fine-grained authorization (runs after role check)
            if ($oidcAvailable && $fgaClient !== null && count($requestPathParts) >= 2) {
                $fgaMiddleware = OpenFgaAuthorizationMiddleware::forCalendarData(
                    $fgaClient,
                    $requestPathParts[0],
                    $rite
                );
                if ($fgaMiddleware !== null) {
                    $pipeline->pipe($fgaMiddleware);
                }
            }
        } elseif ($route === 'tests') {
            $pipeline->pipe(AuthorizationMiddleware::forTestEditor());

            // OpenFGA fine-grained authorization for test definitions
            if ($oidcAvailable && $fgaClient !== null && count($requestPathParts) >= 1) {
                $this->request = $this->request
                    ->withAttribute('test_id', $requestPathParts[0])
                    ->withAttribute('test_rite', $testsRite?->value);
                $pipeline->pipe(OpenFgaAuthorizationMiddleware::forTestScopes($fgaClient, new TestScopeResolver()));
                // Union check (#790): a PATCH that re-scopes a test also needs editor on the
                // payload-derived target scope, not just the stored one above. Inert for
                // PUT/DELETE — see forTestScopePayloadTarget()'s docblock.
                $pipeline->pipe(OpenFgaAuthorizationMiddleware::forTestScopePayloadTarget($fgaClient, new TestScopeResolver()));
            }
        } elseif ($route === 'temporale') {
            $pipeline->pipe(AuthorizationMiddleware::forCalendarEditor());
            if ($oidcAvailable && $fgaClient !== null) {
                $pipeline->pipe(OpenFgaAuthorizationMiddleware::forGeneralRomanCalendar($fgaClient, 'temporale'));
            }
        } elseif ($route === 'decrees') {
            $pipeline->pipe(AuthorizationMiddleware::forCalendarEditor());
            if ($oidcAvailable && $fgaClient !== null) {
                $pipeline->pipe(OpenFgaAuthorizationMiddleware::forGeneralRomanCalendar(
                    $fgaClient,
                    'decrees',
                    ['PUT' => 'editor', 'PATCH' => 'editor', 'DELETE' => 'admin']
                ));
            }
        } elseif ($route === 'missals') {
            // Writes are authorized per-missal: calendar_editor role plus fine-grained FGA
            // (Editio Typica -> general_roman_calendar, national missal -> national_calendar).
            // The missal id is path part 0 for both the (unrouted) collection-item spelling and
            // the entry spelling `/missals/{missal_id}/{event_key}` that writes actually use, so
            // one guard covers both. An id-less write is not routed and never reaches the handler.
            $pipeline->pipe(AuthorizationMiddleware::forCalendarEditor());
            if ($oidcAvailable && $fgaClient !== null && count($requestPathParts) >= 1) {
                $pipeline->pipe(OpenFgaAuthorizationMiddleware::forMissals($fgaClient, $requestPathParts[0]));
            }
        }
    }

    /**
     * Returns true if the server is running on localhost.
     *
     * @return bool true if the server is running on localhost, false otherwise
     */
    /**
     * HTTP methods whose cross-origin use is restricted to the configured allow-list.
     *
     * @var string[]
     */
    private const ORIGIN_RESTRICTED_METHODS = [
        RequestMethod::PUT->value,
        RequestMethod::PATCH->value,
        RequestMethod::DELETE->value
    ];

    /**
     * Whether the CORS allow-list must be applied to this request.
     *
     * True for a write, and — crucially — for the CORS preflight that precedes one.
     * A preflight arrives as OPTIONS and names the method it is clearing in
     * Access-Control-Request-Method, so gating on the request method alone left the
     * preflight configured with the default wildcard. For a handler that allows
     * credentials that meant the browser was told the write was permitted from any
     * origin, so it sent the write with the user's cookies and the API executed it.
     * Restricting the origin on the write response governs only whether the response
     * can be read, never whether the request runs — so the preflight is the only
     * point at which a cross-origin write can actually be refused.
     *
     * Pure and static so the decision is testable: Router::route() emits and calls
     * die(), and cannot be exercised in-process.
     *
     * @param string $requestMethod   The HTTP method of the request as it arrived.
     * @param string $preflightMethod The Access-Control-Request-Method header, '' when absent.
     */
    public static function restrictsOriginsForWrite(string $requestMethod, string $preflightMethod = ''): bool
    {
        $method = strtoupper($requestMethod);

        if (in_array($method, self::ORIGIN_RESTRICTED_METHODS, true)) {
            return true;
        }

        return $method === RequestMethod::OPTIONS->value
            && in_array(strtoupper($preflightMethod), self::ORIGIN_RESTRICTED_METHODS, true);
    }

    /**
     * Hand the configured origin allow-list to the resolved handler of a wholly private route.
     *
     * /auth and /admin differ from the public routes in kind, not degree: they have no
     * anonymous read. Every method on them is cookie-authenticated, so restricting only
     * writes and their preflights — as restrictsOriginsForWrite() does — would leave
     * `POST /auth/login` and `GET /auth/me` reflecting any Origin back with
     * Access-Control-Allow-Credentials: true. The list therefore applies to every method
     * here.
     *
     * Applied once after the sub-dispatch rather than in each of the ~16 branches that
     * resolve a handler, so a new endpoint added to either route inherits it by default
     * instead of having to remember it.
     *
     * Static and taking the handler explicitly, like restrictsOriginsForWrite(), so the
     * decision is testable: Router::route() emits and calls die(), so anything reading
     * $this->handler from inside it is unreachable by any test.
     *
     * @param RequestHandlerInterface $handler        The handler the sub-dispatch resolved.
     * @param string[]                $allowedOrigins
     */
    public static function restrictOriginsForPrivateRoute(RequestHandlerInterface $handler, array $allowedOrigins): void
    {
        if ($handler instanceof AbstractHandler && false === Router::isLocalhost()) {
            $handler->setAllowedOrigins($allowedOrigins);
        }
    }

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

    /**
     * Resolve the host used to build the API's own base URL (self-referential requests).
     *
     * Prefers SERVER_NAME, falls back to SERVER_ADDR, then to 'localhost'. The wildcard
     * bind address 0.0.0.0 (reported by SERVER_NAME/SERVER_ADDR under `php -S 0.0.0.0:8000`,
     * as used in CI containers) is normalized to the loopback address: 0.0.0.0 is valid for
     * binding but not reliably routable as a request target, so self-calls such as
     * RegionalDataHandler validating against /calendars would otherwise fail.
     */
    public static function resolveServerHost(): string
    {
        $host = isset($_SERVER['SERVER_NAME']) && is_string($_SERVER['SERVER_NAME']) && '' !== $_SERVER['SERVER_NAME']
            ? $_SERVER['SERVER_NAME']
            : (
                isset($_SERVER['SERVER_ADDR']) && is_string($_SERVER['SERVER_ADDR']) && '' !== $_SERVER['SERVER_ADDR']
                ? $_SERVER['SERVER_ADDR']
                : 'localhost'
            );

        if ('0.0.0.0' === $host) {
            $host = '127.0.0.1';
        }

        return $host;
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
        $api_full_path .= self::resolveServerHost();


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
        // Captured immediately after the is_string() narrowing above (and before any further
        // static method calls) so PHPStan can keep tracking $_SERVER['SCRIPT_FILENAME'] as a string.
        $indexPath = $_SERVER['SCRIPT_FILENAME'];
        if (false === Router::isLocalhost() && ( false === isset($_ENV['API_BASE_PATH']) || false === is_string($_ENV['API_BASE_PATH']) || empty($_ENV['API_BASE_PATH']) )) {
            throw new ServiceUnavailableException('The API_BASE_PATH environment variable must be set in production environments.');
        } else {
            /** @var string $api_base_path */
            $api_base_path = $_ENV['API_BASE_PATH'];
        }

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
