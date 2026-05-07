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
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Permission Admin Handler — OpenFGA tuple management.
 *
 * Provides endpoints for managing fine-grained permissions:
 *
 * - GET    /admin/permissions              — List tuples (optional filters)
 * - POST   /admin/permissions              — Grant permission (create tuple)
 * - DELETE /admin/permissions              — Revoke permission (delete tuple)
 * - GET    /admin/permissions/check        — Check a specific permission
 *
 * Access control:
 * - Global admins (Zitadel "admin" role) can manage all resources
 * - Resource admins (OpenFGA "admin" relation on a resource) can manage
 *   permissions for that specific resource only
 */
final class PermissionAdminHandler extends AbstractHandler
{
    /**
     * Valid OpenFGA object types that can be managed.
     *
     * @var array<string>
     */
    private const VALID_OBJECT_TYPES = [
        'national_calendar',
        'diocesan_calendar',
        'wider_region',
        'test_definition',
    ];

    /**
     * Valid OpenFGA relations.
     *
     * @var array<string>
     */
    private const VALID_RELATIONS = ['admin', 'viewer', 'editor', 'deleter'];

    private ?OpenFgaClient $fgaClient = null;

    public function __construct()
    {
        parent::__construct();

        $this->allowedRequestMethods      = [RequestMethod::GET, RequestMethod::POST, RequestMethod::DELETE];
        $this->allowedAcceptHeaders       = [AcceptHeader::JSON];
        $this->allowedRequestContentTypes = [RequestContentType::JSON];
        $this->allowCredentials           = true;
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

        // Check authentication
        /** @var array{sub?: string, roles?: array<string>}|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');

        if ($oidcUser === null) {
            throw new UnauthorizedException('Authentication required');
        }

        $userId = $oidcUser['sub'] ?? null;
        if (!is_string($userId) || trim($userId) === '') {
            throw new UnauthorizedException('Invalid authentication token');
        }

        $isGlobalAdmin = OidcAuthMiddleware::isAdmin($oidcUser);

        // Determine sub-route: /admin/permissions or /admin/permissions/check
        $path      = $request->getUri()->getPath();
        $pathParts = explode('/', trim($path, '/'));
        $lastPart  = end($pathParts);

        if ($method === RequestMethod::GET && $lastPart === 'check') {
            return $this->checkPermission($request, $response, $userId, $isGlobalAdmin);
        }

        if ($method === RequestMethod::GET) {
            return $this->listPermissions($request, $response, $userId, $isGlobalAdmin);
        }

        if ($method === RequestMethod::POST) {
            return $this->grantPermission($request, $response, $userId, $isGlobalAdmin);
        }

        // DELETE
        return $this->revokePermission($request, $response, $userId, $isGlobalAdmin);
    }

    /**
     * Check if the current user can manage permissions on a specific resource.
     *
     * A user can manage permissions if they are a global admin (Zitadel role)
     * or a resource admin (OpenFGA "admin" relation on the resource).
     *
     * @param string $userId The current user's Zitadel ID
     * @param bool $isGlobalAdmin Whether the user has the Zitadel admin role
     * @param string $objectType The OpenFGA object type
     * @param string $objectId The resource ID
     * @throws ForbiddenException If the user cannot manage this resource
     */
    private function requireResourceAdmin(
        string $userId,
        bool $isGlobalAdmin,
        string $objectType,
        string $objectId
    ): void {
        if ($isGlobalAdmin) {
            return;
        }

        // Check if user has the "admin" relation on this specific resource
        $fgaUser   = "user:{$userId}";
        $fgaObject = "{$objectType}:{$objectId}";

        $isResourceAdmin = $this->getClient()->check($fgaUser, 'admin', $fgaObject);

        if (!$isResourceAdmin) {
            throw new ForbiddenException(
                sprintf('No admin permission for %s', $fgaObject)
            );
        }
    }

    /**
     * GET /admin/permissions — List relationship tuples.
     *
     * Global admins see all tuples. Resource admins see only tuples
     * for resources they administer.
     *
     * Query parameters:
     *   - user: Filter by user (e.g., "user:zitadel-id")
     *   - object_type: Filter by object type (e.g., "national_calendar")
     *   - object_id: Filter by specific object (e.g., "IT")
     *   - relation: Filter by relation (e.g., "editor")
     */
    private function listPermissions(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $userId,
        bool $isGlobalAdmin
    ): ResponseInterface {
        $params     = $request->getQueryParams();
        $user       = is_string($params['user'] ?? null) ? $params['user'] : '';
        $objectType = is_string($params['object_type'] ?? null) ? $params['object_type'] : '';
        $objectId   = is_string($params['object_id'] ?? null) ? $params['object_id'] : '';
        $relation   = is_string($params['relation'] ?? null) ? $params['relation'] : '';

        if (!$isGlobalAdmin && $objectType === '') {
            // Resource admins must specify an object_type to avoid listing
            // tuples across resources they don't administer
            throw new ValidationException(
                'Resource admins must specify object_type filter'
            );
        }

        if ($objectType !== '' && !in_array($objectType, self::VALID_OBJECT_TYPES, true)) {
            throw new ValidationException(
                sprintf('Invalid object_type. Valid types: %s', implode(', ', self::VALID_OBJECT_TYPES))
            );
        }

        if ($relation !== '' && !in_array($relation, self::VALID_RELATIONS, true)) {
            throw new ValidationException(
                sprintf('Invalid relation. Valid relations: %s', implode(', ', self::VALID_RELATIONS))
            );
        }

        // For resource admins with a specific object, verify admin access
        if (!$isGlobalAdmin && $objectId !== '') {
            $this->requireResourceAdmin($userId, false, $objectType, $objectId);
        }

        if ($objectType === '') {
            // Global admin with no type filter: read all tuples
            $normalizedUser = $user !== '' ? $this->normalizeUser($user) : '';
            $relationFilter = $relation !== '' ? $relation : null;
            $allTuples      = $this->getClient()->readTuples(
                $normalizedUser,
                '',
                $relationFilter
            );

            return $this->encodeResponseBody($response, [
                'permissions' => $allTuples,
                'count'       => count($allTuples),
            ]);
        }

        $object = $objectType . ':' . $objectId;
        $tuples = $this->getClient()->readTuples(
            $user !== '' ? $this->normalizeUser($user) : '',
            $object,
            $relation !== '' ? $relation : null
        );

        // For resource admins listing without a specific object_id,
        // filter to only resources they administer
        if (!$isGlobalAdmin && $objectId === '') {
            $tuples = $this->filterByAdminAccess($tuples, $userId, $objectType);
        }

        return $this->encodeResponseBody($response, [
            'permissions' => $tuples,
            'count'       => count($tuples),
        ]);
    }

    /**
     * POST /admin/permissions — Grant a permission (create tuple).
     *
     * Request body:
     *   - user: string (required) — Zitadel user ID (with or without "user:" prefix)
     *   - object_type: string (required) — e.g., "national_calendar"
     *   - object_id: string (required) — e.g., "IT"
     *   - relation: string (required) — "admin", "viewer", "editor", or "deleter"
     */
    private function grantPermission(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $userId,
        bool $isGlobalAdmin
    ): ResponseInterface {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ValidationException('Request body must be JSON');
        }

        $user       = is_string($body['user'] ?? null) ? $body['user'] : '';
        $objectType = is_string($body['object_type'] ?? null) ? $body['object_type'] : '';
        $objectId   = is_string($body['object_id'] ?? null) ? $body['object_id'] : '';
        $relation   = is_string($body['relation'] ?? null) ? $body['relation'] : '';

        $this->validateTupleParams($user, $objectType, $objectId, $relation);
        $this->requireResourceAdmin($userId, $isGlobalAdmin, $objectType, $objectId);

        $fgaUser   = $this->normalizeUser($user);
        $fgaObject = "{$objectType}:{$objectId}";

        try {
            $this->getClient()->writeTuple($fgaUser, $relation, $fgaObject);
        } catch (\RuntimeException $e) {
            // Treat "tuple already exists" as success (idempotent grant)
            if (!str_contains($e->getMessage(), 'cannot write a tuple which already exists')) {
                throw $e;
            }
        }

        return $this->encodeResponseBody($response, [
            'success'  => true,
            'message'  => 'Permission granted',
            'user'     => $fgaUser,
            'relation' => $relation,
            'object'   => $fgaObject,
        ]);
    }

