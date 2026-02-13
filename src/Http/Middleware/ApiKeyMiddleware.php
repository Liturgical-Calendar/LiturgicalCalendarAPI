<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Http\Middleware;

use LiturgicalCalendar\Api\Repositories\ApiKeyRepository;
use LiturgicalCalendar\Api\Repositories\AuditLogRepository;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * API Key Middleware for optional API key authentication.
 *
 * This middleware:
 * 1. Checks for API key in X-Api-Key header or api_key query parameter
 * 2. Validates the key if present
 * 3. Attaches API key info to request attributes
 * 4. Logs usage for analytics
 *
 * Does NOT require API key - just enhances requests that have one.
 * For endpoints that require authentication, use OidcAuthMiddleware instead.
 */
class ApiKeyMiddleware implements MiddlewareInterface
{
    private ApiKeyRepository $apiKeyRepo;
    private ?AuditLogRepository $auditRepo;
    private ?LoggerInterface $logger;

    /**
     * Create API key middleware.
     *
     * @param ApiKeyRepository $apiKeyRepo API key repository
     * @param AuditLogRepository|null $auditRepo Optional audit log repository
     * @param LoggerInterface|null $logger Optional logger for deprecation warnings
     */
    public function __construct(
        ApiKeyRepository $apiKeyRepo,
        ?AuditLogRepository $auditRepo = null,
        ?LoggerInterface $logger = null
    ) {
        $this->apiKeyRepo = $apiKeyRepo;
        $this->auditRepo  = $auditRepo;
        $this->logger     = $logger;
    }

    /**
     * Process the request and validate API key if present.
     *
     * @param ServerRequestInterface $request Incoming request
     * @param RequestHandlerInterface $handler Next handler
     * @return ResponseInterface Response from next handler
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $apiKey = $this->extractApiKey($request);

        if ($apiKey === null) {
            // No API key provided - continue without it
            return $handler->handle($request);
        }

        $keyInfo = $this->apiKeyRepo->validate($apiKey);

        if ($keyInfo === null) {
            // Invalid key - log for abuse detection and continue without authentication
            $this->logger?->info('Invalid API key provided', [
                'path'       => $request->getUri()->getPath(),
                'method'     => $request->getMethod(),
                'ip_address' => $this->getClientIp($request),
            ]);
            return $handler->handle($request);
        }

        // Attach API key info to request
        $request = $request->withAttribute('api_key', [
            'id'                  => $keyInfo['id'],
            'application_id'      => $keyInfo['application_id'],
            'app_uuid'            => $keyInfo['app_uuid'],
            'app_name'            => $keyInfo['app_name'],
            'owner_id'            => $keyInfo['zitadel_user_id'],
            'scope'               => $keyInfo['scope'],
            'rate_limit_per_hour' => $keyInfo['rate_limit_per_hour'],
        ]);

        // Log API key usage (validate required fields exist)
        if (
            $this->auditRepo !== null
            && isset($keyInfo['id'], $keyInfo['application_id'], $keyInfo['app_uuid'], $keyInfo['app_name'], $keyInfo['zitadel_user_id'], $keyInfo['scope'], $keyInfo['rate_limit_per_hour'])
            && is_int($keyInfo['id'])
            && is_int($keyInfo['application_id'])
            && is_string($keyInfo['app_uuid'])
            && is_string($keyInfo['app_name'])
            && is_string($keyInfo['zitadel_user_id'])
            && is_string($keyInfo['scope'])
            && is_int($keyInfo['rate_limit_per_hour'])
        ) {
            $this->logUsage($request, [
                'id'                  => $keyInfo['id'],
                'application_id'      => $keyInfo['application_id'],
                'app_uuid'            => $keyInfo['app_uuid'],
                'app_name'            => $keyInfo['app_name'],
                'zitadel_user_id'     => $keyInfo['zitadel_user_id'],
                'scope'               => $keyInfo['scope'],
                'rate_limit_per_hour' => $keyInfo['rate_limit_per_hour'],
            ]);
        }

        return $handler->handle($request);
    }

    /**
     * Extract API key from request.
     *
     * Checks X-Api-Key header first, then api_key query parameter.
     *
     * @param ServerRequestInterface $request The request
     * @return string|null API key or null if not found
     */
    private function extractApiKey(ServerRequestInterface $request): ?string
    {
        // 1. Check X-Api-Key header (preferred)
        $headerKey = $request->getHeaderLine('X-Api-Key');
        if (!empty($headerKey)) {
            return $headerKey;
        }

        // 2. Check query parameter (fallback - deprecated)
        $queryParams = $request->getQueryParams();
        if (isset($queryParams['api_key']) && is_string($queryParams['api_key']) && !empty($queryParams['api_key'])) {
            // Log deprecation warning - query parameter API keys are less secure
            // as they may appear in server logs, browser history, and referrer headers
            if ($this->logger !== null) {
                $this->logger->warning(
                    'API key provided via query parameter (deprecated). ' .
                    'Use X-Api-Key header instead for improved security.',
                    [
                        'path'       => $request->getUri()->getPath(),
                        'method'     => $request->getMethod(),
                        'ip_address' => $this->getClientIp($request),
                    ]
                );
            }
            return $queryParams['api_key'];
        }

        return null;
    }

