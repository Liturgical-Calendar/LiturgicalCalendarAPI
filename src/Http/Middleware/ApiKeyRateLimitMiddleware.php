<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Http\Middleware;

use LiturgicalCalendar\Api\Http\Exception\TooManyRequestsException;
use LiturgicalCalendar\Api\Services\RateLimiter;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rate limiting middleware for API key-authenticated and unauthenticated requests.
 *
 * Tracks request counts per API key (or per IP for unauthenticated requests)
 * within a one-hour sliding window. Throws TooManyRequestsException when the
 * limit is exceeded, which ErrorHandlingMiddleware converts to a 429 response
 * with a Retry-After header.
 *
 * Must be piped after ApiKeyMiddleware so the api_key request attribute is available.
 */
class ApiKeyRateLimitMiddleware implements MiddlewareInterface
{
    private RateLimiter $rateLimiter;
    private int $defaultLimit;
    private bool $trustProxyHeaders;

    private const WINDOW_SECONDS = 3600; // 1 hour

    /**
     * Create the rate limit middleware.
     *
     * @param int $defaultLimit Default requests per hour for unauthenticated requests
     * @param string|null $storagePath Path to store rate limit data (default: system temp dir)
     * @param bool $trustProxyHeaders Whether to trust X-Forwarded-For/X-Real-IP headers for client IP
     */
    public function __construct(int $defaultLimit = 10, ?string $storagePath = null, bool $trustProxyHeaders = false)
    {
        $this->defaultLimit      = $defaultLimit;
        $this->trustProxyHeaders = $trustProxyHeaders;
        // maxAttempts is set high because we compare against per-key limits ourselves.
        // The RateLimiter only handles window-based counting and file storage.
        $this->rateLimiter = new RateLimiter(PHP_INT_MAX, self::WINDOW_SECONDS, $storagePath);
    }

    /**
     * Process the request and enforce rate limits.
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     * @throws TooManyRequestsException If the rate limit is exceeded
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $apiKeyData = $request->getAttribute('api_key');
        if (is_array($apiKeyData) && isset($apiKeyData['id']) && is_string($apiKeyData['id'])) {
            $identifier = 'apikey_' . $apiKeyData['id'];
            $rateLimit  = $apiKeyData['rate_limit_per_hour'] ?? $this->defaultLimit;
            $limit      = is_int($rateLimit) ? $rateLimit : ( is_numeric($rateLimit) ? intval($rateLimit) : $this->defaultLimit );
        } else {
            // Unauthenticated: rate limit by IP address
            $clientIp = $this->getClientIp($request);

            // Exempt loopback self-calls. The API issues internal HTTP requests
            // to its own endpoints (e.g. RegionalDataHandler and EventsParams
            // fetching /calendars); these originate from 127.0.0.0/8 or ::1 and
            // must not be charged against the public unauthenticated quota —
            // otherwise the API throttles itself once UNAUTHENTICATED_RATE_LIMIT
            // internal calls occur within the window. Deployments that terminate
            // client traffic on the same host must set TRUST_PROXY_HEADERS=true
            // so real clients resolve to their forwarded address, not loopback.
            if (self::isLoopbackAddress($clientIp)) {
                return $handler->handle($request);
            }

            $identifier = 'ip_' . $clientIp;
            $limit      = $this->defaultLimit;
        }

        // Atomically record the request (capped) and get the count.
        // The cap prevents unbounded file growth from requests that exceed the limit.
        $count     = $this->rateLimiter->recordRequestAndGetCount($identifier, $limit);
        $remaining = $limit - $count;

        if ($remaining < 0) {
            $retryAfter = $this->rateLimiter->getRetryAfter($identifier);
            throw new TooManyRequestsException(
                "Rate limit exceeded. Maximum {$limit} requests per hour.",
                $retryAfter
            );
        }

        // Add rate limit headers to response
        $response = $handler->handle($request);
        return $response
            ->withHeader('X-RateLimit-Limit', (string) $limit)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $remaining))
            ->withHeader('X-RateLimit-Reset', (string) $this->rateLimiter->getWindowResetTime($identifier));
    }

    /**
     * Create middleware from environment variables.
     *
     * @return self
     */
    public static function fromEnv(): self
    {
        $defaultLimitEnv = getenv('UNAUTHENTICATED_RATE_LIMIT') ?: ( $_ENV['UNAUTHENTICATED_RATE_LIMIT'] ?? '' );
        $defaultLimit    = is_numeric($defaultLimitEnv) ? (int) $defaultLimitEnv : 10;
        $storagePath     = getenv('RATE_LIMIT_STORAGE_PATH') ?: ( $_ENV['RATE_LIMIT_STORAGE_PATH'] ?? null );
        $storagePath     = is_string($storagePath) && !empty($storagePath) ? $storagePath : null;
        $trustProxy      = getenv('TRUST_PROXY_HEADERS') ?: ( $_ENV['TRUST_PROXY_HEADERS'] ?? 'false' );
        $trustProxy      = filter_var($trustProxy, FILTER_VALIDATE_BOOLEAN);

        return new self($defaultLimit, $storagePath, $trustProxy);
    }

    /**
     * Extract the client IP address from the request.
     *
     * Only trusts proxy headers (X-Forwarded-For, X-Real-IP) when trustProxyHeaders
     * is enabled. Validates extracted IPs with filter_var to prevent malformed values.
     *
     * @param ServerRequestInterface $request
     * @return string Client IP address
     */
    private function getClientIp(ServerRequestInterface $request): string
    {
        // Honour proxy headers when explicitly trusted, or when running in a
        // local dev/test environment so the test suite can isolate per-class
        // rate-limit budgets without saturating the host's natural IP.
        // Production (APP_ENV=staging|production) is unaffected.
        // Read APP_ENV via getenv() with $_ENV fallback to behave consistently
        // across CLI, FPM, and container environments (matches the pattern in
        // fromEnv() above).
        $appEnv     = getenv('APP_ENV') ?: ( $_ENV['APP_ENV'] ?? '' );
        $devOrTest  = is_string($appEnv) && in_array($appEnv, ['development', 'test'], true);
        $trustProxy = $this->trustProxyHeaders || $devOrTest;

        if ($trustProxy) {
            $headers = ['X-Forwarded-For', 'X-Real-IP'];
            foreach ($headers as $header) {
                $value = $request->getHeaderLine($header);
                if (!empty($value)) {
                    $ips       = array_map('trim', explode(',', $value));
                    $candidate = $ips[0];
                    if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                        return $candidate;
                    }
                }
            }
        }

        $serverParams = $request->getServerParams();
        $remoteAddr   = $serverParams['REMOTE_ADDR'] ?? '127.0.0.1';
        return is_string($remoteAddr) ? $remoteAddr : '127.0.0.1';
    }

    /**
     * Determine whether an IP address is a loopback (self-call) address.
     *
     * Covers the IPv4 loopback range 127.0.0.0/8, the IPv6 loopback ::1, and
     * the IPv4-mapped IPv6 form (::ffff:127.0.0.0/8).
     *
     * @param string $ip
     * @return bool
     */
    private static function isLoopbackAddress(string $ip): bool
    {
        if ($ip === '::1') {
            return true;
        }

        // IPv4-mapped IPv6 loopback, e.g. ::ffff:127.0.0.1
        if (stripos($ip, '::ffff:') === 0) {
            $ip = substr($ip, 7);
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && str_starts_with($ip, '127.');
    }
}