    /**
     * DELETE /admin/permissions — Revoke a permission (delete tuple).
     *
     * Request body:
     *   - user: string (required)
     *   - object_type: string (required)
     *   - object_id: string (required)
     *   - relation: string (required)
     */
    private function revokePermission(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $userId,
        bool $isGlobalAdmin
    ): ResponseInterface {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ValidationException('Request body must be JSON');
        }

        $user       = is_string($body['user'] ?? null) ? $body['user'] : '';
        $objectType = is_string($body['object_type'] ?? null) ? $body['object_type'] : '';
        $objectId   = is_string($body['object_id'] ?? null) ? $body['object_id'] : '';
        $relation   = is_string($body['relation'] ?? null) ? $body['relation'] : '';

        $this->validateTupleParams($user, $objectType, $objectId, $relation);
        $this->requireResourceAdmin($userId, $isGlobalAdmin, $objectType, $objectId);

        $fgaUser   = $this->normalizeUser($user);
        $fgaObject = "{$objectType}:{$objectId}";

        try {
            $this->getClient()->deleteTuple($fgaUser, $relation, $fgaObject);
        } catch (\RuntimeException $e) {
            // Treat "tuple not found" as success (idempotent revoke)
            if (!str_contains($e->getMessage(), 'cannot delete a tuple which does not exist')) {
                throw $e;
            }
        }

