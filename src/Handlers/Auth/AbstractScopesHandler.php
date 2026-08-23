<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Auth;

use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Handlers\Concerns\ResolvesFgaClient;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\ResourceAdminService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared skeleton for the read-only scope report endpoints:
 * `GET /auth/admin-scopes`, `GET /auth/test-scopes`, `GET /auth/dashboard-scopes`.
 *
 * All three answer the same question — "what may this caller do?" — and differ
 * only in which {@see ResourceAdminService} resolvers they call and what keys
 * they encode. Everything else (method and Accept validation, CORS, the
 * `no-store` cache directive, extracting `sub` from the OIDC token, reading the
 * global-admin role, and deciding whether OpenFGA can be reached at all) was
 * triplicated across the three handlers, so a correction to the fail-closed
 * contract in one was a correction in one.
 *
 * Subclasses implement {@see buildScopePayload()} and nothing else.
 *
 * **Fail-closed contract.** When OpenFGA is unreachable or unconfigured, the
 * scope lists are empty but `is_global_admin` is still honored from the token —
 * a caller's Zitadel role does not depend on the authorization server being up.
 * `buildScopePayload()` receives `null` for the service in exactly that case.
 */
abstract class AbstractScopesHandler extends AbstractHandler
{
    use ResolvesFgaClient;

    public function __construct(?OpenFgaClient $fgaClient = null)
    {
        parent::__construct();

        $this->fgaClient             = $fgaClient;
        $this->allowedRequestMethods = [RequestMethod::GET];
        $this->allowedAcceptHeaders  = [AcceptHeader::JSON];
        $this->allowCredentials      = true;
    }

    /**
     * Build the response payload for one scope endpoint.
     *
     * @param string                    $sub           Zitadel user ID from the validated token, guaranteed non-empty
     * @param bool                      $isGlobalAdmin Whether the token carries the Zitadel `admin` role
     * @param ResourceAdminService|null $service       Resolver, or `null` when OpenFGA is unavailable — in which
     *                                                 case the implementation must return its fail-closed shape,
     *                                                 with every key it normally emits still present
     * @return array<string, mixed>
     */
    abstract protected function buildScopePayload(string $sub, bool $isGlobalAdmin, ?ResourceAdminService $service): array;

    final public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = static::initResponse($request);
        $method   = RequestMethod::from($request->getMethod());

        if ($method === RequestMethod::OPTIONS) {
            return $this->handlePreflightRequest($request, $response);
        }

        $response = $this->setAccessControlAllowOriginHeader($request, $response);
        $this->validateRequestMethod($request);

        $mime     = $this->validateAcceptHeader($request, AcceptabilityLevel::LAX);
        $response = $response->withHeader('Content-Type', $mime)
            ->withHeader('Cache-Control', 'no-store');

        /** @var array{sub?: string, roles?: array<string>}|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');

        if ($oidcUser === null) {
            throw new UnauthorizedException('Authentication required');
        }

        $sub = $oidcUser['sub'] ?? null;
        if (!is_string($sub) || trim($sub) === '') {
            throw new UnauthorizedException('Invalid authentication token');
        }

        $isGlobalAdmin = OidcAuthMiddleware::isAdmin($oidcUser);
        $service       = $this->isFgaClientAvailable()
            ? new ResourceAdminService($this->getFgaClient())
            : null;

        return $this->encodeResponseBody($response, $this->buildScopePayload($sub, $isGlobalAdmin, $service));
    }
}
