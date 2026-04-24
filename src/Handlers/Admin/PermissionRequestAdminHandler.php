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
use LiturgicalCalendar\Api\Repositories\PermissionRequestRepository;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Permission Request Admin Handler — review permission requests.
 *
 * Global admins see all pending requests. Resource admins see only
 * requests for resources they administer.
 *
 * - GET  /admin/permission-requests                — List pending requests
 * - POST /admin/permission-requests/{id}/approve   — Approve (creates OpenFGA tuple)
 * - POST /admin/permission-requests/{id}/reject    — Reject
 * - POST /admin/permission-requests/{id}/revoke    — Revoke (deletes OpenFGA tuple)
 */
final class PermissionRequestAdminHandler extends AbstractHandler
{
    private ?PermissionRequestRepository $repository = null;
    private ?OpenFgaClient $fgaClient                = null;

    public function __construct()
    {
        parent::__construct();

        $this->allowedRequestMethods      = [RequestMethod::GET, RequestMethod::POST];
        $this->allowedAcceptHeaders       = [AcceptHeader::JSON];
        $this->allowedRequestContentTypes = [RequestContentType::JSON];
        $this->allowCredentials           = true;
    }

    private function getRepository(): PermissionRequestRepository
    {
        if ($this->repository === null) {
            $this->repository = new PermissionRequestRepository();
        }
        return $this->repository;
    }

