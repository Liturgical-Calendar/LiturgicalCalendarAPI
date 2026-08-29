<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Admin;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Enum\ChangeReviewStatus;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Handlers\Concerns\ResolvesFgaClient;
use LiturgicalCalendar\Api\Handlers\Pagination\OffsetPaginationTrait;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware;
use LiturgicalCalendar\Api\Repositories\AuditLogRepository;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeRequestReview;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\ResourceAdminService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Change Request Admin Handler — the reviewer's queue for source-data change requests.
 *
 * - GET  /admin/change-requests                   — Review queue and history, paginated.
 * - POST /admin/change-requests/{batchId}/approve — Approve a batch.
 * - POST /admin/change-requests/{batchId}/reject  — Reject a batch, with an optional reason.
 *
 * Global admins see and act on every batch. Resource admins see and act only on batches
 * for resources they administer, mirroring {@see AccessRequestAdminHandler}.
 *
 * **Filtering the list does not authorize a mutation.** approve()/reject() re-check the
 * caller's authorization on the SPECIFIC batch named in the path, never trusting that a
 * batch reachable only via GET's filtered list is the only one a caller can name. An admin
 * who can list nothing must not be able to approve a batch by guessing its id.
 *
 * **404, never 403, for "not yours."** Both "no such batch" and "exists but you don't
 * administer it" answer 404 with the same message — a 403 would confirm to the caller
 * that a batch they cannot touch exists at all. Mirrors
 * {@see \LiturgicalCalendar\Api\Handlers\Auth\ChangeRequestHandler::withdraw()}.
 */
final class ChangeRequestAdminHandler extends AbstractHandler
{
    use OffsetPaginationTrait;
    use ResolvesFgaClient;

    private ?SourceDataChangeRequestRepository $repository;
    private ?AuditLogRepository $auditLog = null;

    /**
     * @param string[] $requestPathParams Segments after `/admin`. Index 0 is
     *                                    'change-requests', index 1 the batch id (POST
     *                                    only), index 2 the action ('approve'|'reject').
     */
    public function __construct(
        array $requestPathParams = [],
        ?SourceDataChangeRequestRepository $repository = null,
        ?OpenFgaClient $fgaClient = null
    ) {
        parent::__construct($requestPathParams);

        $this->repository = $repository;
        $this->fgaClient  = $fgaClient;

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

    private function getAuditLog(): AuditLogRepository
    {
        if ($this->auditLog === null) {
            $this->auditLog = new AuditLogRepository();
        }
        return $this->auditLog;
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

        if ($method === RequestMethod::GET) {
            return $this->list($request, $response, $sub, $isGlobalAdmin);
        }

        // POST — requestPathParams[0] is 'change-requests', [1] the batch id, [2] the action.
        $batchId = $this->requestPathParams[1] ?? null;
        $action  = $this->requestPathParams[2] ?? null;

        if (!is_string($batchId) || $batchId === '' || !in_array($action, ['approve', 'reject'], true)) {
            throw new ValidationException(
                'Invalid request path. Expected: /admin/change-requests/{batchId}/approve|reject'
            );
        }

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $batchId)) {
            // Malformed-id shape is decidable from the input alone, so a 400 here
            // leaks nothing an attacker could not already tell by inspection.
            throw new ValidationException('Invalid batch ID format');
        }

        $rows = $this->getRepository()->getBatch($batchId);
        if ($rows === [] || !$this->callerAdministersBatch($rows, $sub, $isGlobalAdmin)) {
            throw new NotFoundException('Change request batch not found');
        }

