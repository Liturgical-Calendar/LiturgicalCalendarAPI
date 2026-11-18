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
use LiturgicalCalendar\Api\Services\AccessRequestRedundancyFilter;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
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
 *
 * Create and resubmit drop permissions the user already holds or repeats within the submission
 * (see {@see AccessRequestRedundancyFilter}), report them as `skipped_permissions`, and answer
 * 409 when nothing is left to ask for.
 *
 * @phpstan-import-type Permission from AccessRequestRedundancyFilter
 * @phpstan-import-type SkippedPermission from AccessRequestRedundancyFilter
 */
final class AccessRequestHandler extends AbstractHandler
{
    use AccessTokenTrait;
    use OffsetPaginationTrait;

    /**
     * @var array<string>
     */
    private const VALID_RELATIONS = ['admin', 'editor'];

    /** `viewer` is retired for people (#1049): they read admin data through the role they hold. */
    private const VIEWER_RETIRED_MESSAGE = 'The viewer relation can no longer be requested: read access comes with the role. Request editor or admin instead.';

    /**
     * Per-request timeouts of the OpenFGA client built for the redundancy checks. With the filter's
     * {@see AccessRequestRedundancyFilter::BUDGET_SECONDS} budget, the worst case (budget plus one
     * timeout) stays near 2.5 s, well under the frontend's 5 s request timeout.
     */
    private const FGA_TIMEOUT_SECONDS         = 1.0;
    private const FGA_CONNECT_TIMEOUT_SECONDS = 0.5;

    private const ALL_REDUNDANT_MESSAGE = 'You already have, or have already requested, all of the requested permissions.';

    private ?AccessRequestRepository $repository = null;

    private bool $fgaClientResolved;

