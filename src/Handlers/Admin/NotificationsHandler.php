<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Admin;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Repositories\ApplicationRepository;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\ResourceAdminService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Notifications Handler
 *
 * Returns notification counts for admin users.
 * GET /admin/notifications - Get counts of pending items
 *
 * Requires admin role or OpenFGA resource-admin access.
 * Global admins receive unscoped counts across all resources;
 * resource-admins receive counts scoped to their administered resources.
 */
final class NotificationsHandler extends AbstractHandler
{
    private ?AccessRequestRepository $accessRequestRepo = null;
    private ?ApplicationRepository $applicationRepo     = null;
    private ?OpenFgaClient $fgaClient                   = null;

    public function __construct(?OpenFgaClient $fgaClient = null)
    {
        parent::__construct();

        $this->fgaClient             = $fgaClient;
        $this->allowedRequestMethods = [RequestMethod::GET];
        $this->allowedAcceptHeaders  = [AcceptHeader::JSON];
        $this->allowCredentials      = true;
    }

    private function isFgaClientAvailable(): bool
    {
        return $this->fgaClient !== null || OpenFgaClient::isConfigured();
    }

    private function getFgaClient(): OpenFgaClient
    {
        if ($this->fgaClient === null) {
            $this->fgaClient = OpenFgaClient::fromEnv();
        }
        return $this->fgaClient;
    }

    private function getAccessRequestRepository(): AccessRequestRepository
    {
        if ($this->accessRequestRepo === null) {
            $this->accessRequestRepo = new AccessRequestRepository();
        }
        return $this->accessRequestRepo;
    }

