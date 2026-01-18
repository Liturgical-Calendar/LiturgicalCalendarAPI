<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Http\Middleware;

use LiturgicalCalendar\Api\Enum\CalendarType;
use LiturgicalCalendar\Api\Enum\PermissionLevel;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Repositories\CalendarPermissionRepository;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authorization Middleware for role and permission checks.
 *
 * This middleware runs after OidcAuthMiddleware and validates:
 * 1. User has required Zitadel role
 * 2. User has calendar-specific permission (if applicable)
 *
 * Admin users bypass all checks.
 */
class AuthorizationMiddleware implements MiddlewareInterface
{
    private CalendarPermissionRepository $permissionRepo;

    /**
     * Required Zitadel role.
     */
    private string $requiredRole;

    /**
     * Calendar type for permission check (null = no calendar check).
     */
    private ?CalendarType $calendarType;

    /**
     * Request attribute name for calendar ID.
     */
    private string $calendarIdAttribute;

    /**
     * Permission level required.
     */
    private PermissionLevel $permissionLevel;

    /**
     * Create authorization middleware.
     *
     * @param CalendarPermissionRepository $permissionRepo Permission repository
     * @param string $requiredRole Required Zitadel role (e.g., 'calendar_editor')
     * @param CalendarType|null $calendarType Calendar type for permission check (null = no check)
     * @param string $calendarIdAttribute Request attribute name containing calendar ID
     * @param PermissionLevel $permissionLevel Required permission level
     */
    public function __construct(
        CalendarPermissionRepository $permissionRepo,
        string $requiredRole,
        ?CalendarType $calendarType = null,
        string $calendarIdAttribute = 'calendar_id',
        PermissionLevel $permissionLevel = PermissionLevel::WRITE
    ) {
        $this->permissionRepo      = $permissionRepo;
        $this->requiredRole        = $requiredRole;
        $this->calendarType        = $calendarType;
        $this->calendarIdAttribute = $calendarIdAttribute;
        $this->permissionLevel     = $permissionLevel;
    }

    /**
     * Process the request and check authorization.
     *
     * @param ServerRequestInterface $request Incoming request
     * @param RequestHandlerInterface $handler Next handler
     * @return ResponseInterface Response from next handler
     * @throws UnauthorizedException If user is not authenticated
     * @throws ForbiddenException If user lacks required role or permission
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var array{sub?: string, roles?: array<string>}|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');

        if ($oidcUser === null) {
            throw new UnauthorizedException('Not authenticated');
        }

        $userId = $oidcUser['sub'] ?? null;
        if ($userId === null) {
            throw new UnauthorizedException('Invalid user token');
        }

        /** @var array<string> $roles */
        $roles = $oidcUser['roles'] ?? [];

        // Admin users bypass all checks
        if (in_array('admin', $roles, true)) {
            return $handler->handle($request);
        }

        // Check required role
        if (!in_array($this->requiredRole, $roles, true)) {
            throw new ForbiddenException(
                sprintf('Missing required role: %s', $this->requiredRole)
            );
        }

        // Check calendar-specific permission if applicable
        if ($this->calendarType !== null) {
            $calendarId = $this->extractCalendarId($request);

            if ($calendarId !== null) {
                $hasPermission = $this->permissionRepo->hasPermission(
                    $userId,
                    $this->calendarType,
                    $calendarId,
                    $this->permissionLevel
                );

                if (!$hasPermission) {
                    throw new ForbiddenException(
                        sprintf(
                            'No %s permission for %s calendar: %s',
                            $this->permissionLevel->value,
                            $this->calendarType->value,
                            $calendarId
                        )
                    );
                }
            }
        }

        return $handler->handle($request);
    }

    /**
     * Extract calendar ID from request.
     *
     * Looks in request attributes first, then route parameters, then query params.
     *
     * @param ServerRequestInterface $request The request
     * @return string|null Calendar ID or null if not found
     */
    private function extractCalendarId(ServerRequestInterface $request): ?string
    {
        // Check request attribute (set by router)
        $calendarId = $request->getAttribute($this->calendarIdAttribute);
        if ($calendarId !== null && ( is_string($calendarId) || is_int($calendarId) )) {
            return (string) $calendarId;
        }

        // Check query parameters
        $queryParams = $request->getQueryParams();
        if (isset($queryParams[$this->calendarIdAttribute])) {
            $value = $queryParams[$this->calendarIdAttribute];
            if (is_string($value) || is_int($value)) {
                return (string) $value;
            }
        }

        // For POST/PUT/PATCH, check parsed body
        $parsedBody = $request->getParsedBody();
        if (is_array($parsedBody) && isset($parsedBody[$this->calendarIdAttribute])) {
            $value = $parsedBody[$this->calendarIdAttribute];
            if (is_string($value) || is_int($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Create middleware for calendar editor role with calendar permission.
     *
     * @param CalendarPermissionRepository $permissionRepo Permission repository
     * @param CalendarType $calendarType Calendar type
     * @return self Configured middleware instance
     */
    public static function forCalendarEditor(
        CalendarPermissionRepository $permissionRepo,
        CalendarType $calendarType
    ): self {
        return new self(
            $permissionRepo,
            'calendar_editor',
            $calendarType,
            'calendar_id',
            PermissionLevel::WRITE
        );
    }

    /**
     * Create middleware for developer role.
     *
     * Developers only need the Zitadel role, not calendar-specific permissions.
     * The permission level is unused since calendarType is null.
     *
     * @param CalendarPermissionRepository $permissionRepo Permission repository
     * @return self Configured middleware instance
     */
    public static function forDeveloper(CalendarPermissionRepository $permissionRepo): self
    {
        // calendarType=null means no calendar permission check is performed,
        // so the permission level parameter is unused (role check only)
        return new self(
            $permissionRepo,
            'developer',
            null,
            'calendar_id',
            PermissionLevel::READ
        );
    }

    /**
     * Create middleware for test editor role.
     *
     * @param CalendarPermissionRepository $permissionRepo Permission repository
     * @return self Configured middleware instance
     */
    public static function forTestEditor(CalendarPermissionRepository $permissionRepo): self
    {
        return new self(
            $permissionRepo,
            'test_editor',
            null,
            'calendar_id',
            PermissionLevel::WRITE
        );
    }

    /**
     * Create middleware for admin-only access.
     *
     * Note: In process(), admin users bypass all checks (line 100-102) before
     * the role check happens. This means the middleware would never throw
     * ForbiddenException for missing role since admins always pass. This is
     * intentional: by requiring the 'admin' role, non-admin users are blocked
     * at line 105-109, while admin users pass via the bypass at line 100-102.
     * The factory effectively ensures only admins can access protected endpoints.
     *
     * @param CalendarPermissionRepository $permissionRepo Permission repository
     * @return self Configured middleware instance
     */
    public static function forAdmin(CalendarPermissionRepository $permissionRepo): self
    {
        return new self(
            $permissionRepo,
            'admin',
            null,
            'calendar_id',
            PermissionLevel::WRITE
        );
    }
}