    private function getClient(): OpenFgaClient
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
            return $this->listPendingRequests($request, $response, $adminId, $isGlobalAdmin);
        }

        // POST — parse action from path
        $path      = $request->getUri()->getPath();
        $pathParts = explode('/', trim($path, '/'));
        $partCount = count($pathParts);

        if ($partCount < 4) {
            throw new ValidationException('Invalid request path. Expected: /admin/permission-requests/{id}/{action}');
        }

        $action    = $pathParts[$partCount - 1];
        $requestId = $pathParts[$partCount - 2];

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
     * GET /admin/permission-requests — List pending requests.
     *
     * Global admins see all. Resource admins see only their resources.
     */
    private function listPendingRequests(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $adminId,
        bool $isGlobalAdmin
    ): ResponseInterface {
        if ($isGlobalAdmin) {
            $requests = $this->getRepository()->getPending();
        } else {
            // Resource admins: get all pending, then filter to administered resources
            $requests = $this->getRepository()->getPending();
            $requests = $this->filterByAdminAccess($requests, $adminId);
        }

        return $this->encodeResponseBody($response, [
            'requests' => $requests,
            'count'    => count($requests),
        ]);
    }

    /**
     * POST /admin/permission-requests/{id}/approve — Approve and create OpenFGA tuple.
     */
    private function approveRequest(
        ResponseInterface $response,
        string $requestId,
        string $adminId,
        bool $isGlobalAdmin,
        ?string $notes
    ): ResponseInterface {
        $permRequest = $this->getRepository()->getById($requestId);
        if ($permRequest === null) {
            throw new NotFoundException('Permission request not found');
        }

        if (( $permRequest['status'] ?? '' ) !== 'pending') {
            throw new ValidationException(
                sprintf('Cannot approve a request with status: %s', $permRequest['status'] ?? 'unknown')
            );
        }

        $objectType = $permRequest['object_type'] ?? '';
        $objectId   = $permRequest['object_id'] ?? '';
        $relation   = $permRequest['relation'] ?? '';
        $userId     = $permRequest['zitadel_user_id'] ?? '';

        if ($objectType === '' || $objectId === '' || $relation === '' || $userId === '') {
            throw new ValidationException('Permission request has incomplete data');
        }

        $this->requireResourceAdmin($adminId, $isGlobalAdmin, $objectType, $objectId);

        // Create OpenFGA tuple first — if this fails, don't update the DB
        $fgaUser   = "user:{$userId}";
        $fgaObject = "{$objectType}:{$objectId}";
        $this->getClient()->writeTuple($fgaUser, $relation, $fgaObject);

        // Tuple created successfully — now update DB status
        $updated = $this->getRepository()->approve($requestId, $adminId, $notes);
        if (!$updated) {
            throw new ValidationException('Failed to approve request');
        }

        return $this->encodeResponseBody($response, [
            'success'  => true,
            'message'  => 'Permission request approved',
            'user'     => $fgaUser,
            'relation' => $relation,
            'object'   => $fgaObject,
        ]);
    }

    /**
     * POST /admin/permission-requests/{id}/reject — Reject the request.
     */
    private function rejectRequest(
        ResponseInterface $response,
        string $requestId,
        string $adminId,
        bool $isGlobalAdmin,
        ?string $notes
    ): ResponseInterface {
        $permRequest = $this->getRepository()->getById($requestId);
        if ($permRequest === null) {
            throw new NotFoundException('Permission request not found');
        }

        if (( $permRequest['status'] ?? '' ) !== 'pending') {
            throw new ValidationException(
                sprintf('Cannot reject a request with status: %s', $permRequest['status'] ?? 'unknown')
            );
        }

        $objectType = $permRequest['object_type'] ?? '';
        $objectId   = $permRequest['object_id'] ?? '';

        $this->requireResourceAdmin($adminId, $isGlobalAdmin, $objectType, $objectId);

        $updated = $this->getRepository()->reject($requestId, $adminId, $notes);
        if (!$updated) {
            throw new ValidationException('Failed to reject request');
        }

        return $this->encodeResponseBody($response, [
            'success' => true,
            'message' => 'Permission request rejected',
        ]);
    }

    /**
     * POST /admin/permission-requests/{id}/revoke — Revoke a previously approved request.
     *
     * Marks the request as revoked and deletes the corresponding OpenFGA tuple.
     */
    private function revokeRequest(
        ResponseInterface $response,
        string $requestId,
        string $adminId,
        bool $isGlobalAdmin,
        ?string $notes
    ): ResponseInterface {
        $permRequest = $this->getRepository()->getById($requestId);
        if ($permRequest === null) {
            throw new NotFoundException('Permission request not found');
        }

        if (( $permRequest['status'] ?? '' ) !== 'approved') {
            throw new ValidationException(
                sprintf('Cannot revoke a request with status: %s', $permRequest['status'] ?? 'unknown')
            );
        }

        $objectType = $permRequest['object_type'] ?? '';
        $objectId   = $permRequest['object_id'] ?? '';
        $relation   = $permRequest['relation'] ?? '';
        $userId     = $permRequest['zitadel_user_id'] ?? '';

        if ($objectType === '' || $objectId === '' || $relation === '' || $userId === '') {
            throw new ValidationException('Permission request has incomplete data');
        }

        $this->requireResourceAdmin($adminId, $isGlobalAdmin, $objectType, $objectId);

        // Delete OpenFGA tuple first — if this fails, don't update the DB
        $fgaUser   = "user:{$userId}";
        $fgaObject = "{$objectType}:{$objectId}";
        $this->getClient()->deleteTuple($fgaUser, $relation, $fgaObject);

        // Tuple deleted successfully — now update DB status
        $updated = $this->getRepository()->revoke($requestId, $adminId, $notes);
        if (!$updated) {
            throw new ValidationException('Failed to revoke request');
        }

        return $this->encodeResponseBody($response, [
            'success'  => true,
            'message'  => 'Permission revoked',
            'user'     => $fgaUser,
            'relation' => $relation,
            'object'   => $fgaObject,
        ]);
    }

    /**
     * Check if the admin can manage permissions on a specific resource.
     *
     * @throws ForbiddenException If the admin lacks access
     */
    private function requireResourceAdmin(
        string $adminId,
        bool $isGlobalAdmin,
        string $objectType,
        string $objectId
    ): void {
        if ($isGlobalAdmin) {
            return;
        }

        if (!OpenFgaClient::isConfigured()) {
            throw new ForbiddenException('Admin role required');
        }

        $isResourceAdmin = $this->getClient()->check(
            "user:{$adminId}",
            'admin',
            "{$objectType}:{$objectId}"
        );

        if (!$isResourceAdmin) {
            throw new ForbiddenException(
                sprintf('No admin permission for %s:%s', $objectType, $objectId)
            );
        }
    }

    /**
     * Filter pending requests to only those the resource admin can manage.
     *
     * @param array<int, array<string, string|null>> $requests All pending requests
     * @param string $adminId Admin's Zitadel user ID
     * @return array<int, array<string, string|null>> Filtered requests
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
            $objectType = $req['object_type'] ?? '';
            $objectId   = $req['object_id'] ?? '';
            $key        = "{$objectType}:{$objectId}";

            if (!isset($cache[$key])) {
                $cache[$key] = $this->getClient()->check($fgaUser, 'admin', $key);
            }

            return $cache[$key];
        }));
    }
}
