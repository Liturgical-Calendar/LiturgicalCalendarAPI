<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Admin;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\Outbox\OutboxRow;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Outbox Admin Handler — inspection and retry management.
 *
 * Provides endpoints for monitoring and operating on the OpenFGA outbox:
 *
 * - GET  /admin/outbox                — List rows (optional filters: status, access_request_id, limit, offset)
 * - GET  /admin/outbox?summary=1      — Summarise counts per status + oldest pending age
 * - POST /admin/outbox/{id}/retry     — Reset a failed_terminal row back to pending
 *
 * Access control: global admins (Zitadel "admin" role) only.
 */
final class OutboxAdminHandler extends AbstractHandler
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 200;

    private ?OutboxRepository $repository;

    public function __construct(?OutboxRepository $repository = null)
    {
        parent::__construct();

        $this->repository = $repository;

        $this->allowedRequestMethods      = [RequestMethod::GET, RequestMethod::POST];
        $this->allowedAcceptHeaders       = [AcceptHeader::JSON];
        $this->allowedRequestContentTypes = [RequestContentType::JSON];
        $this->allowCredentials           = true;
    }

    private function getRepository(): OutboxRepository
    {
        if ($this->repository === null) {
            $this->repository = new OutboxRepository(Connection::getInstance());
        }
        return $this->repository;
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

        // Authentication guard
        /** @var array{sub?: string, roles?: array<string>}|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');

        if ($oidcUser === null) {
            throw new UnauthorizedException('Authentication required');
        }

        $userId = $oidcUser['sub'] ?? null;
        if (!is_string($userId) || trim($userId) === '') {
            throw new UnauthorizedException('Invalid authentication token');
        }

        if (!OidcAuthMiddleware::isAdmin($oidcUser)) {
            throw new UnauthorizedException('Admin role required');
        }

        // Route on method and path segments
        $path      = $request->getUri()->getPath();
        $pathParts = array_values(array_filter(explode('/', trim($path, '/'))));

        // POST /admin/outbox/{id}/retry
        // pathParts: ['admin', 'outbox', '{id}', 'retry']
        if (
            $method === RequestMethod::POST
            && count($pathParts) === 4
            && $pathParts[3] === 'retry'
        ) {
            $rawId = $pathParts[2];
            if (!ctype_digit($rawId) || (int) $rawId < 1) {
                throw new ValidationException('Invalid outbox row id');
            }
            return $this->handleRetry($request, $response, (int) $rawId);
        }

        // GET /admin/outbox?...
        if ($method === RequestMethod::GET) {
            return $this->handleGet($request, $response);
        }

        // Fallback — should not be reachable given allowedRequestMethods
        throw new ValidationException('Unsupported request');
    }

    // -----------------------------------------------------------------------
    // GET handler
    // -----------------------------------------------------------------------

    private function handleGet(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();

        // ?summary=1 — return counts per status + oldest pending age
        $summaryRaw = $params['summary'] ?? null;
        if ($summaryRaw !== null && $summaryRaw !== '' && $summaryRaw !== '0') {
            return $this->handleSummary($response);
        }

        // List with optional filters and pagination
        $statusRaw          = isset($params['status']) && is_string($params['status']) ? $params['status'] : null;
        $accessRequestIdRaw = isset($params['access_request_id']) && is_string($params['access_request_id'])
            ? $params['access_request_id']
            : null;

        $limit  = $this->parseLimit($params['limit'] ?? null);
        $offset = $this->parseOffset($params['offset'] ?? null);

        if ($statusRaw !== null) {
            $validStatuses = ['pending', 'retrying', 'succeeded', 'failed_terminal'];
            if (!in_array($statusRaw, $validStatuses, true)) {
                throw new ValidationException(
                    sprintf('Invalid status. Valid values: %s', implode(', ', $validStatuses))
                );
            }
        }

        $repo   = $this->getRepository();
        $result = $repo->list($statusRaw, $accessRequestIdRaw, $limit, $offset);

        $rows = array_map(
            static fn(OutboxRow $r): array => self::rowToArray($r),
            $result['rows']
        );

        $total   = $result['total'];
        $count   = count($rows);
        $hasMore = ( $offset + $count ) < $total;

        return $this->encodeResponseBody($response, [
            'items'    => $rows,
            'count'    => $count,
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'has_more' => $hasMore,
        ]);
    }

    private function handleSummary(ResponseInterface $response): ResponseInterface
    {
        $repo   = $this->getRepository();
        $counts = $repo->countByStatus();

        // Ensure all four statuses are always present in the response, defaulting to 0.
        $normalised = [
            'pending'         => $counts['pending']        ?? 0,
            'retrying'        => $counts['retrying']       ?? 0,
            'succeeded'       => $counts['succeeded']      ?? 0,
            'failed_terminal' => $counts['failed_terminal'] ?? 0,
        ];

        $oldestAge = $repo->oldestPendingAgeSeconds();

        return $this->encodeResponseBody($response, [
            'counts'                     => $normalised,
            'oldest_pending_age_seconds' => $oldestAge,
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/outbox/{id}/retry
    // -----------------------------------------------------------------------

    private function handleRetry(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $repo = $this->getRepository();

        // Distinguish missing row (404) from non-terminal row (409).
        if ($repo->getById($id) === null) {
            return $this->encodeResponseBody($response, ['error' => 'Outbox row not found', 'id' => $id], StatusCode::NOT_FOUND);
        }

        $reset = $repo->resetForRetry($id);

        if (!$reset) {
            // Row exists but is not in failed_terminal state.
            return $this->encodeResponseBody($response, ['error' => 'Row must be in failed_terminal state to retry'], StatusCode::CONFLICT);
        }

        $row = $repo->getById($id);

        return $this->encodeResponseBody($response, [
            'success' => true,
            'id'      => $id,
            'row'     => $row !== null ? self::rowToArray($row) : null,
        ]);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function rowToArray(OutboxRow $row): array
    {
        return [
            'id'              => $row->id,
            'operation'       => $row->operation->value,
            'fga_user'        => $row->fgaUser,
            'fga_relation'    => $row->fgaRelation,
            'fga_object'      => $row->fgaObject,
            'status'          => $row->status->value,
            'attempts'        => $row->attempts,
            'next_attempt_at' => $row->nextAttemptAt->format(\DateTimeInterface::ATOM),
            'last_error'      => $row->lastError,
            'last_error_code' => $row->lastErrorCode,
            'metadata'        => $row->metadata,
            'created_at'      => $row->createdAt->format(\DateTimeInterface::ATOM),
            'completed_at'    => $row->completedAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function parseLimit(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_LIMIT;
        }
        if (!is_string($raw) || !ctype_digit($raw)) {
            throw new ValidationException('limit must be a positive integer');
        }
        $limit = (int) $raw;
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new ValidationException(sprintf('limit must be between 1 and %d', self::MAX_LIMIT));
        }
        return $limit;
    }

    private function parseOffset(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return 0;
        }
        if (!is_string($raw) || !ctype_digit($raw)) {
            throw new ValidationException('offset must be a non-negative integer');
        }
        return (int) $raw;
    }
}