    /**
     * @param OpenFgaClient|null $fgaClient Client for the redundancy checks. When null, one is built from the
     *                                      environment if OpenFGA is configured; otherwise nothing counts as granted.
     */
    public function __construct(private ?OpenFgaClient $fgaClient = null)
    {
        parent::__construct();

        $this->fgaClientResolved = $fgaClient !== null;

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

    /**
     * The OpenFGA client for the redundancy checks, or null when OpenFGA is unavailable (fail open).
     */
    private function getFgaClient(): ?OpenFgaClient
    {
        if (!$this->fgaClientResolved) {
            $this->fgaClientResolved = true;
            if (OpenFgaClient::isConfigured()) {
                try {
                    $this->fgaClient = OpenFgaClient::fromEnv(
                        timeout: self::FGA_TIMEOUT_SECONDS,
                        connectTimeout: self::FGA_CONNECT_TIMEOUT_SECONDS
                    );
                } catch (\RuntimeException) {
                    $this->fgaClient = null;
                }
            }
        }
        return $this->fgaClient;
    }

    /**
     * Separate the requested permissions worth asking for from the redundant ones.
     *
     * Returns the permissions to store, the skipped ones to report, and whether the request is role-only.
     * Returns `['conflict' => skipped]` instead when every permission is redundant and the user already holds
     * the role (or the global `admin` role): nothing is left to ask for. A missing `roles` claim means the role
     * is missing.
     *
     * @param array{roles?: array<string>} $oidcUser
     * @param list<Permission> $permissions Validated, canonical permissions
     * @return array{permissions: list<Permission>, skipped: list<SkippedPermission>, role_only: bool}|array{conflict: list<SkippedPermission>}
     */
    private function screenPermissions(
        string $userId,
        array $oidcUser,
        string $requestedRole,
        array $permissions
    ): array {
        // One filter per call: its OpenFGA time budget runs from its first check.
        $result = ( new AccessRequestRedundancyFilter($this->getFgaClient()) )->filter($userId, $permissions);

        if ($result['kept'] !== []) {
            return ['permissions' => $result['kept'], 'skipped' => $result['skipped'], 'role_only' => false];
        }

        $roles   = $oidcUser['roles'] ?? [];
        $hasRole = in_array($requestedRole, $roles, true) || in_array('admin', $roles, true);
        if ($hasRole) {
            return ['conflict' => $result['skipped']];
        }

        // The user lacks the role: the request still asks for it, carrying the permissions as submitted
        // minus in-request duplicates.
        $roleOnly = [];
        foreach ($result['skipped'] as $skipped) {
            if ($skipped['reason'] !== 'duplicate') {
                $roleOnly[] = [
                    'object_type' => $skipped['object_type'],
                    'object_id'   => $skipped['object_id'],
                    'relation'    => $skipped['relation'],
                ];
            }
        }
        return ['permissions' => $roleOnly, 'skipped' => [], 'role_only' => true];
    }

    /**
     * 409: every requested permission is already held or already requested.
     *
     * @param list<SkippedPermission> $skipped
     */
    private function allRedundantResponse(ResponseInterface $response, array $skipped): ResponseInterface
    {
        return $this->encodeResponseBody($response, [
            'success'             => false,
            'message'             => self::ALL_REDUNDANT_MESSAGE,
            'skipped_permissions' => $skipped,
        ], StatusCode::CONFLICT);
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

        /** @var list<Permission> $validatedPermissions */
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

            if (!in_array($objectType, AccessRequestRepository::VALID_OBJECT_TYPES, true)) {
                throw new ValidationException(
                    sprintf(
                        'permissions[%d].object_type "%s" is invalid. Valid types: %s',
                        $index,
                        $objectType,
                        implode(', ', AccessRequestRepository::VALID_OBJECT_TYPES)
                    )
                );
            }

            if ($objectId === '') {
                throw new ValidationException(
                    sprintf('permissions[%d].object_id is required', $index)
                );
            }

            $objectId = AccessRequestRepository::canonicalObjectId($objectType, $objectId);
            if (!AccessRequestRepository::isValidObjectIdForType($objectType, $objectId)) {
                $validIdsLabel = AccessRequestRepository::validIdsLabelForType($objectType);
                throw new ValidationException(
                    sprintf(
                        'permissions[%d].object_id "%s" is invalid for object_type "%s". Valid ids: %s',
                        $index,
                        $objectId,
                        $objectType,
                        $validIdsLabel
                    )
                );
            }

            if ($relation === '') {
                throw new ValidationException(
                    sprintf('permissions[%d].relation is required', $index)
                );
            }

            if ($relation === 'viewer') {
                throw new ValidationException(self::VIEWER_RETIRED_MESSAGE);
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

        $screened = $this->screenPermissions($userId, $oidcUser, $requestedRole, $validatedPermissions);
        if (isset($screened['conflict'])) {
            return $this->allRedundantResponse($response, $screened['conflict']);
        }

        // Create the request
        $requestId = $repo->create(
            $userId,
            $userEmail,
            !empty($userName) ? $userName : null,
            $requestedRole,
            $screened['permissions'],
            is_string($justification) ? $justification : null,
            is_string($credentials) ? $credentials : null
        );

        // encodeResponseBody overwrites the response status with its $statusCode
        // argument (default OK), so the only effective way to emit 201 here is
        // to pass it through rather than calling ->withStatus() first.
        return $this->encodeResponseBody($response, [
            'success'             => true,
            'request_id'          => $requestId,
            'message'             => 'Access request submitted successfully. An administrator will review your request.',
            'skipped_permissions' => $screened['skipped'],
            'role_only'           => $screened['role_only'],
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
        /** @var list<Permission> $validatedPermissions */
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

            if (!in_array($objType, AccessRequestRepository::VALID_OBJECT_TYPES, true)) {
                throw new ValidationException(sprintf(
                    'permissions[%d].object_type "%s" is invalid. Valid types: %s',
                    $index,
                    $objType,
                    implode(', ', AccessRequestRepository::VALID_OBJECT_TYPES)
                ));
            }

            $objId = AccessRequestRepository::canonicalObjectId($objType, $objId);
            if (!AccessRequestRepository::isValidObjectIdForType($objType, $objId)) {
                $validIdsLabel = AccessRequestRepository::validIdsLabelForType($objType);
                throw new ValidationException(sprintf(
                    'permissions[%d].object_id "%s" is invalid for object_type "%s". Valid ids: %s',
                    $index,
                    $objId,
                    $objType,
                    $validIdsLabel
                ));
            }

            if ($relation === 'viewer') {
                throw new ValidationException(self::VIEWER_RETIRED_MESSAGE);
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

        $screened = $this->screenPermissions($userId, $oidcUser, $role, $validatedPermissions);
        if (isset($screened['conflict'])) {
            // Nothing is stored: the request stays rejected.
            return $this->allRedundantResponse($response, $screened['conflict']);
        }

        $resubmitted = $this->getRepository()->resubmit($requestId, $screened['permissions'], $justification);
        if (!$resubmitted) {
            throw new ValidationException('Failed to resubmit request');
        }

        return $this->encodeResponseBody($response, [
            'success'             => true,
            'message'             => 'Access request resubmitted for review',
            'id'                  => $requestId,
            'skipped_permissions' => $screened['skipped'],
            'role_only'           => $screened['role_only'],
        ]);
    }

    /**
     * Fields present on the DB row but intentionally absent from the public
     * AccessRequest schema (per #566). Admin-facing endpoints return the
     * full row via AccessRequestAdmin; self-list strips these so the
     * payload validates against the public schema's
     * `additionalProperties: false`.
     *
     * @var array<string, bool>
     */
    private const PUBLIC_REQUEST_HIDDEN_FIELDS = [
        'reviewed_by'         => true,
        'zitadel_sync_status' => true,
        'zitadel_sync_error'  => true,
    ];

    /**
     * GET /auth/access-requests — List the user's own requests, paginated.
     *
     * Query params (validated via OffsetPaginationTrait):
     *   - limit  (1..500, default 100)
     *   - offset (>=0, default 0)
     *
     * Each row is projected to the public AccessRequest shape — admin-only
     * fields (reviewed_by, zitadel_sync_status, zitadel_sync_error) are
     * stripped. See the AccessRequest / AccessRequestAdmin component
     * definitions in jsondata/schemas/openapi.json.
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

        $publicRequests = array_map(
            static fn(array $row): array => array_diff_key($row, self::PUBLIC_REQUEST_HIDDEN_FIELDS),
            $requests
        );

        return $this->encodeResponseBody($response, [
            'requests' => $publicRequests,
            'count'    => count($publicRequests),
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'has_more' => ( $offset + count($publicRequests) ) < $total,
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
     * - calendar_editor: permissions should target one of the calendar types
     * - test_editor: permissions should target one of the scoped test types
     * - developer: permissions can target any type
     *
     * Both role-scoped lists are read from {@see AccessRequestRepository::ROLE_OBJECT_TYPES}, which is
     * the single source of truth shared with RoleCascadeService. A private duplicate of the
     * calendar list used to live here and silently desynced when the rite_calendar tier was added
     * (#955): the repository accepted the new type but this validator rejected it, so nobody could
     * request a grant on it. Do not reintroduce a local copy.
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

            if ($role === 'calendar_editor' && !in_array($objectType, AccessRequestRepository::ROLE_OBJECT_TYPES['calendar_editor'], true)) {
                throw new ValidationException(
                    sprintf(
                        'permissions[%d].object_type "%s" is not valid for role "calendar_editor". '
                        . 'Calendar editors can only request permissions for: %s',
                        $index,
                        $objectType,
                        implode(', ', AccessRequestRepository::ROLE_OBJECT_TYPES['calendar_editor'])
                    )
                );
            }

            if ($role === 'test_editor' && !in_array($objectType, AccessRequestRepository::ROLE_OBJECT_TYPES['test_editor'], true)) {
                throw new ValidationException(
                    sprintf(
                        'permissions[%d].object_type "%s" is not valid for role "test_editor". '
                        . 'Test editors can only request permissions for: %s',
                        $index,
                        $objectType,
                        implode(', ', AccessRequestRepository::ROLE_OBJECT_TYPES['test_editor'])
                    )
                );
            }
        }
    }
}
