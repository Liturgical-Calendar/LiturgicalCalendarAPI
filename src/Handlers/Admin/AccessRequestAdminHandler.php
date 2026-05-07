<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Admin;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\ZitadelService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Access Request Admin Handler — review and manage access requests.
 *
 * Replaces both RoleRequestAdminHandler and PermissionRequestAdminHandler
 * with a unified workflow. On approval, both the Zitadel role and all
 * OpenFGA permission tuples are granted in a single operation.
 *
 * Global admins see all pending requests. Resource admins see only
 * requests for resources they administer.
 *
 * - GET  /admin/access-requests                — List requests
 * - POST /admin/access-requests/{id}/approve   — Approve (grant role + create tuples)
 * - POST /admin/access-requests/{id}/reject    — Reject with notes
 * - POST /admin/access-requests/{id}/revoke    — Revoke (remove role + delete tuples)
 */
final class AccessRequestAdminHandler extends AbstractHandler
{
    private ?AccessRequestRepository $repository = null;
    private ?OpenFgaClient $fgaClient            = null;

    public function __construct()
    {
        parent::__construct();

        $this->allowedRequestMethods      = [RequestMethod::GET, RequestMethod::POST];
        $this->allowedAcceptHeaders       = [AcceptHeader::JSON];
        $this->allowedRequestContentTypes = [RequestContentType::JSON];
        $this->allowCredentials           = true;
    }

    private function getRepository(): AccessRequestRepository
    {
        if ($this->repository === null) {
            $this->repository = new AccessRequestRepository();
        }
        return $this->repository;
    }