    private function getApplicationRepository(): ApplicationRepository
    {
        if ($this->applicationRepo === null) {
            $this->applicationRepo = new ApplicationRepository();
        }
        return $this->applicationRepo;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = static::initResponse($request);
        $method   = RequestMethod::from($request->getMethod());

        if ($method === RequestMethod::OPTIONS) {
            return $this->handlePreflightRequest($request, $response);
        }

        $response = $this->setAccessControlAllowOriginHeader($request, $response);
        $this->validateRequestMethod($request);

        $mime     = $this->validateAcceptHeader($request, AcceptabilityLevel::LAX);
        $response = $response->withHeader('Content-Type', $mime);

        // Check authentication via OIDC token
        /** @var array{sub?: string, roles?: array<string>}|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');

        if ($oidcUser === null) {
            throw new UnauthorizedException('Authentication required');
        }

        $isGlobalAdmin = OidcAuthMiddleware::isAdmin($oidcUser);
        $sub           = $oidcUser['sub'] ?? null;

        // Get notification counts
        $notifications = [
            'pending_access_requests' => 0,
            'pending_applications'    => 0,
            'total'                   => 0,
            'items'                   => [],
        ];

        if ($isGlobalAdmin) {
            if (Connection::isConfigured()) {
                $notifications = $this->buildGlobalAdminNotifications($notifications);
            }
        } else {
            // Resource-admin path: admit only callers who hold an OpenFGA admin
            // tuple on at least one resource. Fail closed when FGA is unavailable.
            if (!$this->isFgaClientAvailable() || !is_string($sub) || trim($sub) === '') {
                throw new ForbiddenException('Admin role required');
            }

            $scopeService = new ResourceAdminService($this->getFgaClient());
            if ($scopeService->resolveScopes($sub) === []) {
                throw new ForbiddenException('Admin role required');
            }

            if (Connection::isConfigured()) {
                $notifications = $this->buildResourceAdminNotifications($notifications, $scopeService, $sub);
            }
        }

        // Add Cache-Control header to prevent caching
        $response = $response->withHeader('Cache-Control', 'no-store');

        return $this->encodeResponseBody($response, $notifications);
    }

    /**
     * Build the unscoped (global-admin) notification payload.
     *
     * @param array{pending_access_requests: int, pending_applications: int, total: int, items: array<int, array<string, mixed>>} $notifications
     * @return array{pending_access_requests: int, pending_applications: int, total: int, items: array<int, array<string, mixed>>}
     */
    private function buildGlobalAdminNotifications(array $notifications): array
    {
        $accessRequestRepo                        = $this->getAccessRequestRepository();
        $notifications['pending_access_requests'] = $accessRequestRepo->countPending();

        // getPending() returns oldest-first (ORDER BY created_at ASC),
        // so take the last 5 and reverse to display newest-first.
        $pendingRequests = $accessRequestRepo->getPending();
        $recentPending   = array_reverse(array_slice($pendingRequests, -5));
        foreach ($recentPending as $req) {
            $notifications['items'][] = $this->accessRequestItem($req);
        }

        $applicationRepo                       = $this->getApplicationRepository();
        $notifications['pending_applications'] = $applicationRepo->countPendingApplications();

        // getPendingApplications() returns oldest-first (ORDER BY created_at ASC),
        // so take the last 5 to include the most recent pending applications.
        $pendingApps = $applicationRepo->getPendingApplications();
        foreach (array_slice($pendingApps, -5) as $app) {
            $notifications['items'][] = [
                'type'            => 'application',
                'id'              => $app['id'] ?? '',
                'app_name'        => $app['name'] ?? 'Unknown',
                'zitadel_user_id' => $app['zitadel_user_id'] ?? '',
                'requested_scope' => $app['requested_scope'] ?? 'read',
                'created_at'      => $app['created_at'] ?? '',
                'url'             => 'admin-applications.php',
            ];
        }

        usort($notifications['items'], function ($a, $b) {
            $aDate = is_string($a['created_at']) ? $a['created_at'] : '';
            $bDate = is_string($b['created_at']) ? $b['created_at'] : '';
            return strcmp($bDate, $aDate);
        });
        $notifications['items'] = array_slice($notifications['items'], 0, 5);

        $notifications['total'] = $notifications['pending_access_requests']
                                + $notifications['pending_applications'];

        return $notifications;
    }

    /**
     * Build the scoped (resource-admin) notification payload: only the pending
     * access-requests the caller administers; no applications.
     *
     * @param array{pending_access_requests: int, pending_applications: int, total: int, items: array<int, array<string, mixed>>} $notifications
     * @return array{pending_access_requests: int, pending_applications: int, total: int, items: array<int, array<string, mixed>>}
     */
    private function buildResourceAdminNotifications(
        array $notifications,
        ResourceAdminService $scopeService,
        string $sub
    ): array {
        $pendingRequests = $this->getAccessRequestRepository()->getPending();
        $scoped          = $scopeService->filterByAdminAccess($pendingRequests, $sub);

        $notifications['pending_access_requests'] = count($scoped);
        $notifications['pending_applications']    = 0;
        $notifications['total']                   = count($scoped);

        // getPending() is oldest-first; filter preserves order. Newest 5, newest-first.
        $recentScoped = array_reverse(array_slice($scoped, -5));
        foreach ($recentScoped as $req) {
            $notifications['items'][] = $this->accessRequestItem($req);
        }

        return $notifications;
    }

    /**
     * Build a single access_request notification item.
     *
     * @param array<string, mixed> $req
     * @return array<string, mixed>
     */
    private function accessRequestItem(array $req): array
    {
        $displayName = !empty($req['user_name'])
            ? $req['user_name']
            : ( !empty($req['user_email'])
                ? $req['user_email']
                : ( 'User ' . substr(is_string($req['zitadel_user_id'] ?? null) ? $req['zitadel_user_id'] : '', -6) ) );

        return [
            'type'       => 'access_request',
            'id'         => $req['id'] ?? '',
            'user_name'  => $displayName,
            'user_email' => $req['user_email'] ?? '',
            'role'       => $req['requested_role'] ?? '',
            'created_at' => $req['created_at'] ?? '',
            'url'        => 'admin-permissions.php',
        ];
    }
}
