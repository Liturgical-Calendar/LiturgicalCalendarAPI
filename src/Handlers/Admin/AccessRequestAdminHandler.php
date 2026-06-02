<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Admin;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Handlers\Pagination\OffsetPaginationTrait;
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
use LiturgicalCalendar\Api\Services\Exception\OpenFgaApiException;
use LiturgicalCalendar\Api\Services\Exception\TupleAlreadyExistsException;
use LiturgicalCalendar\Api\Services\Exception\TupleNotFoundException;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\RoleCascadeService;
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
    use OffsetPaginationTrait;

    private ?AccessRequestRepository $repository = null;
    private ?OpenFgaClient $fgaClient            = null;

    /**
     * The OpenFGA client is constructor-injectable for tests — pass a
     * MockHandler-backed instance to verify the typed-exception integration
     * without touching the runtime env. Production calls use the zero-arg
     * form and the lazy `fromEnv()` fallback in `getFgaClient()`. Mirrors
     * the pattern PR #623 introduced for `PermissionAdminHandler`.
     */
    public function __construct(?OpenFgaClient $fgaClient = null)
    {
        parent::__construct();

        $this->fgaClient = $fgaClient;

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

    /**
     * True when an OpenFGA client is reachable: either one was constructor-
     * injected (test path), or the env-based static is configured (prod
     * path). Replaces direct `OpenFgaClient::isConfigured()` checks at the
     * sites that gate tuple-write / admin-check work, so an injected mock
     * is honored without also requiring OPENFGA_* env vars to be set.
     */
    private function isFgaClientAvailable(): bool
    {
        return $this->fgaClient !== null || OpenFgaClient::isConfigured();
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
     * GET /admin/access-requests — List access requests, paginated.
     *
     * Global admins see all requests. Resource admins see only requests
     * for resources they administer (filterByAdminAccess is applied
     * post-fetch to each page).
     *
     * Query parameters:
     *   - status: Filter by status (pending, approved, rejected, revoked).
     *             If omitted, returns all statuses.
     *   - limit:  Max items in this page (1..500, default 100).
     *   - offset: Zero-based item index where this page starts (default 0).
     *
     * Note: when filterByAdminAccess is applied (non-global admin), the
     * returned `count` may be smaller than `limit` and smaller than
     * `total` — `total` reflects the pre-filter SQL count, `has_more`
     * reflects the SQL paginator. Clients should keep paging until
     * `has_more` is false even when individual pages come back short.
     * Same caveat as PR #623's /admin/permissions endpoint.
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

        if ($statusFilter !== null && !in_array($statusFilter, AccessRequestRepository::VALID_STATUSES, true)) {
            throw new ValidationException(
                sprintf('Invalid status. Valid values are: %s', implode(', ', AccessRequestRepository::VALID_STATUSES))
            );
        }

        $limit  = $this->parseLimit($queryParams['limit']   ?? null);
        $offset = $this->parseOffset($queryParams['offset'] ?? null);

        $page  = $repo->getAll($statusFilter, $limit, $offset);
        $total = $repo->countAll($statusFilter);

        // Snapshot the SQL page size BEFORE filterByAdminAccess shrinks the page.
        // `has_more` must reflect the SQL paginator's state, not the filtered
        // page count: when SQL returns its final page (sqlPageCount < limit so
        // offset + sqlPageCount == total), the client should stop. If we used
        // the post-filter count instead, a heavily-filtered final page would
        // falsely advertise has_more=true and the client would fetch an empty
        // next page.
        $sqlPageCount = count($page);

        if (!$isGlobalAdmin) {
            $page = $this->filterByAdminAccess($page, $adminId);
        }

        return $this->encodeResponseBody($response, [
            'requests' => $page,
            'count'    => count($page),
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'has_more' => ( $offset + $sqlPageCount ) < $total,
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

        // Step 1: Create OpenFGA tuples for each permission.
        //
        // TupleAlreadyExistsException is treated as benign — the tuple is in
        // the desired state already, so re-approval after a partial first
        // attempt converges instead of double-failing. Any other
        // OpenFgaApiException (validation, auth, server) is fatal: the
        // response will surface success: false with the structured error
        // list so the admin knows there's drift to repair.
        $createdTuples = [];
        $fgaErrors     = [];

        if ($this->isFgaClientAvailable() && !empty($permissions)) {
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
                } catch (TupleAlreadyExistsException) {
                    // Idempotent — tuple already exists. Count it as created
                    // so the response shows the user's effective grants.
                    $createdTuples[] = [
                        'user'     => $fgaUser,
                        'relation' => $relation,
                        'object'   => $fgaObject,
                    ];
                } catch (OpenFgaApiException $e) {
                    $fgaErrors[] = [
                        'object'   => $fgaObject,
                        'relation' => $relation,
                        'error'    => $e->getMessage(),
                    ];
                }
            }
        }

        // If we couldn't write some of the tuples, bail before mutating the
        // DB — leaving the request 'pending' lets the admin retry cleanly,
        // and avoids the silent under-provisioning the issue flags
        // ("row marked approved while user is missing the failed tuples").
        if (!empty($fgaErrors)) {
            return $this->encodeResponseBody($response, [
                'success'        => false,
                'role_assigned'  => false,
                'zitadel_error'  => null,
                'tuples_created' => $createdTuples,
                'fga_errors'     => $fgaErrors,
                'message'        => sprintf(
                    'Approval aborted: %d of %d permission tuple(s) could not be written. The request remains pending; retry once the underlying OpenFGA error is resolved.',
                    count($fgaErrors),
                    count($permissions)
                ),
            ]);
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

        // Step 1: Delete OpenFGA tuples.
        //
        // TupleNotFoundException is treated as benign — the tuple is already
        // gone, so re-revoke after a partial first attempt converges instead
        // of double-failing. Any other OpenFgaApiException is fatal: the
        // response will surface success: false with the structured error
        // list so the admin knows there's drift to repair.
        $deletedTuples = [];
        $fgaErrors     = [];

        if ($this->isFgaClientAvailable() && !empty($permissions)) {
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
                } catch (TupleNotFoundException) {
                    // Idempotent — tuple already deleted. Count it as deleted
                    // so the response shows the user's effective state.
                    $deletedTuples[] = [
                        'user'     => $fgaUser,
                        'relation' => $relation,
                        'object'   => $fgaObject,
                    ];
                } catch (OpenFgaApiException $e) {
                    $fgaErrors[] = [
                        'object'   => $fgaObject,
                        'relation' => $relation,
                        'error'    => $e->getMessage(),
                    ];
                }
            }
        }

        // If we couldn't delete some of the tuples, bail before mutating the
        // DB — leaving the request 'approved' lets the admin retry cleanly,
        // and avoids the silent over-provisioning the issue flags
        // ("row marked revoked while stale tuples remain in OpenFGA").
        if (!empty($fgaErrors)) {
            return $this->encodeResponseBody($response, [
                'success'        => false,
                'role_removed'   => false,
                'zitadel_error'  => null,
                'tuples_deleted' => $deletedTuples,
                'fga_errors'     => $fgaErrors,
                'message'        => sprintf(
                    'Revocation aborted: %d of %d permission tuple(s) could not be deleted. The request remains approved; retry once the underlying OpenFGA error is resolved.',
                    count($fgaErrors),
                    count($permissions)
                ),
            ]);
        }

        // Step 2: Revoke in database
        $revoked = $repo->revoke($requestId, $adminId, $notes);
        if (!$revoked) {
            throw new NotFoundException('Request not found or not in approved status');
        }

        // Step 3: Conditionally remove role from Zitadel.
        // Cascade rule: only revoke the role if the user has zero remaining
        // tuples in the role's scope. Other access_requests may still grant
        // tuples for the same role — revoking unconditionally would strip
        // those legitimate grants.
        $roleRemoved  = false;
        $zitadelError = null;

        if (ZitadelService::isConfigured()) {
            if (empty($userId) || empty($requestedRole)) {
                $zitadelError = 'Missing user ID or role in revoked request';
                $repo->updateZitadelSyncStatus($requestId, 'failed', $zitadelError);
            } else {
                $repo->updateZitadelSyncStatus($requestId, 'pending');

                try {
                    $cascade     = RoleCascadeService::fromEnv();
                    $roleRemoved = $cascade->maybeCascadeRoleRevoke($userId, $requestedRole);

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
                ? 'Access revoked, role removed (no remaining permissions in scope) and permissions deleted'
                : ( $zitadelError !== null
                    ? 'Access revoked but Zitadel sync failed (will retry)'
                    : ( ZitadelService::isConfigured()
                        ? 'Access revoked, permissions deleted; role retained (other in-scope permissions remain)'
                        : 'Access revoked (Zitadel not configured)' ) ),
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

        if (!$this->isFgaClientAvailable()) {
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
        if (!$this->isFgaClientAvailable()) {
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
