<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Auth;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Handlers\Pagination\OffsetPaginationTrait;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Access Request Handler — user-facing endpoints.
 *
 * Replaces both RoleRequestHandler and PermissionRequestHandler
 * with a unified workflow where users request a role together
 * with the specific permissions they need.
 *
 * - POST /auth/access-requests          — Submit a new access request (role + permissions)
 * - GET  /auth/access-requests          — List user's own requests
 * - GET  /auth/access-requests/status   — Check if user needs to request access
 */
final class AccessRequestHandler extends AbstractHandler
{
    use AccessTokenTrait;
    use OffsetPaginationTrait;

    /**
     * @var array<string>
     */
    private const VALID_OBJECT_TYPES = [
        'national_calendar',
        'diocesan_calendar',
        'wider_region',
        'test_definition',
    ];

    /**
     * @var array<string>
     */
    private const VALID_RELATIONS = ['admin', 'viewer', 'editor', 'deleter'];

    /**
     * Calendar-related object types for calendar_editor role.
     *
     * @var array<string>
     */
    private const CALENDAR_OBJECT_TYPES = [
        'national_calendar',
        'diocesan_calendar',
        'wider_region',
    ];

    private ?AccessRequestRepository $repository = null;

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

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = static::initResponse($request);
        // tryFrom returns null for unrecognized methods; let validateRequestMethod
        // surface that as a 405 instead of a ValueError → 500.
        $method = RequestMethod::tryFrom($request->getMethod());

        if ($method === null) {
            $this->validateRequestMethod($request);
        }

        if ($method === RequestMethod::OPTIONS) {
            return $this->handlePreflightRequest($request, $response);
        }

        $response = $this->setAccessControlAllowOriginHeader($request, $response);
        $this->validateRequestMethod($request);

        $mime     = $this->validateAcceptHeader($request, AcceptabilityLevel::LAX);
        $response = $response->withHeader('Content-Type', $mime);

        // Check authentication via OIDC token in request attribute
        /** @var array{sub?: string, email?: string, name?: string, preferred_username?: string, roles?: array<string>}|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');

        if ($oidcUser === null) {
            throw new UnauthorizedException('Authentication required');
        }

        $userId = $oidcUser['sub'] ?? null;
        if (!is_string($userId) || trim($userId) === '') {
            throw new UnauthorizedException('Invalid authentication token');
        }

        if (!Connection::isConfigured()) {
            throw new \RuntimeException('Database not configured');
        }

        // Determine action based on path and method
        $path = $request->getUri()->getPath();

        if ($method === RequestMethod::POST) {
            // Check if this is a resubmit: POST /auth/access-requests/{id}/resubmit
            if (str_ends_with($path, '/resubmit')) {
                $pathParts = explode('/', trim($path, '/'));
                $partCount = count($pathParts);
                if ($partCount >= 4) {
                    $requestId = $pathParts[$partCount - 2];
                    return $this->resubmitRequest($request, $response, $oidcUser, $requestId);
                }
            }
            return $this->createRequest($request, $response, $oidcUser);
        }

        // GET requests
        if (str_ends_with($path, '/status')) {
            return $this->getStatus($response, $oidcUser);
        }

        return $this->listOwnRequests($request, $response, $userId);
    }

    /**
     * POST /auth/access-requests — Submit a new access request.
     *
     * @param ServerRequestInterface $request The HTTP request
     * @param ResponseInterface $response The HTTP response
     * @param array{sub?: string, email?: string, name?: string, preferred_username?: string, roles?: array<string>} $oidcUser
     * @return ResponseInterface
     */
    private function createRequest(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $oidcUser
    ): ResponseInterface {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ValidationException('Request body must be JSON');
        }

        $userId = $oidcUser['sub'] ?? '';

        // Get user info from token first, fall back to request body
        $userEmail = $oidcUser['email'] ?? '';
        if (empty($userEmail) && isset($body['email']) && is_string($body['email'])) {
            $userEmail = $body['email'];
        }

        $userName = $oidcUser['name'] ?? $oidcUser['preferred_username'] ?? '';
        if (empty($userName) && isset($body['name']) && is_string($body['name'])) {
            $userName = $body['name'];
        }

