<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Admin;

use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware;
use LiturgicalCalendar\Api\Services\ZitadelService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin Users Handler
 *
 * Handles admin operations for user management:
 * - GET /admin/users - List all users with roles
 * - DELETE /admin/users/{userId}/roles/{role} - Revoke a role from a user
 *
 * Requires admin role.
 */
final class UsersHandler extends AbstractHandler
{
    public function __construct()
    {
        parent::__construct();

        $this->allowedRequestMethods = [RequestMethod::GET, RequestMethod::DELETE];
        $this->allowedAcceptHeaders  = [AcceptHeader::JSON];
        $this->allowCredentials      = true;
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

        // Check if Zitadel is configured
        if (!ZitadelService::isConfigured()) {
            throw new \RuntimeException('Zitadel service not configured');
        }

        // Parse path to determine action
        $path      = $request->getUri()->getPath();
        $pathParts = explode('/', trim($path, '/'));

        // Expected paths:
        // admin/users
        // admin/users/{userId}/roles/{role}

        if ($method === RequestMethod::GET) {
            return $this->listUsers($response);
        }

        // DELETE requires userId and role
        // Path: admin/users/{userId}/roles/{role}
        // After explode: ["admin", "users", "{userId}", "roles", "{role}"]
        // Indices:        0        1         2           3        4
        if (count($pathParts) < 5 || $pathParts[3] !== 'roles') {
            throw new ValidationException('Invalid request path. Expected: /admin/users/{userId}/roles/{role}');
        }

        $userId = $pathParts[2];
        $role   = $pathParts[4];

        if (empty($userId) || empty($role)) {
            throw new ValidationException('User ID and role are required');
        }

        return $this->revokeRole($response, $userId, $role);
    }

    /**
     * List all users with roles in the project.
     *
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    private function listUsers(ResponseInterface $response): ResponseInterface
    {
        $zitadel = ZitadelService::fromEnv();
        $result  = $zitadel->listProjectUsers();

        // Transform users to include a flat 'roles' array for easier frontend consumption
        $users = [];
        foreach ($result['users'] as $user) {
            // Flatten roles from all grants into a single array
            $roles = [];
            if (isset($user['grants']) && is_array($user['grants'])) {
                foreach ($user['grants'] as $grant) {
                    if (is_array($grant) && isset($grant['roles']) && is_array($grant['roles'])) {
                        $roles = array_merge($roles, $grant['roles']);
                    }
                }
            }
            $user['roles'] = array_values(array_unique($roles));
            $users[]       = $user;
        }

        return $this->encodeResponseBody($response, [
            'users' => $users,
            'total' => $result['total'],
        ]);
    }

    /**
     * Revoke a role from a user.
     *
     * @param ResponseInterface $response
     * @param string $userId Zitadel user ID
     * @param string $role Role to revoke
     * @return ResponseInterface
     */
    private function revokeRole(
        ResponseInterface $response,
        string $userId,
        string $role
    ): ResponseInterface {
        $zitadel = ZitadelService::fromEnv();

        // First verify the user has this role
        $grants  = $zitadel->getUserGrantsWithIds($userId);
        $hasRole = false;

        foreach ($grants as $grant) {
            if (in_array($role, $grant['roles'], true)) {
                $hasRole = true;
                break;
            }
        }

        if (!$hasRole) {
            throw new NotFoundException('User does not have this role');
        }

        // Revoke the role
        $success = $zitadel->revokeUserRole($userId, $role);

        if (!$success) {
            $response = $response->withStatus(StatusCode::INTERNAL_SERVER_ERROR->value);
            return $this->encodeResponseBody($response, [
                'success' => false,
                'message' => 'Failed to revoke role in Zitadel',
            ]);
        }

        $response = $response->withStatus(StatusCode::OK->value);

        return $this->encodeResponseBody($response, [
            'success' => true,
            'message' => 'Role revoked successfully',
        ]);
    }
}