        return $action === 'approve'
            ? $this->approve($response, $sub, $batchId, $rows[0])
            : $this->reject($request, $response, $sub, $batchId, $rows[0]);
    }

    /**
     * GET /admin/change-requests — review queue and history, paginated.
     *
     * Query params:
     *   - status: One of ChangeReviewStatus's values. Omitted returns all statuses.
     *   - limit:  Max items in this page (1..500, default 100).
     *   - offset: Zero-based item index where this page starts (default 0).
     *
     * Global admins see every batch. Resource admins see only batches for resources
     * they administer ({@see ChangeRequestReview::filterForAdmin()}, applied post-fetch
     * to each page — filtering happens in application code, not SQL, because it depends
     * on OpenFGA relations no query here can express).
     *
     * **`count` may be smaller than both `limit` and `total`.** Once a non-global admin's
     * page is filtered, `total` still reflects the PRE-filter SQL count (consistent with
     * `/admin/access-requests`), and `has_more` is derived from the PRE-filter SQL page
     * size — never the post-filter count. Using the post-filter count for `has_more` would
     * break in both directions: a heavily-filtered final SQL page would falsely advertise
     * more pages forever, and — the sharper failure — a page that filters down to ZERO
     * surviving rows would falsely advertise NO more pages, so a client that stops paging
     * on an empty page would silently stop seeing reviewable batches that exist on later
     * pages. Clients must keep paging on `has_more`, not on whether the current page came
     * back non-empty.
     */
    private function list(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $sub,
        bool $isGlobalAdmin
    ): ResponseInterface {
        $repo   = $this->getRepository();
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

        $page  = $repo->listAll($status, $limit, $offset);
        $total = $repo->countAll($status);

        // Snapshot the SQL page size BEFORE filterForAdmin() shrinks the page — see
        // the docblock above for why has_more must be derived from this, not from
        // count($page) after filtering.
        $sqlPageCount = count($page);

        if (!$isGlobalAdmin) {
            $page = $this->filterForAdmin($page, $sub);
        }

        return $this->encodeResponseBody($response, [
            'change_requests' => $page,
            'count'           => count($page),
            'total'           => $total,
            'limit'           => $limit,
            'offset'          => $offset,
            'has_more'        => ( $offset + $sqlPageCount ) < $total,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $batches
     * @return array<int, array<string, mixed>>
     */
    private function filterForAdmin(array $batches, string $sub): array
    {
        if (!$this->isFgaClientAvailable()) {
            return [];
        }

        $review = new ChangeRequestReview(new ResourceAdminService($this->getFgaClient()));

        return $review->filterForAdmin($batches, $sub);
    }

    /**
     * Whether $sub may act on the batch $rows belong to.
     *
     * Every row in a batch shares one resource, so `permissions` — the synthetic
     * `[{object_type, object_id, relation: 'admin'}]` key
     * {@see SourceDataChangeRequestRepository::hydrate()} attaches to every row — is
     * identical across the batch; reading it from the first row is exact, not an
     * approximation.
     *
     * This goes straight to {@see ResourceAdminService::administersAllResources()}
     * rather than through {@see ChangeRequestReview::administers()}: that method takes a
     * freshly-built `ChangeResource`, whose constructor is private behind typed
     * factories that RE-QUALIFY a bare id with a `Rite` (e.g. `nationalCalendar()` calls
     * `RiteScopedObjectId::qualify()` internally). A `resource_id` read back from this
     * table is already rite-qualified (e.g. `roman/US`); calling a factory on it again
     * would double-qualify it into a nonexistent object id (`roman/roman/US`) and fail
     * closed for the wrong reason. Calling `administersAllResources()` directly with the
     * row's own `permissions` key is exactly what `ChangeRequestReview::administers()`
     * does internally (down to the same fail-closed `catch (\RuntimeException)`), so
     * behaviour is identical — this only skips reconstructing an object nothing else
     * needs.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function callerAdministersBatch(array $rows, string $sub, bool $isGlobalAdmin): bool
    {
        if ($isGlobalAdmin) {
            return true;
        }

        if (!$this->isFgaClientAvailable()) {
            return false;
        }

        /** @var array<int, array{object_type: string, object_id: string, relation: string}> $permissions */
        $permissions = is_array($rows[0]['permissions'] ?? null) ? $rows[0]['permissions'] : [];

        /** @var array<string, bool> $cache */
        $cache = [];

        try {
            return ( new ResourceAdminService($this->getFgaClient()) )
                ->administersAllResources($permissions, 'user:' . $sub, $cache);
        } catch (\RuntimeException) {
            // Fail closed: an unreachable OpenFGA must never be read as authorized.
            return false;
        }
    }

    /**
     * POST /admin/change-requests/{batchId}/approve
     *
     * Existence and authorization were already settled in handle() before this is
     * reached. `approveBatch()` transitions only rows still `submitted`; zero rows
     * transitioned means someone else decided the batch first — a 409, not a silent
     * success.
     *
     * @param array<string, mixed> $firstRow One row of the batch, for the audit log.
     */
    private function approve(ResponseInterface $response, string $sub, string $batchId, array $firstRow): ResponseInterface
    {
        $decided = $this->getRepository()->approveBatch($batchId, $sub);
        if ($decided === 0) {
            throw new ConflictException('Change request batch was already decided');
        }

        $this->audit('change_request.approve', $sub, $batchId, $firstRow, []);

        return $this->encodeResponseBody($response, [
            'success'  => true,
            'batch_id' => $batchId,
            'status'   => ChangeReviewStatus::APPROVED->value,
        ]);
    }

    /**
     * POST /admin/change-requests/{batchId}/reject
     *
     * Same existence/authorization/transition ordering and 409 semantics as
     * {@see approve()}. `reason` is read from the raw JSON body via
     * `parseBodyParams()` rather than `getParsedBody()`, because this handler has no
     * body-parsing middleware in front of it when constructed directly (as the test
     * suite does) — only `JsonBodyParserMiddleware` in the production pipeline
     * populates `getParsedBody()`.
     *
     * @param array<string, mixed> $firstRow One row of the batch, for the audit log.
     */
    private function reject(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $sub,
        string $batchId,
        array $firstRow
    ): ResponseInterface {
        $body   = $this->parseBodyParams($request, false);
        $reason = is_array($body) && isset($body['reason']) && is_string($body['reason']) ? $body['reason'] : null;

        $decided = $this->getRepository()->rejectBatch($batchId, $sub, $reason);
        if ($decided === 0) {
            throw new ConflictException('Change request batch was already decided');
        }

        $this->audit('change_request.reject', $sub, $batchId, $firstRow, $reason !== null ? ['reason' => $reason] : []);

        return $this->encodeResponseBody($response, [
            'success'  => true,
            'batch_id' => $batchId,
            'status'   => ChangeReviewStatus::REJECTED->value,
        ]);
    }

    /**
     * Best-effort audit trail entry. A logging failure must never turn an already
     * -committed approve/reject into a 500 for the caller, so failures are swallowed
     * here rather than propagated.
     *
     * @param array<string, mixed> $firstRow
     * @param array<string, mixed> $details
     */
    private function audit(string $action, string $sub, string $batchId, array $firstRow, array $details): void
    {
        $resourceType = is_string($firstRow['resource_type'] ?? null) ? $firstRow['resource_type'] : 'sourcedata_change_request';
        $resourceId   = is_string($firstRow['resource_id'] ?? null) ? $firstRow['resource_id'] : $batchId;

        try {
            $this->getAuditLog()->log($sub, $action, $resourceType, $resourceId, array_merge(['batch_id' => $batchId], $details));
        } catch (\Throwable) {
            // Deliberately swallowed — see method docblock.
        }
    }
}