        $requestedRole = $body['requested_role'] ?? null;
        $permissions   = $body['permissions'] ?? null;
        $justification = $body['justification'] ?? null;
        $credentials   = $body['credentials'] ?? null;

        // Validate requested_role
        if (!is_string($requestedRole) || empty($requestedRole)) {
            throw new ValidationException('requested_role is required');
        }

        if (!in_array($requestedRole, AccessRequestRepository::VALID_ROLES, true)) {
            throw new ValidationException(
                sprintf('Invalid requested_role. Valid roles are: %s', implode(', ', AccessRequestRepository::VALID_ROLES))
            );
        }

        // Validate permissions array
        if (!is_array($permissions) || empty($permissions)) {
            throw new ValidationException('permissions is required and must be a non-empty array');
        }

        /** @var array<int, array{object_type: string, object_id: string, relation: string}> $validatedPermissions */
        $validatedPermissions = [];

        foreach ($permissions as $index => $perm) {
            if (!is_array($perm)) {
                throw new ValidationException(
                    sprintf('permissions[%d] must be an object', $index)
                );
            }

            $objectType = is_string($perm['object_type'] ?? null) ? $perm['object_type'] : '';
            $objectId   = is_string($perm['object_id'] ?? null) ? $perm['object_id'] : '';
            $relation   = is_string($perm['relation'] ?? null) ? $perm['relation'] : '';

            if ($objectType === '') {
                throw new ValidationException(
                    sprintf('permissions[%d].object_type is required', $index)
                );
            }

            if (!in_array($objectType, self::VALID_OBJECT_TYPES, true)) {
                throw new ValidationException(
                    sprintf(
                        'permissions[%d].object_type "%s" is invalid. Valid types: %s',
                        $index,
                        $objectType,
                        implode(', ', self::VALID_OBJECT_TYPES)
                    )
                );
            }

            if ($objectId === '') {
                throw new ValidationException(
                    sprintf('permissions[%d].object_id is required', $index)
                );
            }

            if ($relation === '') {
                throw new ValidationException(
                    sprintf('permissions[%d].relation is required', $index)
                );
            }

            if (!in_array($relation, self::VALID_RELATIONS, true)) {
                throw new ValidationException(
                    sprintf(
                        'permissions[%d].relation "%s" is invalid. Valid relations: %s',
                        $index,
                        $relation,
                        implode(', ', self::VALID_RELATIONS)
                    )
                );
            }

            $validatedPermissions[] = [
                'object_type' => $objectType,
                'object_id'   => $objectId,
                'relation'    => $relation,
            ];
        }

        // Validate role-permission consistency
        $this->validateRolePermissionConsistency($requestedRole, $validatedPermissions);

        // Check if user already has a pending request for this role.
        // Note: deliberately NOT blocking when the user already holds the role.
        // A user with role X granted via a previous access request is allowed
        // to submit additional access requests for the same role to add more
        // resource permissions (e.g., calendar editor with permissions for IT
        // requesting permissions for US). The role grant on approval is
        // idempotent in Zitadel; the new permission tuples are additive.
        $repo = $this->getRepository();
        if ($repo->hasPendingRequest($userId, $requestedRole)) {
            throw new ValidationException('You already have a pending request for this role');
        }

        // Create the request
        $requestId = $repo->create(
            $userId,
            $userEmail,
            !empty($userName) ? $userName : null,
            $requestedRole,
            $validatedPermissions,
            is_string($justification) ? $justification : null,
            is_string($credentials) ? $credentials : null
        );

