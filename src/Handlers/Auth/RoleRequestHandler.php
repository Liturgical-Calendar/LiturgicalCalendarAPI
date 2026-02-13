<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Auth;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware;
use LiturgicalCalendar\Api\Repositories\RoleRequestRepository;
use LiturgicalCalendar\Api\Services\ZitadelService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Role Request Handler
 *
 * Handles role request operations:
 * - POST /auth/role-requests - Create a new role request
 * - GET /auth/role-requests - Get user's own role requests
 * - GET /auth/role-requests/status - Check if user needs to request a role
 *
 * Admin operations are handled via separate admin endpoints.
 */
final class RoleRequestHandler extends AbstractHandler
{
    use AccessTokenTrait;

    private ?RoleRequestRepository $repository = null;

    public function __construct()
    {
        parent::__construct();

        $this->allowedRequestMethods = [RequestMethod::GET, RequestMethod::POST];
        $this->allowedAcceptHeaders  = [AcceptHeader::JSON];
        $this->allowCredentials      = true;
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

        // Check authentication via OIDC token in request attribute
        /** @var array{sub?: string, email?: string, name?: string, preferred_username?: string, roles?: array<string>}|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');

        if ($oidcUser === null) {
            throw new UnauthorizedException('Authentication required');
        }

        $userId = $oidcUser['sub'] ?? null;
        if ($userId === null) {
            throw new UnauthorizedException('Invalid authentication token');
        }

        // Check if database is configured
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

        return $this->getUserRequests($response, $userId);
    }

    /**
     * Create a new role request.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array{sub?: string, email?: string, name?: string, roles?: array<string>} $oidcUser
     * @return ResponseInterface
     */
    private function createRequest(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $oidcUser
    ): ResponseInterface {
        // Parse request body
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
        // (Access tokens may not include profile claims, but frontend can send from ID token)
        $userEmail = $oidcUser['email'] ?? '';
        if (empty($userEmail) && isset($body['email']) && is_string($body['email'])) {
            $userEmail = $body['email'];
        }

        $userName = $oidcUser['name'] ?? $oidcUser['preferred_username'] ?? '';
        if (empty($userName) && isset($body['name']) && is_string($body['name'])) {
            $userName = $body['name'];
        }

        $requestedRole = $body['role'] ?? null;
        $justification = $body['justification'] ?? null;

        if (!is_string($requestedRole) || empty($requestedRole)) {
            throw new ValidationException('Role is required');
        }

        if (!in_array($requestedRole, RoleRequestRepository::VALID_ROLES, true)) {
            throw new ValidationException(
                sprintf('Invalid role. Valid roles are: %s', implode(', ', RoleRequestRepository::VALID_ROLES))
            );
        }

        $repo = $this->getRepository();

        // Check if user already has a pending request for this role
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
        $requestId = $repo->createRequest(
            $userId,
            $userEmail,
            $userName,
            $requestedRole,
            is_string($justification) ? $justification : null
        );

        $response = $response->withStatus(StatusCode::CREATED->value);

        return $this->encodeResponseBody($response, [
            'success'    => true,
            'request_id' => $requestId,
            'message'    => 'Role request submitted successfully. An administrator will review your request.',
        ]);
    }

    /**
     * Get user's own role requests.
     *
     * @param ResponseInterface $response
     * @param string $userId
     * @return ResponseInterface
     */
    private function getUserRequests(ResponseInterface $response, string $userId): ResponseInterface
    {
        $repo     = $this->getRepository();
        $requests = $repo->getRequestsForUser($userId);

        return $this->encodeResponseBody($response, ['requests' => $requests]);
    }

    /**
     * Get role request status for user.
     *
     * Returns whether the user needs to request a role (has no roles and no pending requests).
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
        $userRequests  = $repo->getRequestsForUser($userId);
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

        // User needs to request a role if they have no roles and no pending requests
        $needsRoleRequest = !$hasRoles && $pendingCount === 0;

        return $this->encodeResponseBody($response, [
            'has_roles'          => $hasRoles,
            'current_roles'      => $currentRoles,
            'pending_requests'   => $pendingCount,
            'approved_requests'  => $approvedCount,
            'rejected_requests'  => $rejectedCount,
            'needs_role_request' => $needsRoleRequest,
            'valid_roles'        => RoleRequestRepository::VALID_ROLES,
        ]);
    }
}
