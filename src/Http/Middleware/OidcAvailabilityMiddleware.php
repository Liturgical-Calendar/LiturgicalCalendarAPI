<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Http\Middleware;

use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware to check OIDC availability before processing requests.
 *
 * This middleware ensures that OIDC authentication is properly configured
 * before allowing requests to proceed. By checking within the middleware
 * pipeline, errors are properly handled by ErrorHandlingMiddleware and
 * CORS headers are applied correctly.
 */
class OidcAvailabilityMiddleware implements MiddlewareInterface
{
    private bool $isOidcConfigured;
    private string $errorMessage;

    /**
     * Create the OIDC availability middleware.
     *
     * @param bool $isOidcConfigured Whether OIDC is configured
     * @param string $errorMessage Custom error message for when OIDC is not configured
     */
    public function __construct(
        bool $isOidcConfigured,
        string $errorMessage = 'OIDC authentication is not configured. These features require Zitadel integration.'
    ) {
        $this->isOidcConfigured = $isOidcConfigured;
        $this->errorMessage     = $errorMessage;
    }

    /**
     * Process the request.
     *
     * @param ServerRequestInterface $request The incoming request
     * @param RequestHandlerInterface $handler The next handler in the pipeline
     * @return ResponseInterface The response
     * @throws ServiceUnavailableException If OIDC is not configured
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->isOidcConfigured) {
            throw new ServiceUnavailableException($this->errorMessage);
        }

        return $handler->handle($request);
    }
}