        // encodeResponseBody overwrites the response status with its $statusCode
        // argument (default OK), so the only effective way to emit 201 here is
        // to pass it through rather than calling ->withStatus() first.
        return $this->encodeResponseBody($response, [
            'success'    => true,
            'request_id' => $requestId,
            'message'    => 'Access request submitted successfully. An administrator will review your request.',
        ], StatusCode::CREATED);
    }

    /**
     * POST /auth/access-requests/{id}/resubmit — Resubmit a rejected request.
     *
     * Allows the user to update their permissions and resubmit for review.
     * Only the user who created the request can resubmit it, and only
     * if the request was rejected.
     *
     * @param ServerRequestInterface $request The HTTP request
     * @param ResponseInterface $response The HTTP response
     * @param array{sub?: string, email?: string, name?: string, preferred_username?: string, roles?: array<string>} $oidcUser
     * @param string $requestId UUID of the request to resubmit
     */
    private function resubmitRequest(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $oidcUser,
        string $requestId
    ): ResponseInterface {
        $userId = $oidcUser['sub'] ?? '';

        // Validate UUID format
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $requestId)) {
            throw new ValidationException('Invalid request ID format');
        }

        // Get the existing request
        $existing = $this->getRepository()->getById($requestId);
        if ($existing === null) {
            throw new ValidationException('Access request not found');
        }

        // Verify ownership — only the user who created the request can resubmit
        if (( $existing['zitadel_user_id'] ?? '' ) !== $userId) {
            throw new ForbiddenException('You can only resubmit your own requests');
        }

        // Only rejected requests can be resubmitted
        if (( $existing['status'] ?? '' ) !== 'rejected') {
            throw new ValidationException(
                sprintf('Cannot resubmit a request with status: %s. Only rejected requests can be resubmitted.', is_string($existing['status'] ?? null) ? $existing['status'] : 'unknown')
            );
        }

        // Block resubmit when another pending request for the same role already exists.
        // Mirrors the in-memory check createRequest() makes; the partial unique index
        // idx_access_requests_unique_pending_user_role catches the race at the DB level,
        // but checking here gives a clean 422 instead of letting the constraint
        // violation bubble up as a 500.
        $requestedRole = is_string($existing['requested_role'] ?? null) ? $existing['requested_role'] : '';
        if ($requestedRole !== '' && $this->getRepository()->hasPendingRequest($userId, $requestedRole)) {
            throw new ValidationException('You already have a pending request for this role');
        }

        // Parse updated permissions from body
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $rawBody = (string) $request->getBody();
            $body    = json_decode($rawBody, true);
            if (!is_array($body)) {
                throw new ValidationException('Request body must be JSON');
            }
        }

        $permissions = $body['permissions'] ?? null;
        if (!is_array($permissions) || count($permissions) === 0) {
            throw new ValidationException('At least one permission is required');
        }

        // Validate each permission against the same constraints as createRequest
        $role = is_string($existing['requested_role'] ?? null) ? $existing['requested_role'] : '';
        /** @var array<int, array{object_type: string, object_id: string, relation: string}> $validatedPermissions */
        $validatedPermissions = [];
        foreach ($permissions as $index => $perm) {
            if (!is_array($perm)) {
                throw new ValidationException('Each permission must be an object');
            }
            $objType  = is_string($perm['object_type'] ?? null) ? $perm['object_type'] : '';
            $objId    = is_string($perm['object_id'] ?? null) ? $perm['object_id'] : '';
            $relation = is_string($perm['relation'] ?? null) ? $perm['relation'] : '';

            if ($objType === '' || $objId === '' || $relation === '') {
                throw new ValidationException('Each permission requires object_type, object_id, and relation');
            }

            if (!in_array($objType, self::VALID_OBJECT_TYPES, true)) {
                throw new ValidationException(sprintf(
                    'permissions[%d].object_type "%s" is invalid. Valid types: %s',
                    $index,
                    $objType,
                    implode(', ', self::VALID_OBJECT_TYPES)
                ));
            }

            if (!in_array($relation, self::VALID_RELATIONS, true)) {
                throw new ValidationException(sprintf(
                    'permissions[%d].relation "%s" is invalid. Valid relations: %s',
                    $index,
                    $relation,
                    implode(', ', self::VALID_RELATIONS)
                ));
            }

            $validatedPermissions[] = [
                'object_type' => $objType,
                'object_id'   => $objId,
                'relation'    => $relation,
            ];
        }

        // Validate role-permission consistency
        $this->validateRolePermissionConsistency($role, $validatedPermissions);

        $justification = isset($body['justification']) && is_string($body['justification'])
            ? $body['justification']
            : null;

        $resubmitted = $this->getRepository()->resubmit($requestId, $validatedPermissions, $justification);
        if (!$resubmitted) {
            throw new ValidationException('Failed to resubmit request');
        }

        return $this->encodeResponseBody($response, [
            'success' => true,
            'message' => 'Access request resubmitted for review',
            'id'      => $requestId,
        ]);
    }

    /**
     * GET /auth/access-requests — List the user's own requests, paginated.
     *
     * Query params (validated via OffsetPaginationTrait):
     *   - limit  (1..500, default 100)
     *   - offset (>=0, default 0)
     *
     * Response envelope: requests[], count, total, limit, offset, has_more.
     */
    private function listOwnRequests(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $userId
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $limit  = $this->parseLimit($params['limit'] ?? null);
        $offset = $this->parseOffset($params['offset'] ?? null);

        $repo     = $this->getRepository();
        $requests = $repo->getByUser($userId, $limit, $offset);
        $total    = $repo->countByUser($userId);

        return $this->encodeResponseBody($response, [
            'requests' => $requests,
            'count'    => count($requests),
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'has_more' => ( $offset + count($requests) ) < $total,
        ]);
    }

    /**
     * GET /auth/access-requests/status — Check if user needs to request access.
     *
     * Returns info about user's current roles and pending requests.
     *
     * @param ResponseInterface $response
     * @param array{sub?: string, roles?: array<string>} $oidcUser
     * @return ResponseInterface
     */
    private function getStatus(ResponseInterface $response, array $oidcUser): ResponseInterface
    {
        $userId = $oidcUser['sub'] ?? '';
        /** @var array<string> $currentRoles */
        $currentRoles = $oidcUser['roles'] ?? [];

        $repo = $this->getRepository();

        // Check if user has any roles
        $hasRoles = !empty($currentRoles);

        // Check if user has any pending requests
        $userRequests  = $repo->getByUser($userId);
        $pendingCount  = 0;
        $approvedCount = 0;
        $rejectedCount = 0;

        foreach ($userRequests as $req) {
            $status = $req['status'] ?? '';
            if ($status === 'pending') {
                $pendingCount++;
            } elseif ($status === 'approved') {
                $approvedCount++;
            } elseif ($status === 'rejected') {
                $rejectedCount++;
            }
        }

        // User needs to request access only if they have no roles, no pending request,
        // and no approved request (an approved request may not yet have synced to roles).
        $needsAccessRequest = !$hasRoles && $pendingCount === 0 && $approvedCount === 0;

        return $this->encodeResponseBody($response, [
            'has_roles'            => $hasRoles,
            'current_roles'        => $currentRoles,
            'pending_requests'     => $pendingCount,
            'approved_requests'    => $approvedCount,
            'rejected_requests'    => $rejectedCount,
            'needs_access_request' => $needsAccessRequest,
            'valid_roles'          => AccessRequestRepository::VALID_ROLES,
        ]);
    }

    /**
     * Validate that the requested permissions are consistent with the requested role.
     *
     * - calendar_editor: permissions should target calendar types (national_calendar, diocesan_calendar, wider_region)
     * - test_editor: permissions should target test_definition
     * - developer: permissions can target any type
     *
     * @param string $role The requested role
     * @param array<int, array{object_type: string, object_id: string, relation: string}> $permissions
     * @throws ValidationException If permissions are inconsistent with the role
     */
    private function validateRolePermissionConsistency(string $role, array $permissions): void
    {
        if ($role === 'developer') {
            // Developer can target any object type
            return;
        }

        foreach ($permissions as $index => $perm) {
            $objectType = $perm['object_type'];

            if ($role === 'calendar_editor' && !in_array($objectType, self::CALENDAR_OBJECT_TYPES, true)) {
                throw new ValidationException(
                    sprintf(
                        'permissions[%d].object_type "%s" is not valid for role "calendar_editor". '
                        . 'Calendar editors can only request permissions for: %s',
                        $index,
                        $objectType,
                        implode(', ', self::CALENDAR_OBJECT_TYPES)
                    )
                );
            }

            if ($role === 'test_editor' && $objectType !== 'test_definition') {
                throw new ValidationException(
                    sprintf(
                        'permissions[%d].object_type "%s" is not valid for role "test_editor". '
                        . 'Test editors can only request permissions for: test_definition',
                        $index,
                        $objectType
                    )
                );
            }
        }
    }
}