        // Keep access_requests DB in sync: remove this permission from
        // any approved access request for this user
        $bareUserId = str_starts_with($fgaUser, 'user:')
            ? substr($fgaUser, 5)
            : $fgaUser;

        if (Connection::isConfigured()) {
            $repo = new AccessRequestRepository();
            $repo->removePermissionTuple($bareUserId, $objectType, $objectId, $relation);
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
     * GET /admin/permissions/check — Check if a user has a specific permission.
     *
     * Query parameters:
     *   - user: string (required)
     *   - object_type: string (required)
     *   - object_id: string (required)
     *   - relation: string (required)
     */
    private function checkPermission(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $userId,
        bool $isGlobalAdmin
    ): ResponseInterface {
        $params     = $request->getQueryParams();
        $user       = is_string($params['user'] ?? null) ? $params['user'] : '';
        $objectType = is_string($params['object_type'] ?? null) ? $params['object_type'] : '';
        $objectId   = is_string($params['object_id'] ?? null) ? $params['object_id'] : '';
        $relation   = is_string($params['relation'] ?? null) ? $params['relation'] : '';

        $this->validateTupleParams($user, $objectType, $objectId, $relation);
        $this->requireResourceAdmin($userId, $isGlobalAdmin, $objectType, $objectId);

        $fgaUser   = $this->normalizeUser($user);
        $fgaObject = "{$objectType}:{$objectId}";

        $allowed = $this->getClient()->check($fgaUser, $relation, $fgaObject);

        return $this->encodeResponseBody($response, [
            'allowed'  => $allowed,
            'user'     => $fgaUser,
            'relation' => $relation,
            'object'   => $fgaObject,
        ]);
    }

    /**
     * Filter tuples to only those on resources the user administers.
     *
     * @param array<int, array{user: string, relation: string, object: string}> $tuples All tuples
     * @param string $userId The current user's Zitadel ID
     * @param string $objectType The object type being listed
     * @return array<int, array{user: string, relation: string, object: string}> Filtered tuples
     */
    private function filterByAdminAccess(array $tuples, string $userId, string $objectType): array
    {
        // Collect unique object IDs from the tuples
        $objectIds = [];
        foreach ($tuples as $tuple) {
            $parts = explode(':', $tuple['object'], 2);
            if (count($parts) === 2 && $parts[1] !== '') {
                $objectIds[$parts[1]] = true;
            }
        }

        // Check admin access for each unique object
        $fgaUser    = "user:{$userId}";
        $allowedIds = [];
        foreach (array_keys($objectIds) as $objId) {
            $fgaObject = "{$objectType}:{$objId}";
            if ($this->getClient()->check($fgaUser, 'admin', $fgaObject)) {
                $allowedIds[$objId] = true;
            }
        }

        // Filter tuples
        return array_values(array_filter($tuples, function (array $tuple) use ($allowedIds): bool {
            $parts = explode(':', $tuple['object'], 2);
            $objId = $parts[1] ?? '';
            return isset($allowedIds[$objId]);
        }));
    }

    /**
     * Validate tuple parameters.
     *
     * @throws ValidationException If any parameter is invalid
     */
    private function validateTupleParams(string $user, string $objectType, string $objectId, string $relation): void
    {
        if ($user === '') {
            throw new ValidationException('Missing required parameter: user');
        }

        if ($objectType === '') {
            throw new ValidationException('Missing required parameter: object_type');
        }

        if (!in_array($objectType, self::VALID_OBJECT_TYPES, true)) {
            throw new ValidationException(
                sprintf('Invalid object_type "%s". Valid types: %s', $objectType, implode(', ', self::VALID_OBJECT_TYPES))
            );
        }

        if ($objectId === '') {
            throw new ValidationException('Missing required parameter: object_id');
        }

        if ($relation === '') {
            throw new ValidationException('Missing required parameter: relation');
        }

        if (!in_array($relation, self::VALID_RELATIONS, true)) {
            throw new ValidationException(
                sprintf('Invalid relation "%s". Valid relations: %s', $relation, implode(', ', self::VALID_RELATIONS))
            );
        }
    }

    /**
     * Normalize user identifier to OpenFGA format.
     *
     * Accepts either a bare Zitadel user ID or a prefixed "user:id" format.
     */
    private function normalizeUser(string $user): string
    {
        if (!str_starts_with($user, 'user:')) {
            return "user:{$user}";
        }
        return $user;
    }
}
