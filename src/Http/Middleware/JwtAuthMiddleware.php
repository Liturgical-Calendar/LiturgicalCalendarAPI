<?php

namespace LiturgicalCalendar\Api\Http\Middleware;

use LiturgicalCalendar\Api\Http\CookieHelper;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Models\Auth\User;
use LiturgicalCalendar\Api\Services\JwtService;
use LiturgicalCalendar\Api\Services\JwtServiceFactory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * JWT Authentication Middleware
 *
 * This middleware:
 * 1. Extracts JWT token from HttpOnly cookie first (preferred, more secure)
 * 2. Falls back to Authorization header for backwards compatibility
 * 3. Verifies the token using JwtService
 * 4. Attaches authenticated user to request attributes
 * 5. Throws UnauthorizedException if token is missing or invalid
 *
 * @package LiturgicalCalendar\Api\Http\Middleware
 */
class JwtAuthMiddleware implements MiddlewareInterface
{
    private JwtService $jwtService;

    /**
     * Create the middleware and set its JwtService.
     *
     * If a JwtService is provided it will be used; otherwise a service is created from environment via JwtServiceFactory::fromEnv().
     *
     * @param JwtService|null $jwtService Optional JwtService instance to use for token verification; when null a JwtService is created from environment.
     */
    public function __construct(?JwtService $jwtService = null)
    {
        $this->jwtService = $jwtService ?? JwtServiceFactory::fromEnv();
    }

    /**
     * Authenticate the incoming request using a Bearer JWT and forward it to the next handler.
     *
     * If authentication succeeds, the request is augmented with two attributes:
     * - `user`: a User instance created from the JWT payload.
     * - `jwt_payload`: the raw JWT payload.
     *
     * @param ServerRequestInterface  $request The incoming server request.
     * @param RequestHandlerInterface $handler The next request handler to invoke on success.
     * @return ResponseInterface The response returned by the next handler.
     * @throws UnauthorizedException If the Authorization header is missing, has an invalid format,
     *                               the token is missing, the token is invalid or expired, or the
     *                               payload does not correspond to a valid user.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = null;

        // 1. Try to get token from HttpOnly cookie first (preferred, more secure)
        /** @var array<string, string> $cookies */
        $cookies = $request->getCookieParams();
        $token   = CookieHelper::getAccessToken($cookies);

        // 2. Fall back to Authorization header for backwards compatibility
        if ($token === null) {
            $authHeader = $request->getHeaderLine('Authorization');

            if (!empty($authHeader)) {
                // Check for Bearer token format (case-insensitive per RFC 7235)
                if (!str_starts_with(strtolower($authHeader), 'bearer ')) {
                    throw new UnauthorizedException('Invalid Authorization header format. Expected: Bearer <token>');
                }

                // Extract token and trim whitespace
                $token = trim(substr($authHeader, 7)); // Remove "Bearer " prefix and trim
            }
        }

        // No token found in either location
        if ($token === null || $token === '') {
            throw new UnauthorizedException('Missing JWT token');
        }

        // Verify token
        $payload = $this->jwtService->verify($token);

        if ($payload === null) {
            throw new UnauthorizedException('Invalid or expired JWT token');
        }

        // Create user from payload
        $user = User::fromJwtPayload($payload);

        if ($user === null) {
            throw new UnauthorizedException('Invalid user in JWT token');
        }

        // Attach both user and payload to request attributes
        // - 'user': Type-safe User object for authentication/authorization checks
        // - 'jwt_payload': Raw JWT payload for accessing custom claims beyond User properties
        $request = $request->withAttribute('user', $user);
        $request = $request->withAttribute('jwt_payload', $payload);

        // Also set 'oidc_user' attribute for compatibility with AuthorizationMiddleware
        // This allows the same authorization middleware to work with both OIDC and JWT flows
        $request = $request->withAttribute('oidc_user', [
            'sub'   => $user->username,
            'roles' => $user->roles,
        ]);

        // Continue with the request
        return $handler->handle($request);
    }
}
