<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Auth;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Enum\ChangeReviewStatus;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Handlers\Pagination\OffsetPaginationTrait;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Change Request Handler — an editor's own view of their source-data change requests.
 *
 * - GET  /auth/change-requests                    — List the caller's own batches, paginated.
 * - POST /auth/change-requests/{batchId}/withdraw — Withdraw one of them.
 *
 * Scoping is entirely server-side: every query is built from the caller's own `sub`,
 * read out of the `oidc_user` request attribute set by OidcAuthMiddleware. Nothing
 * in the request (query parameter, body field, header) is ever accepted as a
 * submitter identifier — accepting one would let a caller list or withdraw someone
 * else's change requests.
 *
 * Withdraw always answers 404 for a batch that either isn't the caller's or is no
 * longer pending, never 403 — a 403 would confirm to the caller that a batch they
 * cannot touch exists at all. {@see SourceDataChangeRequestRepository::withdrawBatch()}
 * enforces the same scoping again in SQL, so a bug here cannot widen it.
 */
final class ChangeRequestHandler extends AbstractHandler
{
    use OffsetPaginationTrait;

    private ?SourceDataChangeRequestRepository $repository;

    /**
     * @param string[] $requestPathParams
     */
    public function __construct(array $requestPathParams = [], ?SourceDataChangeRequestRepository $repository = null)
    {
        parent::__construct($requestPathParams);

        $this->repository = $repository;

        $this->allowedRequestMethods      = [RequestMethod::GET, RequestMethod::POST];
        $this->allowedAcceptHeaders       = [AcceptHeader::JSON];
        $this->allowedRequestContentTypes = [RequestContentType::JSON];
        $this->allowCredentials           = true;
    }

    private function getRepository(): SourceDataChangeRequestRepository
    {
        if ($this->repository === null) {
            $this->repository = new SourceDataChangeRequestRepository(Connection::getInstance());
        }
        return $this->repository;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = static::initResponse($request);
        // tryFrom returns null for unrecognized methods; let validateRequestMethod
        // surface that as a 405 instead of a ValueError → 500.
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
        $response = $response->withHeader('Content-Type', $mime)
            ->withHeader('Cache-Control', 'no-store');

        // Check authentication via OIDC token in request attribute. The caller's
        // own `sub` is the ONLY submitter identifier ever used below — never a
        // query parameter, body field, or header supplied by the client.
        /** @var array{sub?: string}|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');
        if ($oidcUser === null) {
            throw new UnauthorizedException('Authentication required');
        }

        $sub = $oidcUser['sub'] ?? null;
        if (!is_string($sub) || trim($sub) === '') {
            throw new UnauthorizedException('Invalid authentication token');
        }

        // requestPathParams carries the segments after `/auth`, so index 0 is
        // `change-requests` itself.
        if ($method === RequestMethod::POST) {
            $batchId = $this->requestPathParams[1] ?? null;
            $action  = $this->requestPathParams[2] ?? null;

            if (!is_string($batchId) || $batchId === '' || $action !== 'withdraw') {
                throw new ValidationException(
                    'Invalid request path. Expected: /auth/change-requests/{batchId}/withdraw'
                );
            }

            return $this->withdraw($response, $sub, $batchId);
        }

        return $this->list($request, $response, $sub);
    }

    /**
     * GET /auth/change-requests — the caller's own batches, paginated.
     *
     * Query params:
     *   - status (optional; one of ChangeReviewStatus's values)
     *   - limit  (validated via OffsetPaginationTrait)
     *   - offset (validated via OffsetPaginationTrait)
     *
     * An unrecognised `status` value is a ValidationException, not a silently
     * unfiltered listing — a caller who thinks they narrowed the list must never
     * silently get everything instead.
     */
    private function list(ServerRequestInterface $request, ResponseInterface $response, string $sub): ResponseInterface
    {
        $params = $request->getQueryParams();

        $status    = null;
        $rawStatus = $params['status'] ?? null;
        if (is_string($rawStatus) && $rawStatus !== '') {
            $status = ChangeReviewStatus::tryFrom($rawStatus);
            if ($status === null) {
                throw new ValidationException(sprintf(
                    'Invalid status "%s". Valid values: %s',
                    $rawStatus,
                    implode(', ', array_map(static fn (ChangeReviewStatus $s): string => $s->value, ChangeReviewStatus::cases()))
                ));
            }
        }

        $limit  = $this->parseLimit($params['limit'] ?? null);
        $offset = $this->parseOffset($params['offset'] ?? null);

        $repo           = $this->getRepository();
        $changeRequests = $repo->listBySubmitter($sub, $status, $limit, $offset);
        $total          = $repo->countBySubmitter($sub, $status);

        return $this->encodeResponseBody($response, [
            'change_requests' => $changeRequests,
            'total'           => $total,
            'limit'           => $limit,
            'offset'          => $offset,
        ]);
    }

    /**
     * POST /auth/change-requests/{batchId}/withdraw — withdraw the caller's own batch.
     *
     * `withdrawBatch()` returns 0 rows both when the batch is not the caller's and
     * when it has already been decided (approved/rejected/withdrawn). Both cases
     * answer 404 here, deliberately never 403 (see class docblock).
     */
    private function withdraw(ResponseInterface $response, string $sub, string $batchId): ResponseInterface
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $batchId)) {
            throw new ValidationException('Invalid batch ID format');
        }

        $rows = $this->getRepository()->withdrawBatch($batchId, $sub);
        if ($rows === 0) {
            throw new NotFoundException('Change request batch not found');
        }

        return $this->encodeResponseBody($response, [
            'success'  => true,
            'batch_id' => $batchId,
            'status'   => ChangeReviewStatus::WITHDRAWN->value,
        ]);
    }
}
