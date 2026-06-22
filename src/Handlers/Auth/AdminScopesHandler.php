<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Auth;

use LiturgicalCalendar\Api\Handlers\AbstractHandler;
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
 * Admin Scopes Handler
 *
 * GET /auth/admin-scopes — report the authenticated caller's admin status:
 *   - is_global_admin: the Zitadel `admin` role is present in the token.
 *   - admin_scopes: union of OpenFGA `admin` tuples across the admin-capable
 *     object types, as [{object_type, object_id}].
 *   - is_resource_admin: admin_scopes is non-empty.
 *
 * Fails closed: when OpenFGA is unavailable, admin_scopes is empty and
 * is_resource_admin is false, but is_global_admin is still honored from the token.
 */
final class AdminScopesHandler extends AbstractHandler
{
    private ?OpenFgaClient $fgaClient = null;

    public function __construct(?OpenFgaClient $fgaClient = null)
    {
        parent::__construct();

        $this->fgaClient             = $fgaClient;
        $this->allowedRequestMethods = [RequestMethod::GET];
        $this->allowedAcceptHeaders  = [AcceptHeader::JSON];
        $this->allowCredentials      = true;
    }

    private function isFgaClientAvailable(): bool
    {
        return $this->fgaClient !== null || OpenFgaClient::isConfigured();
    }

    private function getFgaClient(): OpenFgaClient
    {
        if ($this->fgaClient === null) {
            $this->fgaClient = OpenFgaClient::fromEnv();
        }
        return $this->fgaClient;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
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

        $scopes = [];
        if ($this->isFgaClientAvailable()) {
            $scopes = ( new ResourceAdminService($this->getFgaClient()) )->resolveScopes($sub);
        }

        return $this->encodeResponseBody($response, [
            'is_global_admin'   => $isGlobalAdmin,
            'is_resource_admin' => $scopes !== [],
            'admin_scopes'      => $scopes,
        ]);
    }
}
