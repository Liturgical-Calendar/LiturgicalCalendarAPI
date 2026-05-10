<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Http\Middleware;

use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authorization Middleware for Zitadel role checks.
 *
 * This middleware runs after OidcAuthMiddleware and validates that the
 * authenticated user has the required Zitadel role. Admin users bypass
 * all checks.
 *
 * Per-resource permission checks (e.g., "can this user edit Italy's calendar?")
 * are handled by OpenFgaAuthorizationMiddleware, which runs after this middleware.
 */
class AuthorizationMiddleware implements MiddlewareInterface
{
    /**
     * Required Zitadel role.
     */
    private string $requiredRole;

    /**
     * Create authorization middleware.
     *
     * @param string $requiredRole Required Zitadel role (e.g., 'calendar_editor')
     */
    public function __construct(string $requiredRole)
    {
        $this->requiredRole = $requiredRole;
    }

    /**
     * Process the request and check authorization.
     *
     * @param ServerRequestInterface $request Incoming request
     * @param RequestHandlerInterface $handler Next handler
     * @return ResponseInterface Response from next handler
     * @throws UnauthorizedException If user is not authenticated
     * @throws ForbiddenException If user lacks required role
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

        return $handler->handle($request);
    }

    /**
     * Create middleware for calendar editor role.
     */
    public static function forCalendarEditor(): self
    {
        return new self('calendar_editor');
    }

    /**
     * Create middleware for developer role.
     */
    public static function forDeveloper(): self
    {
        return new self('developer');
    }

    /**
     * Create middleware for test editor role.
     */
    public static function forTestEditor(): self
    {
        return new self('test_editor');
    }

    /**
     * Create middleware for admin-only access.
     */
    public static function forAdmin(): self
    {
        return new self('admin');
    }
}
