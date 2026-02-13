<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Admin;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware;
use LiturgicalCalendar\Api\Repositories\RoleRequestRepository;
use LiturgicalCalendar\Api\Services\ZitadelService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Role Request Admin Handler
 *
 * Handles admin operations for role requests:
 * - GET /admin/role-requests - List all pending requests
 * - POST /admin/role-requests/{id}/approve - Approve a request
 * - POST /admin/role-requests/{id}/reject - Reject a request
 *
 * Requires admin role.
 */
final class RoleRequestAdminHandler extends AbstractHandler
{
    private ?RoleRequestRepository $repository = null;

    public function __construct()
    {
        parent::__construct();

        $this->allowedRequestMethods      = [RequestMethod::GET, RequestMethod::POST];
        $this->allowedAcceptHeaders       = [AcceptHeader::JSON];
        $this->allowedRequestContentTypes = [RequestContentType::JSON];
        $this->allowCredentials           = true;
    }

    private function getRepository(): RoleRequestRepository
    {
        if ($this->repository === null) {
            $this->repository = new RoleRequestRepository();
        }
        return $this->repository;
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

        // Verify admin role
        if (!OidcAuthMiddleware::isAdmin($oidcUser)) {
            throw new ForbiddenException('Admin role required');
        }

        $adminId = $oidcUser['sub'] ?? '';
        if (empty($adminId)) {
            throw new UnauthorizedException('Invalid authentication token');
        }

        if (!Connection::isConfigured()) {
            throw new \RuntimeException('Database not configured');
        }

        // Parse path to determine action
        $path      = $request->getUri()->getPath();
        $pathParts = explode('/', trim($path, '/'));

        // Expected paths:
        // admin/role-requests
        // admin/role-requests/{id}/approve
        // admin/role-requests/{id}/reject

        if ($method === RequestMethod::GET) {
            return $this->listRequests($request, $response);
        }

        // POST requires an action (approve/reject)
        $partCount = count($pathParts);
        if ($partCount < 4) {
            throw new ValidationException('Invalid request path');
        }

        // Parse from the end to handle different route prefixes
        $action    = $pathParts[$partCount - 1];
        $requestId = $pathParts[$partCount - 2];

        // Validate UUID format
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $requestId)) {
            throw new ValidationException('Invalid request ID format');
        }

        $body  = $request->getParsedBody();
        $notes = null;
        if (is_array($body) && isset($body['notes']) && is_string($body['notes'])) {
            $notes = $body['notes'];
        }

        if ($action === 'approve') {
            return $this->approveRequest($response, $requestId, $adminId, $notes);
        }

        if ($action === 'reject') {
            return $this->rejectRequest($response, $requestId, $adminId, $notes);
        }

        if ($action === 'revoke') {
            return $this->revokeRequest($response, $requestId, $adminId, $notes);
        }

        throw new ValidationException('Invalid action. Use "approve", "reject", or "revoke"');
    }

    /**
     * List role requests with optional status filter.
     *
     * Query parameters:
     * - status: Filter by status (pending, approved, rejected). If omitted, returns all.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    private function listRequests(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $repo         = $this->getRepository();
        $queryParams  = $request->getQueryParams();
        $statusFilter = isset($queryParams['status']) && is_string($queryParams['status'])
            ? $queryParams['status']
            : null;

        // Validate status if provided (use repository constant to avoid drift)
        if ($statusFilter !== null && !in_array($statusFilter, RoleRequestRepository::VALID_STATUSES, true)) {
            throw new ValidationException(
                sprintf('Invalid status. Valid values are: %s', implode(', ', RoleRequestRepository::VALID_STATUSES))
            );
        }

        $requests = $repo->getAllRequests($statusFilter);
        $counts   = $repo->getRequestCounts();

        // Group requests by status for frontend convenience
        $grouped = [
            'pending'  => [],
            'approved' => [],
            'rejected' => [],
            'revoked'  => [],
        ];

        foreach ($requests as $req) {
            $status = $req['status'] ?? 'pending';
            if (is_string($status) && isset($grouped[$status])) {
                $grouped[$status][] = $req;
            }
        }

        return $this->encodeResponseBody($response, [
            'requests'         => $requests,
            'pending_requests' => $grouped['pending'],  // Backward compatibility
            'approved'         => $grouped['approved'],
            'rejected'         => $grouped['rejected'],
            'revoked'          => $grouped['revoked'],
            'counts'           => $counts,
        ]);
    }

    /**
     * Approve a role request.
     *
     * The database transaction is committed before calling Zitadel to avoid
     * holding locks during external API calls. If Zitadel sync fails, the
     * request remains approved but with a 'failed' sync status for retry.
     *
     * @param ResponseInterface $response
     * @param string $requestId Request UUID
     * @param string $adminId
     * @param string|null $notes
     * @return ResponseInterface
     */
    private function approveRequest(
        ResponseInterface $response,
        string $requestId,
        string $adminId,
        ?string $notes
    ): ResponseInterface {
        $repo = $this->getRepository();

        // Step 1: Approve in database (no external calls inside transaction)
        $approvedRequest = $repo->approveRequest($requestId, $adminId, $notes);

        if ($approvedRequest === null) {
            throw new NotFoundException('Request not found or already processed');
        }

        // Extract user/role info for Zitadel sync
        $userIdValue   = $approvedRequest['zitadel_user_id'] ?? null;
        $requestedRole = $approvedRequest['requested_role'] ?? null;
        $userId        = is_string($userIdValue) ? $userIdValue : '';
        $role          = is_string($requestedRole) ? $requestedRole : '';

        $roleAssigned = false;
        $zitadelError = null;

        // Step 2: Sync to Zitadel outside transaction (DB already committed)
        if (ZitadelService::isConfigured()) {
            if (empty($userId) || empty($role)) {
                // Missing user/role data is a sync failure, not "not configured"
                $zitadelError = 'Missing user ID or role in approved request';
                $repo->updateZitadelSyncStatus($requestId, 'failed', $zitadelError);
            } else {
                // Mark sync as pending
                $repo->updateZitadelSyncStatus($requestId, 'pending');

                try {
                    $zitadel = ZitadelService::fromEnv();
                    $zitadel->assignUserRole($userId, $role);
                    $roleAssigned = true;

                    // Mark sync as successful
                    $repo->updateZitadelSyncStatus($requestId, 'synced');
                } catch (\Exception $e) {
                    // Mark sync as failed (request remains approved for retry)
                    $zitadelError = $e->getMessage();
                    $repo->updateZitadelSyncStatus($requestId, 'failed', $zitadelError);
                }
            }
        }

        return $this->encodeResponseBody($response, [
            'success'       => true,
            'request'       => $approvedRequest,
            'role_assigned' => $roleAssigned,
            'zitadel_error' => $zitadelError,
            'message'       => $roleAssigned
                ? 'Request approved and role assigned in Zitadel'
                : ( $zitadelError !== null
                    ? 'Request approved but Zitadel sync failed (will retry)'
                    : 'Request approved (Zitadel not configured)' ),
        ]);
    }

    /**
     * Reject a role request.
     *
     * @param ResponseInterface $response
     * @param string $requestId Request UUID
     * @param string $adminId
     * @param string|null $notes
     * @return ResponseInterface
     */
    private function rejectRequest(
        ResponseInterface $response,
        string $requestId,
        string $adminId,
        ?string $notes
    ): ResponseInterface {
        $repo = $this->getRepository();

        $rejected = $repo->rejectRequest($requestId, $adminId, $notes);

        if (!$rejected) {
            throw new NotFoundException('Request not found or already processed');
        }

        return $this->encodeResponseBody($response, [
            'success' => true,
            'message' => 'Request rejected',
        ]);
    }

    /**
     * Revoke a previously approved role request.
     *
     * This removes the role from Zitadel and marks the request as revoked.
     * The database transaction is committed before calling Zitadel to avoid
     * holding locks during external API calls. If Zitadel sync fails, the
     * request remains revoked but with a 'failed' sync status for retry.
     *
     * @param ResponseInterface $response
     * @param string $requestId Request UUID
     * @param string $adminId
     * @param string|null $notes
     * @return ResponseInterface
     */
    private function revokeRequest(
        ResponseInterface $response,
        string $requestId,
        string $adminId,
        ?string $notes
    ): ResponseInterface {
        $repo = $this->getRepository();

        // Get the approved request by ID (targeted query instead of full-scan)
        $targetRequest = $repo->getByIdWithStatus($requestId, 'approved');

        if ($targetRequest === null) {
            throw new NotFoundException('Approved request not found');
        }

        // Step 1: Revoke in database (no external calls inside transaction)
        $revoked = $repo->revokeRequest($requestId, $adminId, $notes);

        if (!$revoked) {
            throw new NotFoundException('Request not found or not in approved status');
        }

        // Extract user/role info for Zitadel sync
        $userIdValue   = $targetRequest['zitadel_user_id'] ?? null;
        $requestedRole = $targetRequest['requested_role'] ?? null;
        $userId        = is_string($userIdValue) ? $userIdValue : '';
        $role          = is_string($requestedRole) ? $requestedRole : '';

        $roleRemoved  = false;
        $zitadelError = null;

        // Step 2: Sync to Zitadel outside transaction (DB already committed)
        if (ZitadelService::isConfigured()) {
            if (empty($userId) || empty($role)) {
                // Missing user/role data is a sync failure, not "not configured"
                $zitadelError = 'Missing user ID or role in revoked request';
                $repo->updateZitadelSyncStatus($requestId, 'failed', $zitadelError);
            } else {
                // Mark sync as pending
                $repo->updateZitadelSyncStatus($requestId, 'pending');

                try {
                    $zitadel = ZitadelService::fromEnv();
                    $zitadel->revokeUserRole($userId, $role);
                    $roleRemoved = true;

                    // Mark sync as successful
                    $repo->updateZitadelSyncStatus($requestId, 'synced');
                } catch (\Exception $e) {
                    // Mark sync as failed (request remains revoked for retry)
                    $zitadelError = $e->getMessage();
                    $repo->updateZitadelSyncStatus($requestId, 'failed', $zitadelError);
                }
            }
        }

        return $this->encodeResponseBody($response, [
            'success'       => true,
            'role_removed'  => $roleRemoved,
            'zitadel_error' => $zitadelError,
            'message'       => $roleRemoved
                ? 'Request revoked and role removed from Zitadel'
                : ( $zitadelError !== null
                    ? 'Request revoked but Zitadel sync failed (will retry)'
                    : 'Request revoked (Zitadel not configured)' ),
        ]);
    }
}
