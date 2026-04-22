<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Http\Middleware;

use Firebase\JWT\CachedKeySet;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Message\RequestInterface;
use LiturgicalCalendar\Api\Http\CookieHelper;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Models\Auth\User;
use LiturgicalCalendar\Api\Services\JwtServiceFactory;
use LiturgicalCalendar\Api\Services\ZitadelHostHeader;
use LiturgicalCalendar\Api\Services\ZitadelService;
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
    private ?string $projectId;
    private ?string $internalUrl;
    private int $cacheTtl;
    private bool $jwtFallback;
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
     * @param string $issuer Zitadel issuer URL (e.g., http://localhost:8080)
     * @param string $clientId Zitadel client ID for audience validation
     * @param string|null $projectId Zitadel project ID (also valid as audience for machine-to-machine tokens)
     * @param string|null $internalUrl Internal URL for server-side requests (e.g., http://zitadel:8080)
     * @param int $cacheTtl JWKS cache TTL in seconds (default: 3600)
     * @param LoggerInterface|null $logger PSR-3 logger instance (optional)
     * @param bool $jwtFallback Whether to fall back to legacy JWT validation when OIDC validation fails
     */
    public function __construct(
        string $issuer,
        string $clientId,
        ?string $projectId = null,
        ?string $internalUrl = null,
        int $cacheTtl = 3600,
        ?LoggerInterface $logger = null,
        bool $jwtFallback = false
    ) {
        $this->issuer      = rtrim($issuer, '/');
        $this->clientId    = $clientId;
        $this->projectId   = $projectId;
        $this->internalUrl = $internalUrl !== null ? rtrim($internalUrl, '/') : null;
        $this->cacheTtl    = $cacheTtl;
        $this->jwtFallback = $jwtFallback;
        $this->logger      = $logger ?? LoggerFactory::create('auth', null, 30, false, true, false);
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
     * @param bool $jwtFallback Whether to fall back to legacy JWT validation when OIDC validation fails
     * @return self
     * @throws \RuntimeException If required environment variables are missing
     */
    public static function fromEnv(bool $jwtFallback = false): self
    {
        // Check both getenv() and $_ENV since Dotenv may not always populate putenv()
        $issuerEnv      = getenv('ZITADEL_ISSUER') ?: ( $_ENV['ZITADEL_ISSUER'] ?? '' );
        $clientIdEnv    = getenv('ZITADEL_CLIENT_ID') ?: ( $_ENV['ZITADEL_CLIENT_ID'] ?? '' );
        $projectIdEnv   = getenv('ZITADEL_PROJECT_ID') ?: ( $_ENV['ZITADEL_PROJECT_ID'] ?? '' );
        $internalUrlEnv = getenv('ZITADEL_INTERNAL_URL') ?: ( $_ENV['ZITADEL_INTERNAL_URL'] ?? '' );

        $issuer      = is_string($issuerEnv) ? $issuerEnv : '';
        $clientId    = is_string($clientIdEnv) ? $clientIdEnv : '';
        $projectId   = is_string($projectIdEnv) && !empty($projectIdEnv) ? $projectIdEnv : null;
        $internalUrl = is_string($internalUrlEnv) && !empty($internalUrlEnv) ? $internalUrlEnv : null;

        if (empty($issuer) || empty($clientId)) {
            throw new \RuntimeException(
                'Missing required environment variables: ZITADEL_ISSUER, ZITADEL_CLIENT_ID'
            );
        }

        return new self($issuer, $clientId, $projectId, $internalUrl, 3600, null, $jwtFallback);
    }

    /**
     * Process the request and validate OIDC token.
     *
     * Attempts OIDC (Zitadel) token validation first. If that fails and JWT fallback
     * is enabled, attempts legacy JWT validation as a secondary mechanism.
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

        // Try OIDC validation first
        $oidcResult = $this->tryOidcValidation($token);

        if ($oidcResult !== null) {
            // OIDC validation succeeded
            $request = $request->withAttribute('oidc_user', $oidcResult['user']);
            $request = $request->withAttribute('oidc_token', $oidcResult['payload']);
            return $handler->handle($request);
        }

        // OIDC validation failed — try JWT fallback if enabled
        if ($this->jwtFallback) {
            $jwtResult = $this->tryJwtFallback($token);
            if ($jwtResult !== null) {
                $request = $request->withAttribute('user', $jwtResult['user']);
                $request = $request->withAttribute('jwt_payload', $jwtResult['payload']);
                $request = $request->withAttribute('oidc_user', [
                    'sub'                => $jwtResult['user']->username,
                    'email'              => null,
                    'email_verified'     => false,
                    'name'               => null,
                    'given_name'         => null,
                    'family_name'        => null,
                    'preferred_username' => $jwtResult['user']->username,
                    'roles'              => $jwtResult['user']->roles,
                ]);
                return $handler->handle($request);
            }
        }

        throw new UnauthorizedException('Invalid or expired token');
    }

    /**
     * Attempt OIDC token validation.
     *
     * @param string $token JWT token string
     * @return array{user: array{sub: string|null, email: string|null, email_verified: bool, name: string|null, given_name: string|null, family_name: string|null, preferred_username: string|null, roles: array<string>, project_id?: string}, payload: object}|null Validated result or null on failure
     */
    private function tryOidcValidation(string $token): ?array
    {
        try {
            $payload = $this->validateToken($token);
        } catch (\Exception $e) {
            $this->logger->debug('OIDC token validation failed, will try fallback', [
                'error'  => $e->getMessage(),
                'issuer' => $this->issuer,
            ]);
            return null;
        }

        // Validate issuer
        if (!isset($payload->iss) || $payload->iss !== $this->issuer) {
            $this->logger->warning('OIDC issuer mismatch', [
                'expected' => $this->issuer,
                'actual'   => $payload->iss ?? '(missing)',
            ]);
            return null;
        }

        // Validate audience (can be string or array)
        // Accept either the OIDC client ID or the project ID as valid audience.
        // Zitadel puts the project ID in the audience for machine-to-machine tokens,
        // and the client ID for user-facing OIDC tokens.
        $aud            = $payload->aud ?? null;
        $validAudiences = array_filter([$this->clientId, $this->projectId]);
        $validAudience  = false;
        if (is_string($aud) && in_array($aud, $validAudiences, true)) {
            $validAudience = true;
        } elseif (is_array($aud) && !empty(array_intersect(array_filter($aud, 'is_string'), $validAudiences))) {
            $validAudience = true;
        }

        if (!$validAudience) {
            $this->logger->warning('OIDC audience mismatch', [
                'expected' => $validAudiences,
                'actual'   => $aud,
            ]);
            return null;
        }

        $oidcUser = $this->extractUserInfo($payload);
        return ['user' => $oidcUser, 'payload' => $payload];
    }

    /**
     * Attempt legacy JWT token validation as a fallback.
     *
     * @param string $token JWT token string
     * @return array{user: User, payload: object}|null Validated result or null on failure
     */
    private function tryJwtFallback(string $token): ?array
    {
        try {
            $jwtService = JwtServiceFactory::fromEnv();
            $payload    = $jwtService->verify($token);

            if ($payload === null) {
                return null;
            }

            $user = User::fromJwtPayload($payload);
            if ($user === null) {
                return null;
            }

            $this->logger->info('Authenticated via legacy JWT fallback', [
                'username' => $user->username,
            ]);

            return ['user' => $user, 'payload' => $payload];
        } catch (\Exception $e) {
            $this->logger->debug('JWT fallback validation also failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
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

        if ($this->internalUrl !== null) {
            // Rewrite JWKS URI to use internal URL for Docker networking
            $jwksUri = $this->internalUrl . '/oauth/v2/keys';

            // CachedKeySet uses PSR-18 sendRequest() which doesn't apply Guzzle's
            // default headers. Use middleware to inject the Host header so Zitadel
            // accepts requests sent to the Docker service name.
            $hostHeader = ZitadelHostHeader::deriveFromIssuer($this->issuer);
            $stack      = HandlerStack::create();
            $stack->push(Middleware::mapRequest(function (RequestInterface $request) use ($hostHeader) {
                return $request->withHeader('Host', $hostHeader);
            }));
            $httpClient = new Client(['handler' => $stack]);
        } else {
            $httpClient = new Client();
        }
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

        // If roles are not present in the token (e.g., JWT Profile grant for service accounts),
        // look them up via the Zitadel Management API with a short-lived cache
        if (empty($roles) && is_string($sub) && ZitadelService::isConfigured()) {
            $sanitize = static fn(string $v): string => str_replace(
                ['{', '}', '(', ')', '/', '\\', '@', ':'],
                '_',
                $v
            );
            $cacheDir = dirname(__DIR__, 3) . '/cache';
            $cache    = new FilesystemAdapter('roles', 300, $cacheDir);
            $cacheKey = $sanitize($this->issuer) . '_' . $sanitize($this->projectId ?? '') . '_' . $sanitize($sub);

            try {
                $cachedItem = $cache->getItem($cacheKey);
                if ($cachedItem->isHit()) {
                    $cached = $cachedItem->get();
                    $roles  = is_array($cached) ? array_filter($cached, 'is_string') : [];
                } else {
                    $zitadelService = ZitadelService::fromEnv();
                    $roles          = $zitadelService->getUserRoles($sub);
                    $cachedItem->set($roles);
                    $cache->save($cachedItem);
                }
            } catch (\Exception $e) {
                $this->logger->debug('Role cache/lookup failed, falling back to API', [
                    'error'  => $e->getMessage(),
                    'userId' => $sub,
                ]);
                try {
                    $zitadelService = ZitadelService::fromEnv();
                    $roles          = $zitadelService->getUserRoles($sub);
                } catch (\Exception $fallbackEx) {
                    $this->logger->debug('Fallback role lookup also failed', [
                        'error'  => $fallbackEx->getMessage(),
                        'userId' => $sub,
                    ]);
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
