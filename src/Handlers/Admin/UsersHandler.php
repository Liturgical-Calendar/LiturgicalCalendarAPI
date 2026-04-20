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
 * - GET /admin/users - List all users (with and without roles)
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
            return $this->listUsers($request, $response);
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

    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT     = 1000;

    /**
     * List all users (with and without roles).
     *
     * Accepts optional `limit` and `offset` query parameters for pagination.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    private function listUsers(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $queryParams = $request->getQueryParams();

        $limit  = self::DEFAULT_LIMIT;
        $offset = 0;

        if (isset($queryParams['limit'])) {
            $limitParam = filter_var($queryParams['limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_LIMIT]]);
            if ($limitParam === false) {
                throw new ValidationException('limit must be an integer between 1 and ' . self::MAX_LIMIT);
            }
            $limit = $limitParam;
        }

        if (isset($queryParams['offset'])) {
            $offsetParam = filter_var($queryParams['offset'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($offsetParam === false) {
                throw new ValidationException('offset must be a non-negative integer');
            }
            $offset = $offsetParam;
        }

        $zitadel = ZitadelService::fromEnv();

        // Fetch all users with roles (high limit to ensure complete role data for merging)
        $usersWithRolesResult = $zitadel->listProjectUsers(self::MAX_LIMIT);

        // Fetch paginated list of all users
        $allUsersResult = $zitadel->listAllUsers($limit, $offset);

        // Defensive checks for API response structure
        $usersWithRolesArray = $usersWithRolesResult['users'] ?? [];
        $allUsersArray       = $allUsersResult['users'] ?? [];

        // Build a map of users with roles (keyed by userId)
        /** @var array<string, array<string, mixed>> $usersWithRolesMap */
        $usersWithRolesMap = [];
        foreach ($usersWithRolesArray as $user) {
            // Flatten roles from all grants into a single array
            $roles = [];
            if (isset($user['grants']) && is_array($user['grants'])) {
                foreach ($user['grants'] as $grant) {
                    if (is_array($grant) && isset($grant['roles']) && is_array($grant['roles'])) {
                        $roles = array_merge($roles, $grant['roles']);
                    }
                }
            }
            $user['roles'] = array_values(array_unique(array_filter($roles, 'is_string')));
            $userId        = $user['userId'] ?? null;
            if (is_string($userId)) {
                $usersWithRolesMap[$userId] = $user;
            }
        }

        // Separate users into those with roles and those without
        $usersWithRoles    = [];
        $usersWithoutRoles = [];
        $seenUserIds       = [];

        foreach ($allUsersArray as $user) {
            $userId = $user['userId'] ?? null;
            if (!is_string($userId)) {
                continue;
            }
            $seenUserIds[$userId] = true;
            if (isset($usersWithRolesMap[$userId])) {
                // User has roles - merge role info with email verification from the all-users list
                $userWithRoles                  = $usersWithRolesMap[$userId];
                $userWithRoles['emailVerified'] = $user['emailVerified'] ?? false;
                $usersWithRoles[]               = $userWithRoles;
            } else {
                // User has no roles
                $user['roles']       = [];
                $usersWithoutRoles[] = $user;
            }
        }

        // Ensure all role-bearing users are included, even if not in allUsersArray
        // (e.g., due to pagination limits in listAllUsers)
        foreach ($usersWithRolesMap as $userId => $userWithRoles) {
            if (!isset($seenUserIds[$userId])) {
                // User has roles but wasn't in allUsersArray - add with emailVerified defaulting to false
                $userWithRoles['emailVerified'] = $userWithRoles['emailVerified'] ?? false;
                $usersWithRoles[]               = $userWithRoles;
            }
        }

        $processedTotal = count($usersWithRoles) + count($usersWithoutRoles);
        $reportedTotal  = $allUsersResult['total'] ?? 0;

        return $this->encodeResponseBody($response, [
            'usersWithRoles'    => $usersWithRoles,
            'usersWithoutRoles' => $usersWithoutRoles,
            'totalWithRoles'    => count($usersWithRoles),
            'totalWithoutRoles' => count($usersWithoutRoles),
            'total'             => $processedTotal,
            'reportedTotal'     => $reportedTotal,
            'pagination'        => [
                'limit'   => $limit,
                'offset'  => $offset,
                'hasMore' => ( $offset + $limit ) < $reportedTotal,
            ],
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
