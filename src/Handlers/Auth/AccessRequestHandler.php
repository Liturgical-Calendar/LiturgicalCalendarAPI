<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Auth;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
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
        $method   = RequestMethod::from($request->getMethod());

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
        if ($userId === null || ( is_string($userId) && trim($userId) === '' )) {
            throw new UnauthorizedException('Invalid authentication token');
        }

        if (!Connection::isConfigured()) {
            throw new \RuntimeException('Database not configured');
        }

        // Determine action based on path and method
        $path = $request->getUri()->getPath();

        if ($method === RequestMethod::POST) {
            return $this->createRequest($request, $response, $oidcUser);
        }

        // GET requests
        if (str_ends_with($path, '/status')) {
            return $this->getStatus($response, $oidcUser);
        }

        return $this->listOwnRequests($response, $userId);
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
            $rawBody = (string) $request->getBody();
            $body    = json_decode($rawBody, true);
            if (!is_array($body)) {
                throw new ValidationException('Invalid request body');
            }
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

        // Check if user already has a pending request for this role
        $repo = $this->getRepository();
        if ($repo->hasPendingRequest($userId, $requestedRole)) {
            throw new ValidationException('You already have a pending request for this role');
        }

        // Check if user already has this role in Zitadel
        /** @var array<string> $currentRoles */
        $currentRoles = $oidcUser['roles'] ?? [];
        if (in_array($requestedRole, $currentRoles, true)) {
            throw new ValidationException('You already have this role');
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

        $response = $response->withStatus(StatusCode::CREATED->value);

        return $this->encodeResponseBody($response, [
            'success'    => true,
            'request_id' => $requestId,
            'message'    => 'Access request submitted successfully. An administrator will review your request.',
        ]);
    }

    /**
     * GET /auth/access-requests — List the user's own requests.
     *
     * @param ResponseInterface $response
     * @param string $userId
     * @return ResponseInterface
     */
    private function listOwnRequests(ResponseInterface $response, string $userId): ResponseInterface
    {
        $requests = $this->getRepository()->getByUser($userId);

        return $this->encodeResponseBody($response, [
            'requests' => $requests,
            'count'    => count($requests),
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

        // User needs to request access if they have no roles and no pending requests
        $needsAccessRequest = !$hasRoles && $pendingCount === 0;

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
