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

    private const WINDOW_SECONDS = 3600; // 1 hour

    /**
     * Create the rate limit middleware.
     *
     * @param int $defaultLimit Default requests per hour for unauthenticated requests
     * @param string|null $storagePath Path to store rate limit data (default: system temp dir)
     */
    public function __construct(int $defaultLimit = 100, ?string $storagePath = null)
    {
        $this->defaultLimit = $defaultLimit;
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
            $identifier = 'ip_' . $this->getClientIp($request);
            $limit      = $this->defaultLimit;
        }

        // Check current count within the window
        $currentCount = $this->rateLimiter->getAttemptCount($identifier);
        $remaining    = $limit - $currentCount;
        if ($remaining <= 0) {
            $retryAfter = $this->rateLimiter->getRetryAfter($identifier);
            throw new TooManyRequestsException(
                "Rate limit exceeded. Maximum {$limit} requests per hour.",
                $retryAfter
            );
        }

        // Record this request
        $this->rateLimiter->recordRequest($identifier);

        // Add rate limit headers to response
        $response = $handler->handle($request);
        return $response
            ->withHeader('X-RateLimit-Limit', (string) $limit)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $remaining - 1))
            ->withHeader('X-RateLimit-Reset', (string) ( time() + self::WINDOW_SECONDS ));
    }

    /**
     * Create middleware from environment variables.
     *
     * @return self
     */
    public static function fromEnv(): self
    {
        $defaultLimitEnv = getenv('API_KEY_DEFAULT_RATE_LIMIT') ?: ( $_ENV['API_KEY_DEFAULT_RATE_LIMIT'] ?? '' );
        $defaultLimit    = is_numeric($defaultLimitEnv) ? (int) $defaultLimitEnv : 100;
        $storagePath     = getenv('RATE_LIMIT_STORAGE_PATH') ?: ( $_ENV['RATE_LIMIT_STORAGE_PATH'] ?? null );
        $storagePath     = is_string($storagePath) && !empty($storagePath) ? $storagePath : null;

        return new self($defaultLimit, $storagePath);
    }

    /**
     * Extract the client IP address from the request.
     *
     * @param ServerRequestInterface $request
     * @return string Client IP address
     */
    private function getClientIp(ServerRequestInterface $request): string
    {
        // Check common proxy headers
        $headers = ['X-Forwarded-For', 'X-Real-IP'];
        foreach ($headers as $header) {
            $value = $request->getHeaderLine($header);
            if (!empty($value)) {
                // X-Forwarded-For may contain multiple IPs; take the first (client IP)
                $ips = array_map('trim', explode(',', $value));
                return $ips[0];
            }
        }

        $serverParams = $request->getServerParams();
        $remoteAddr   = $serverParams['REMOTE_ADDR'] ?? '127.0.0.1';
        return is_string($remoteAddr) ? $remoteAddr : '127.0.0.1';
    }
}