    private function getFgaClient(): OpenFgaClient
    {
        if ($this->fgaClient === null) {
            $this->fgaClient = OpenFgaClient::fromEnv();
        }
        return $this->fgaClient;
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
        $response = $response->withHeader('Content-Type', $mime)
            ->withHeader('Cache-Control', 'no-store');

        /** @var array{sub?: string, roles?: array<string>}|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');

        if ($oidcUser === null) {
            throw new UnauthorizedException('Authentication required');
        }

        $adminId = $oidcUser['sub'] ?? null;
        if ($adminId === null || ( is_string($adminId) && trim($adminId) === '' )) {
            throw new UnauthorizedException('Invalid authentication token');
        }

        if (!Connection::isConfigured()) {
            throw new \RuntimeException('Database not configured');
        }

        $isGlobalAdmin = OidcAuthMiddleware::isAdmin($oidcUser);

        if ($method === RequestMethod::GET) {
            return $this->listRequests($request, $response, $adminId, $isGlobalAdmin);
        }

        // POST — parse action from path
        $path      = $request->getUri()->getPath();
        $pathParts = explode('/', trim($path, '/'));
        $partCount = count($pathParts);

        if ($partCount < 4) {
            throw new ValidationException('Invalid request path. Expected: /admin/access-requests/{id}/{action}');
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
            return $this->approveRequest($response, $requestId, $adminId, $isGlobalAdmin, $notes);
        }

        if ($action === 'reject') {
            return $this->rejectRequest($response, $requestId, $adminId, $isGlobalAdmin, $notes);
        }

        if ($action === 'revoke') {
            return $this->revokeRequest($response, $requestId, $adminId, $isGlobalAdmin, $notes);
        }

        throw new ValidationException('Invalid action. Use "approve", "reject", or "revoke"');
    }

    /**
     * GET /admin/access-requests — List access requests.
     *
     * Global admins see all requests. Resource admins see only requests
     * for resources they administer.
     *
     * Query parameters:
     * - status: Filter by status (pending, approved, rejected, revoked). If omitted, returns all statuses.
     */
    private function listRequests(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $adminId,
        bool $isGlobalAdmin
    ): ResponseInterface {
        $repo        = $this->getRepository();
        $queryParams = $request->getQueryParams();

        $statusFilter = isset($queryParams['status']) && is_string($queryParams['status'])
            ? $queryParams['status']
            : null;

        // Validate status if provided
        if ($statusFilter !== null && !in_array($statusFilter, AccessRequestRepository::VALID_STATUSES, true)) {
            throw new ValidationException(
                sprintf('Invalid status. Valid values are: %s', implode(', ', AccessRequestRepository::VALID_STATUSES))
            );
        }

        if ($isGlobalAdmin) {
            $requests = $statusFilter !== null
                ? $repo->getAll($statusFilter)
                : $repo->getAll();
        } else {
            // Resource admins: get requests, then filter by admin access
            $allRequests = $statusFilter !== null
                ? $repo->getAll($statusFilter)
                : $repo->getAll();
            $requests    = $this->filterByAdminAccess($allRequests, $adminId);
        }

        return $this->encodeResponseBody($response, [
            'requests' => $requests,
            'count'    => count($requests),
        ]);
    }

    /**
     * POST /admin/access-requests/{id}/approve — Approve access request.
     *
     * Flow:
     * 1. Validate request exists and is pending
     * 2. Check admin has authority (global admin OR resource admin for ALL requested resources)
     * 3. Create OpenFGA tuples first (for each permission in the array)
     * 4. Assign Zitadel role
     * 5. Update DB status to approved
     * 6. Track Zitadel sync status
     */
    private function approveRequest(
        ResponseInterface $response,
        string $requestId,
        string $adminId,
        bool $isGlobalAdmin,
        ?string $notes
    ): ResponseInterface {
        $repo = $this->getRepository();

        $accessRequest = $repo->getById($requestId);
        if ($accessRequest === null) {
            throw new NotFoundException('Access request not found');
        }

        if (( $accessRequest['status'] ?? '' ) !== 'pending') {
            throw new ValidationException(
                sprintf('Cannot approve a request with status: %s', is_string($accessRequest['status'] ?? null) ? $accessRequest['status'] : 'unknown')
            );
        }

        $userId        = is_string($accessRequest['zitadel_user_id'] ?? null) ? $accessRequest['zitadel_user_id'] : '';
        $requestedRole = is_string($accessRequest['requested_role'] ?? null) ? $accessRequest['requested_role'] : '';
        /** @var array<int, array{object_type: string, object_id: string, relation: string}> $permissions */
        $permissions = is_array($accessRequest['permissions'] ?? null) ? $accessRequest['permissions'] : [];

        if ($userId === '' || $requestedRole === '') {
            throw new ValidationException('Access request has incomplete data');
        }

        // Check admin authority over ALL requested resources
        $this->requireAdminForAllResources($adminId, $isGlobalAdmin, $permissions);

        // Step 1: Create OpenFGA tuples for each permission
        $createdTuples = [];
        $fgaErrors     = [];

        if (OpenFgaClient::isConfigured() && !empty($permissions)) {
            $fgaUser = "user:{$userId}";

            foreach ($permissions as $perm) {
                $objectType = $perm['object_type'] ?? '';
                $objectId   = $perm['object_id'] ?? '';
                $relation   = $perm['relation'] ?? '';
                $fgaObject  = "{$objectType}:{$objectId}";

                try {
                    $this->getFgaClient()->writeTuple($fgaUser, $relation, $fgaObject);
                    $createdTuples[] = [
                        'user'     => $fgaUser,
                        'relation' => $relation,
                        'object'   => $fgaObject,
                    ];
                } catch (\Exception $e) {
                    // Tuple may already exist — log but continue
                    $fgaErrors[] = [
                        'object'   => $fgaObject,
                        'relation' => $relation,
                        'error'    => $e->getMessage(),
                    ];
                }
            }
        }

        // Step 2: Approve in database
        $approved = $repo->approve($requestId, $adminId, $notes);
        if (!$approved) {
            throw new ValidationException('Failed to approve request');
        }

        // Step 3: Sync role to Zitadel
        $roleAssigned = false;
        $zitadelError = null;

        if (ZitadelService::isConfigured()) {
            if (empty($userId) || empty($requestedRole)) {
                $zitadelError = 'Missing user ID or role in approved request';
                $repo->updateZitadelSyncStatus($requestId, 'failed', $zitadelError);
            } else {
                $repo->updateZitadelSyncStatus($requestId, 'pending');

                try {
                    $zitadel = ZitadelService::fromEnv();
                    $zitadel->assignUserRole($userId, $requestedRole);
                    $roleAssigned = true;

                    $repo->updateZitadelSyncStatus($requestId, 'synced');
                } catch (\Exception $e) {
                    $zitadelError = $e->getMessage();
                    $repo->updateZitadelSyncStatus($requestId, 'failed', $zitadelError);
                }
            }
        }

        return $this->encodeResponseBody($response, [
            'success'        => true,
            'role_assigned'  => $roleAssigned,
            'zitadel_error'  => $zitadelError,
            'tuples_created' => $createdTuples,
            'fga_errors'     => $fgaErrors,
            'message'        => $roleAssigned
                ? 'Access request approved, role assigned and permissions granted'
                : ( $zitadelError !== null
                    ? 'Access request approved but Zitadel sync failed (will retry)'
                    : 'Access request approved (Zitadel not configured)' ),
        ]);
    }

    /**
     * POST /admin/access-requests/{id}/reject — Reject the request.
     */
    private function rejectRequest(
        ResponseInterface $response,
        string $requestId,
        string $adminId,
        bool $isGlobalAdmin,
        ?string $notes
    ): ResponseInterface {
        $repo = $this->getRepository();

        $accessRequest = $repo->getById($requestId);
        if ($accessRequest === null) {
            throw new NotFoundException('Access request not found');
        }

        if (( $accessRequest['status'] ?? '' ) !== 'pending') {
            throw new ValidationException(
                sprintf('Cannot reject a request with status: %s', is_string($accessRequest['status'] ?? null) ? $accessRequest['status'] : 'unknown')
            );
        }

        /** @var array<int, array{object_type: string, object_id: string, relation: string}> $permissions */
        $permissions = is_array($accessRequest['permissions'] ?? null) ? $accessRequest['permissions'] : [];

        $this->requireAdminForAllResources($adminId, $isGlobalAdmin, $permissions);

        $rejected = $repo->reject($requestId, $adminId, $notes);
        if (!$rejected) {
            throw new ValidationException('Failed to reject request');
        }

        return $this->encodeResponseBody($response, [
            'success' => true,
            'message' => 'Access request rejected',
        ]);
    }

    /**
     * POST /admin/access-requests/{id}/revoke — Revoke a previously approved request.
     *
     * Flow:
     * 1. Validate request exists and is approved
     * 2. Delete all OpenFGA tuples
     * 3. Remove Zitadel role
     * 4. Update DB status to revoked
     */
    private function revokeRequest(
        ResponseInterface $response,
        string $requestId,
        string $adminId,
        bool $isGlobalAdmin,
        ?string $notes
    ): ResponseInterface {
        $repo = $this->getRepository();

        $accessRequest = $repo->getById($requestId);
        if ($accessRequest === null) {
            throw new NotFoundException('Access request not found');
        }

        if (( $accessRequest['status'] ?? '' ) !== 'approved') {
            throw new ValidationException(
                sprintf('Cannot revoke a request with status: %s', is_string($accessRequest['status'] ?? null) ? $accessRequest['status'] : 'unknown')
            );
        }

        $userId        = is_string($accessRequest['zitadel_user_id'] ?? null) ? $accessRequest['zitadel_user_id'] : '';
        $requestedRole = is_string($accessRequest['requested_role'] ?? null) ? $accessRequest['requested_role'] : '';
        /** @var array<int, array{object_type: string, object_id: string, relation: string}> $permissions */
        $permissions = is_array($accessRequest['permissions'] ?? null) ? $accessRequest['permissions'] : [];

        if ($userId === '' || $requestedRole === '') {
            throw new ValidationException('Access request has incomplete data');
        }

        $this->requireAdminForAllResources($adminId, $isGlobalAdmin, $permissions);

        // Step 1: Delete OpenFGA tuples
        $deletedTuples = [];
        $fgaErrors     = [];

        if (OpenFgaClient::isConfigured() && !empty($permissions)) {
            $fgaUser = "user:{$userId}";

            foreach ($permissions as $perm) {
                $objectType = $perm['object_type'] ?? '';
                $objectId   = $perm['object_id'] ?? '';
                $relation   = $perm['relation'] ?? '';
                $fgaObject  = "{$objectType}:{$objectId}";

                try {
                    $this->getFgaClient()->deleteTuple($fgaUser, $relation, $fgaObject);
                    $deletedTuples[] = [
                        'user'     => $fgaUser,
                        'relation' => $relation,
                        'object'   => $fgaObject,
                    ];
                } catch (\Exception $e) {
                    // Tuple may already be deleted — log but continue
                    $fgaErrors[] = [
                        'object'   => $fgaObject,
                        'relation' => $relation,
                        'error'    => $e->getMessage(),
                    ];
                }
            }
        }

        // Step 2: Revoke in database
        $revoked = $repo->revoke($requestId, $adminId, $notes);
        if (!$revoked) {
            throw new NotFoundException('Request not found or not in approved status');
        }

        // Step 3: Remove role from Zitadel
        $roleRemoved  = false;
        $zitadelError = null;

        if (ZitadelService::isConfigured()) {
            if (empty($userId) || empty($requestedRole)) {
                $zitadelError = 'Missing user ID or role in revoked request';
                $repo->updateZitadelSyncStatus($requestId, 'failed', $zitadelError);
            } else {
                $repo->updateZitadelSyncStatus($requestId, 'pending');

                try {
                    $zitadel = ZitadelService::fromEnv();
                    $zitadel->revokeUserRole($userId, $requestedRole);
                    $roleRemoved = true;

                    $repo->updateZitadelSyncStatus($requestId, 'synced');
                } catch (\Exception $e) {
                    $zitadelError = $e->getMessage();
                    $repo->updateZitadelSyncStatus($requestId, 'failed', $zitadelError);
                }
            }
        }

        return $this->encodeResponseBody($response, [
            'success'        => true,
            'role_removed'   => $roleRemoved,
            'zitadel_error'  => $zitadelError,
            'tuples_deleted' => $deletedTuples,
            'fga_errors'     => $fgaErrors,
            'message'        => $roleRemoved
                ? 'Access revoked, role removed and permissions deleted'
                : ( $zitadelError !== null
                    ? 'Access revoked but Zitadel sync failed (will retry)'
                    : 'Access revoked (Zitadel not configured)' ),
        ]);
    }

    /**
     * Check that the admin has authority over ALL resources in the permissions array.
     *
     * Global admins always pass. Resource admins must have the 'admin' relation
     * on every resource referenced in the permissions.
     *
     * @param string $adminId Admin's Zitadel user ID
     * @param bool $isGlobalAdmin Whether the admin has global admin role
     * @param array<int, array{object_type: string, object_id: string, relation: string}> $permissions
     * @throws ForbiddenException If the admin lacks authority over any resource
     */
    private function requireAdminForAllResources(
        string $adminId,
        bool $isGlobalAdmin,
        array $permissions
    ): void {
        if ($isGlobalAdmin) {
            return;
        }

        if (!OpenFgaClient::isConfigured()) {
            throw new ForbiddenException('Admin role required');
        }

        $fgaUser = "user:{$adminId}";

        // Cache admin checks to avoid redundant API calls
        /** @var array<string, bool> $cache */
        $cache = [];

        foreach ($permissions as $perm) {
            $objectType = $perm['object_type'] ?? '';
            $objectId   = $perm['object_id'] ?? '';
            $key        = "{$objectType}:{$objectId}";

            if (!isset($cache[$key])) {
                $cache[$key] = $this->getFgaClient()->check($fgaUser, 'admin', $key);
            }

            if (!$cache[$key]) {
                throw new ForbiddenException(
                    sprintf('No admin permission for %s', $key)
                );
            }
        }
    }

    /**
     * Filter requests to only those the resource admin can manage.
     *
     * A resource admin can manage a request only if they have the 'admin'
     * relation on ALL resources in that request's permissions array.
     *
     * @param array<int, array<string, mixed>> $requests All requests
     * @param string $adminId Admin's Zitadel user ID
     * @return array<int, array<string, mixed>> Filtered requests
     */
    private function filterByAdminAccess(array $requests, string $adminId): array
    {
        if (!OpenFgaClient::isConfigured()) {
            return [];
        }

        $fgaUser = "user:{$adminId}";

        // Cache admin checks to avoid redundant API calls
        /** @var array<string, bool> $cache */
        $cache = [];

        return array_values(array_filter($requests, function (array $req) use ($fgaUser, &$cache): bool {
            /** @var array<int, array{object_type: string, object_id: string, relation: string}> $permissions */
            $permissions = is_array($req['permissions'] ?? null) ? $req['permissions'] : [];

            if (empty($permissions)) {
                return false;
            }

            // Admin must have access to ALL resources in the request
            foreach ($permissions as $perm) {
                $objectType = $perm['object_type'] ?? '';
                $objectId   = $perm['object_id'] ?? '';
                $key        = "{$objectType}:{$objectId}";

                if (!isset($cache[$key])) {
                    $cache[$key] = $this->getFgaClient()->check($fgaUser, 'admin', $key);
                }

                if (!$cache[$key]) {
                    return false;
                }
            }

            return true;
        }));
    }
}
