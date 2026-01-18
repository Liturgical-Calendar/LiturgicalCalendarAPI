<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Http\Middleware;

use Firebase\JWT\CachedKeySet;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use LiturgicalCalendar\Api\Http\CookieHelper;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * OIDC Authentication Middleware for Zitadel tokens.
 *
 * Validates OIDC tokens from Zitadel using JWKS endpoint.
 * Supports both cookie-based and header-based authentication.
 *
 * Request attributes set on successful authentication:
 * - 'oidc_user': Array with user info (sub, email, name, roles)
 * - 'oidc_token': Raw JWT payload for additional claims
 */
class OidcAuthMiddleware implements MiddlewareInterface
{
    private string $issuer;
    private string $clientId;
    private int $cacheTtl;
    private LoggerInterface $logger;

    /**
     * Cached JWKS key sets, keyed by issuer URL.
     *
     * @var array<string, CachedKeySet>
     */
    private static array $keySets = [];

    /**
     * Create the OIDC authentication middleware.
     *
     * @param string $issuer Zitadel issuer URL (e.g., http://localhost:8081)
     * @param string $clientId Zitadel client ID for audience validation
     * @param int $cacheTtl JWKS cache TTL in seconds (default: 3600)
     * @param LoggerInterface|null $logger PSR-3 logger instance (optional)
     */
    public function __construct(
        string $issuer,
        string $clientId,
        int $cacheTtl = 3600,
        ?LoggerInterface $logger = null
    ) {
        $this->issuer   = rtrim($issuer, '/');
        $this->clientId = $clientId;
        $this->cacheTtl = $cacheTtl;
        $this->logger   = $logger ?? LoggerFactory::create('auth', null, 30, false, true, false);
    }

    /**
     * Check if OIDC authentication is configured.
     *
     * @return bool True if required environment variables are set
     */
    public static function isConfigured(): bool
    {
        // Check both getenv() and $_ENV since Dotenv may not always populate putenv()
        $issuer   = getenv('ZITADEL_ISSUER') ?: ( $_ENV['ZITADEL_ISSUER'] ?? '' );
        $clientId = getenv('ZITADEL_CLIENT_ID') ?: ( $_ENV['ZITADEL_CLIENT_ID'] ?? '' );

        return !empty($issuer) && !empty($clientId);
    }

    /**
     * Create middleware from environment variables.
     *
     * Required environment variables:
     * - ZITADEL_ISSUER: Zitadel issuer URL
     * - ZITADEL_CLIENT_ID: Client ID for audience validation
     *
     * @return self
     * @throws \RuntimeException If required environment variables are missing
     */
    public static function fromEnv(): self
    {
        // Check both getenv() and $_ENV since Dotenv may not always populate putenv()
        $issuerEnv   = getenv('ZITADEL_ISSUER') ?: ( $_ENV['ZITADEL_ISSUER'] ?? '' );
        $clientIdEnv = getenv('ZITADEL_CLIENT_ID') ?: ( $_ENV['ZITADEL_CLIENT_ID'] ?? '' );

        $issuer   = is_string($issuerEnv) ? $issuerEnv : '';
        $clientId = is_string($clientIdEnv) ? $clientIdEnv : '';

        if (empty($issuer) || empty($clientId)) {
            throw new \RuntimeException(
                'Missing required environment variables: ZITADEL_ISSUER, ZITADEL_CLIENT_ID'
            );
        }

        return new self($issuer, $clientId);
    }

    /**
     * Process the request and validate OIDC token.
     *
     * @param ServerRequestInterface $request Incoming request
     * @param RequestHandlerInterface $handler Next handler
     * @return ResponseInterface Response from next handler
     * @throws UnauthorizedException If token is missing or invalid
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Skip authentication for OPTIONS preflight requests (CORS)
        if ($request->getMethod() === 'OPTIONS') {
            return $handler->handle($request);
        }

        $token = $this->extractToken($request);

        if ($token === null) {
            throw new UnauthorizedException('Missing authentication token');
        }

        try {
            $payload = $this->validateToken($token);
        } catch (\Exception $e) {
            // Log full details for debugging, return generic message to client
            $this->logger->warning('OIDC token validation failed', [
                'error'  => $e->getMessage(),
                'issuer' => $this->issuer,
            ]);
            throw new UnauthorizedException('Invalid or expired token');
        }

        // Validate issuer
        if (!isset($payload->iss) || $payload->iss !== $this->issuer) {
            throw new UnauthorizedException('Invalid token issuer');
        }

        // Validate audience (can be string or array)
        $aud           = $payload->aud ?? null;
        $validAudience = false;
        if (is_string($aud) && $aud === $this->clientId) {
            $validAudience = true;
        } elseif (is_array($aud) && in_array($this->clientId, $aud, true)) {
            $validAudience = true;
        }

        if (!$validAudience) {
            throw new UnauthorizedException('Invalid token audience');
        }

        // Extract user info and roles from token
        $oidcUser = $this->extractUserInfo($payload);

        // Attach to request attributes
        $request = $request->withAttribute('oidc_user', $oidcUser);
        $request = $request->withAttribute('oidc_token', $payload);

        return $handler->handle($request);
    }

    /**
     * Extract token from request (cookie first, then header).
     *
     * @param ServerRequestInterface $request The request
     * @return string|null Token string or null if not found
     */
    private function extractToken(ServerRequestInterface $request): ?string
    {
        // 1. Try HttpOnly cookie first (preferred for security)
        /** @var array<string, string> $cookies */
        $cookies = $request->getCookieParams();
        $token   = CookieHelper::getAccessToken($cookies);

        if ($token !== null) {
            return $token;
        }

        // 2. Fall back to Authorization header
        $authHeader = $request->getHeaderLine('Authorization');

        if (!empty($authHeader)) {
            if (!str_starts_with(strtolower($authHeader), 'bearer ')) {
                return null;
            }
            return trim(substr($authHeader, 7));
        }

        return null;
    }

