<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Auth;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Repositories\UserNotificationRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * User notifications handler — issue #573.
 *
 * - GET  /auth/notifications        — Inbox + unread badge.
 * - POST /auth/notifications/seen   — Mark inbox seen (bookmark).
 *
 * OIDC-gated via OidcAuthMiddleware (wired in Router.php). The user
 * identifier is the Zitadel sub from oidc_user, which keys both the
 * access_requests query and the user_notification_state row.
 */
final class NotificationsHandler extends AbstractHandler
{
    private const INBOX_LIMIT = 50;

    private ?UserNotificationRepository $repository = null;

    public function __construct()
    {
        parent::__construct();

        $this->allowedRequestMethods      = [RequestMethod::GET, RequestMethod::POST];
        $this->allowedAcceptHeaders       = [AcceptHeader::JSON];
        $this->allowedRequestContentTypes = [RequestContentType::JSON];
        $this->allowCredentials           = true;
    }

    private function getRepository(): UserNotificationRepository
    {
        if ($this->repository === null) {
            $this->repository = new UserNotificationRepository();
        }
        return $this->repository;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = static::initResponse($request);

        $method = RequestMethod::tryFrom($request->getMethod());

        if ($method === null) {
            $this->validateRequestMethod($request);
        }

        if ($method === RequestMethod::OPTIONS) {
            return $this->handlePreflightRequest($request, $response);
        }

        $response = $this->setAccessControlAllowOriginHeader($request, $response);
        $this->validateRequestMethod($request);

        $mime     = $this->validateAcceptHeader($request, AcceptabilityLevel::LAX);
        $response = $response->withHeader('Content-Type', $mime);
        $response = $response->withHeader('Cache-Control', 'no-store');

        /** @var array{sub?: string, email?: string, name?: string, preferred_username?: string, roles?: array<string>}|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');
        if ($oidcUser === null) {
            throw new UnauthorizedException('Authentication required');
        }
        $userId = $oidcUser['sub'] ?? null;
        if (!is_string($userId) || trim($userId) === '') {
            throw new UnauthorizedException('Invalid authentication token');
        }

        $tail = $this->extractSubPath($request->getUri()->getPath());

        if ($method === RequestMethod::GET && $tail === '') {
            return $this->getInbox($response, $userId);
        }

        if ($method === RequestMethod::POST && $tail === 'seen') {
            return $this->markSeen($response, $userId);
        }

        return $response->withStatus(StatusCode::NOT_FOUND->value, StatusCode::NOT_FOUND->reason());
    }

    private function getInbox(ResponseInterface $response, string $userId): ResponseInterface
    {
        if (!Connection::isConfigured()) {
            throw new \RuntimeException('Database not configured');
        }
        return $this->encodeResponseBody(
            $response,
            $this->getRepository()->fetchInbox($userId, self::INBOX_LIMIT)
        );
    }

    private function markSeen(ResponseInterface $response, string $userId): ResponseInterface
    {
        if (!Connection::isConfigured()) {
            throw new \RuntimeException('Database not configured');
        }
        return $this->encodeResponseBody(
            $response,
            ['success' => true, 'seen_at' => $this->getRepository()->markSeen($userId)]
        );
    }

    private function extractSubPath(string $path): string
    {
        $prefix = '/auth/notifications';
        $base   = isset($_ENV['API_BASE_PATH']) && is_string($_ENV['API_BASE_PATH'])
            ? rtrim($_ENV['API_BASE_PATH'], '/')
            : '';
        $needle = $base . $prefix;
        $tail   = substr($path, strlen($needle));
        return trim($tail, '/');
    }
}