    /**
     * Log API key usage for analytics.
     *
     * @param ServerRequestInterface $request The request
     * @param array{id: int, application_id: int, app_uuid: string, app_name: string, zitadel_user_id: string, scope: string, rate_limit_per_hour: int} $keyInfo API key info
     */
    private function logUsage(ServerRequestInterface $request, array $keyInfo): void
    {
        if ($this->auditRepo === null) {
            return;
        }

        $ipAddress = $this->getClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');

        $this->auditRepo->log(
            $keyInfo['zitadel_user_id'],
            'api_request',
            'api_key',
            (string) $keyInfo['id'],
            [
                'method'   => $request->getMethod(),
                'path'     => $request->getUri()->getPath(),
                'app_name' => $keyInfo['app_name'],
            ],
            $ipAddress,
            $userAgent !== '' ? $userAgent : null,
            true
        );
    }

    /**
     * Get client IP address from request.
     *
     * @param ServerRequestInterface $request The request
     * @return string|null Client IP or null
     */
    private function getClientIp(ServerRequestInterface $request): ?string
    {
        // Check for proxy headers first
        $forwardedFor = $request->getHeaderLine('X-Forwarded-For');
        if (!empty($forwardedFor)) {
            // Take first IP in comma-separated list
            $ips = array_map('trim', explode(',', $forwardedFor));
            return $ips[0] ?? null;
        }

        $realIp = $request->getHeaderLine('X-Real-IP');
        if (!empty($realIp)) {
            return $realIp;
        }

        // Fall back to server params
        $serverParams = $request->getServerParams();
        $remoteAddr   = $serverParams['REMOTE_ADDR'] ?? null;
        return is_string($remoteAddr) ? $remoteAddr : null;
    }

    /**
     * Check if request has a valid API key.
     *
     * @param ServerRequestInterface $request The request
     * @return bool True if request has valid API key
     */
    public static function hasValidKey(ServerRequestInterface $request): bool
    {
        return $request->getAttribute('api_key') !== null;
    }

    /**
     * Get the rate limit for the current request.
     *
     * Returns the API key's rate limit if authenticated, or default.
     *
     * @param ServerRequestInterface $request The request
     * @param int $default Default rate limit for unauthenticated requests
     * @return int Rate limit per hour
     */
    public static function getRateLimit(ServerRequestInterface $request, int $default = 100): int
    {
        /** @var array{rate_limit_per_hour?: int}|null $apiKey */
        $apiKey = $request->getAttribute('api_key');

        if (is_array($apiKey) && isset($apiKey['rate_limit_per_hour'])) {
            return $apiKey['rate_limit_per_hour'];
        }

        return $default;
    }

    /**
     * Get the API key scope for the current request.
     *
     * @param ServerRequestInterface $request The request
     * @return string|null Scope ('read' or 'write') or null if no API key
     */
    public static function getScope(ServerRequestInterface $request): ?string
    {
        /** @var array{scope?: string}|null $apiKey */
        $apiKey = $request->getAttribute('api_key');

        if (is_array($apiKey) && isset($apiKey['scope'])) {
            return $apiKey['scope'];
        }

        return null;
    }
}