    /**
     * Validate token against Zitadel JWKS.
     *
     * @param string $token JWT token string
     * @return object Decoded token payload
     * @throws \Exception If token validation fails
     */
    private function validateToken(string $token): object
    {
        $keySet = $this->getKeySet();

        return JWT::decode($token, $keySet);
    }

    /**
     * Get or create cached JWKS key set.
     *
     * Key sets are cached per issuer to support multiple OIDC providers.
     *
     * @return CachedKeySet JWKS key set
     */
    private function getKeySet(): CachedKeySet
    {
        if (isset(self::$keySets[$this->issuer])) {
            return self::$keySets[$this->issuer];
        }

        $jwksUri = $this->issuer . '/oauth/v2/keys';

        $httpClient  = new Client();
        $httpFactory = new HttpFactory();

        // Use filesystem cache for JWKS (PSR-6 compatible)
        $cacheDir = dirname(__DIR__, 3) . '/cache';
        $cache    = new FilesystemAdapter('jwks', $this->cacheTtl, $cacheDir);

        // Create cached key set
        self::$keySets[$this->issuer] = new CachedKeySet(
            $jwksUri,
            $httpClient,
            $httpFactory,
            $cache,
            $this->cacheTtl,
            true // Rate limit JWKS fetches
        );

        return self::$keySets[$this->issuer];
    }

    /**
     * Extract user information from token payload.
     *
     * @param object $payload Token payload
     * @return array{sub: string|null, email: string|null, email_verified: bool, name: string|null, given_name: string|null, family_name: string|null, preferred_username: string|null, roles: array<string>, project_id?: string} User info array
     */
    private function extractUserInfo(object $payload): array
    {
        // Standard OIDC claims - extract and validate types
        $sub               = $payload->sub ?? null;
        $email             = $payload->email ?? null;
        $emailVerified     = $payload->email_verified ?? false;
        $name              = $payload->name ?? null;
        $givenName         = $payload->given_name ?? null;
        $familyName        = $payload->family_name ?? null;
        $preferredUsername = $payload->preferred_username ?? null;

        $user = [
            'sub'                => is_string($sub) ? $sub : null,
            'email'              => is_string($email) ? $email : null,
            'email_verified'     => is_bool($emailVerified) ? $emailVerified : false,
            'name'               => is_string($name) ? $name : null,
            'given_name'         => is_string($givenName) ? $givenName : null,
            'family_name'        => is_string($familyName) ? $familyName : null,
            'preferred_username' => is_string($preferredUsername) ? $preferredUsername : null,
        ];

        // Zitadel-specific: Extract project roles
        // Zitadel uses the claim: urn:zitadel:iam:org:project:roles
        $rolesClaimKey = 'urn:zitadel:iam:org:project:roles';
        /** @var array<string> $roles */
        $roles = [];

        if (isset($payload->{$rolesClaimKey})) {
            // Zitadel returns roles as object with role name as key
            // e.g., {"admin": {"org_id": "123"}, "developer": {"org_id": "123"}}
            $rolesData = (array) $payload->{$rolesClaimKey};
            foreach (array_keys($rolesData) as $role) {
                if (is_string($role)) {
                    $roles[] = $role;
                }
            }
        }

        $user['roles'] = $roles;

        // Also check for Zitadel project ID claim
        $projectIdKey = 'urn:zitadel:iam:org:project:id';
        if (isset($payload->{$projectIdKey})) {
            $projectId = $payload->{$projectIdKey};
            if (is_string($projectId)) {
                $user['project_id'] = $projectId;
            }
        }

        return $user;
    }

    /**
     * Check if a user has a specific role.
     *
     * @param array{roles?: array<string>} $oidcUser User array from request attribute
     * @param string $role Role to check
     * @return bool True if user has the role
     */
    public static function hasRole(array $oidcUser, string $role): bool
    {
        /** @var array<string> $roles */
        $roles = $oidcUser['roles'] ?? [];
        return in_array($role, $roles, true);
    }

    /**
     * Check if a user has any of the specified roles.
     *
     * @param array{roles?: array<string>} $oidcUser User array from request attribute
     * @param array<string> $roles Roles to check
     * @return bool True if user has any of the roles
     */
    public static function hasAnyRole(array $oidcUser, array $roles): bool
    {
        /** @var array<string> $userRoles */
        $userRoles = $oidcUser['roles'] ?? [];
        return !empty(array_intersect($roles, $userRoles));
    }

    /**
     * Check if a user is an admin.
     *
     * @param array{roles?: array<string>} $oidcUser User array from request attribute
     * @return bool True if user has admin role
     */
    public static function isAdmin(array $oidcUser): bool
    {
        return self::hasRole($oidcUser, 'admin');
    }

    /**
     * Check if a user has the developer role.
     *
     * @param array{roles?: array<string>} $oidcUser User array from request attribute
     * @return bool True if user has developer role
     */
    public static function hasDeveloperRole(array $oidcUser): bool
    {
        return self::hasRole($oidcUser, 'developer');
    }

    /**
     * Reset all cached key sets (useful for testing).
     */
    public static function resetKeySetCache(): void
    {
        self::$keySets = [];
    }
}
