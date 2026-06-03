<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Admin;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Handlers\Pagination\OffsetPaginationTrait;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\Outbox\OutboxDisposition;
use LiturgicalCalendar\Api\Services\Outbox\OutboxNotifier;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Api\Services\Outbox\OutboxProcessor;
use LiturgicalCalendar\Api\Services\Outbox\OutboxStatus;
use LiturgicalCalendar\Api\Services\RoleCascadeService;
use LiturgicalCalendar\Api\Services\ZitadelService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Access Request Admin Handler — review and manage access requests.
 *
 * Replaces both RoleRequestAdminHandler and PermissionRequestAdminHandler
 * with a unified workflow. On approval, both the Zitadel role and all
 * OpenFGA permission tuples are granted in a single operation.
 *
 * Global admins see all pending requests. Resource admins see only
 * requests for resources they administer.
 *
 * - GET  /admin/access-requests                — List requests
 * - POST /admin/access-requests/{id}/approve   — Approve (grant role + create tuples)
 * - POST /admin/access-requests/{id}/reject    — Reject with notes
 * - POST /admin/access-requests/{id}/revoke    — Revoke (remove role + delete tuples)
 */
final class AccessRequestAdminHandler extends AbstractHandler
{
    use OffsetPaginationTrait;

    private ?AccessRequestRepository $repository = null;
    private ?OpenFgaClient $fgaClient            = null;
    private ?OutboxRepository $outboxRepository  = null;
    private ?OutboxNotifier $outboxNotifier      = null;
    private ?OutboxProcessor $outboxProcessor    = null;

    /**
     * The OpenFGA client is constructor-injectable for tests — pass a
     * MockHandler-backed instance to verify the typed-exception integration
     * without touching the runtime env. Production calls use the zero-arg
     * form and the lazy `fromEnv()` fallback in `getFgaClient()`. Mirrors
     * the pattern PR #623 introduced for `PermissionAdminHandler`.
     */
    public function __construct(?OpenFgaClient $fgaClient = null)
    {
        parent::__construct();

        $this->fgaClient = $fgaClient;

        $this->allowedRequestMethods      = [RequestMethod::GET, RequestMethod::POST];
        $this->allowedAcceptHeaders       = [AcceptHeader::JSON];
        $this->allowedRequestContentTypes = [RequestContentType::JSON];
        $this->allowCredentials           = true;
    }

    /**
     * Inject outbox dependencies — primarily for tests (avoids real Redis / env).
     * Production calls use the lazy getters below, which build from env vars.
     */
    public function setOutboxDependencies(
        OutboxRepository $repo,
        OutboxNotifier $notifier,
        OutboxProcessor $processor,
    ): void {
        $this->outboxRepository = $repo;
        $this->outboxNotifier   = $notifier;
        $this->outboxProcessor  = $processor;
    }

    private function getRepository(): AccessRequestRepository
    {
        if ($this->repository === null) {
            $this->repository = new AccessRequestRepository();
        }
        return $this->repository;
    }

    private function getFgaClient(): OpenFgaClient
    {
        if ($this->fgaClient === null) {
            $this->fgaClient = OpenFgaClient::fromEnv();
        }
        return $this->fgaClient;
    }

    private function getOutboxRepository(): OutboxRepository
    {
        if ($this->outboxRepository === null) {
            $this->outboxRepository = new OutboxRepository(Connection::getInstance());
        }
        return $this->outboxRepository;
    }

    private function getOutboxNotifier(): OutboxNotifier
    {
        if ($this->outboxNotifier === null) {
            $redis = null;
            if (extension_loaded('redis') && ( isset($_ENV['REDIS_HOST']) || isset($_ENV['REDIS_SOCKET']) )) {
                try {
                    $redis = new \Redis();
                    if (isset($_ENV['REDIS_SOCKET']) && is_string($_ENV['REDIS_SOCKET']) && $_ENV['REDIS_SOCKET'] !== '') {
                        $redis->connect((string) $_ENV['REDIS_SOCKET']);
                    } else {
                        $redisHost = is_string($_ENV['REDIS_HOST'] ?? null) ? $_ENV['REDIS_HOST'] : '127.0.0.1';
                        $redisPort = is_numeric($_ENV['REDIS_PORT'] ?? null) ? (int) $_ENV['REDIS_PORT'] : 6379;
                        $redis->connect($redisHost, $redisPort);
                    }
                    if (isset($_ENV['REDIS_PASSWORD']) && is_string($_ENV['REDIS_PASSWORD']) && $_ENV['REDIS_PASSWORD'] !== '') {
                        $redis->auth((string) $_ENV['REDIS_PASSWORD']);
                    }
                } catch (\Throwable) {
                    $redis = null; // Best-effort; fall back to PG-only durability.
                }
            }
            $redisStream          = is_string($_ENV['REDIS_OUTBOX_STREAM'] ?? null) ? $_ENV['REDIS_OUTBOX_STREAM'] : 'litcal:reconcile-stream';
            $streamName           = $redisStream;
            $this->outboxNotifier = new OutboxNotifier($redis, $streamName);
        }
        return $this->outboxNotifier;
    }

    private function getOutboxProcessor(): OutboxProcessor
    {
        if ($this->outboxProcessor === null) {
            $maxAttempts           = is_numeric($_ENV['OUTBOX_MAX_ATTEMPTS'] ?? null)
                ? (int) $_ENV['OUTBOX_MAX_ATTEMPTS']
                : 10;
            $this->outboxProcessor = new OutboxProcessor(
                $this->getOutboxRepository(),
                $this->getFgaClient(),
                $maxAttempts,
            );
        }
        return $this->outboxProcessor;
    }

    /**
     * True when an OpenFGA client is reachable: either one was constructor-
     * injected (test path), or the env-based static is configured (prod
     * path). Replaces direct `OpenFgaClient::isConfigured()` checks at the
     * sites that gate tuple-write / admin-check work, so an injected mock
     * is honored without also requiring OPENFGA_* env vars to be set.
     */
    private function isFgaClientAvailable(): bool
    {
        return $this->fgaClient !== null || OpenFgaClient::isConfigured();
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

        $adminId = $oidcUser['sub'] ?? null;
        if ($adminId === null || ( is_string($adminId) && trim($adminId) === '' )) {
            throw new UnauthorizedException('Invalid authentication token');
        }

        if (!Connection::isConfigured()) {
            throw new \RuntimeException('Database not configured');
        }

        $isGlobalAdmin = OidcAuthMiddleware::isAdmin($oidcUser);

        if ($method === RequestMethod::GET) {
            return $this->listRequests($request, $response, $adminId, $isGlobalAdmin);
        }

        // POST — parse action from path
        $path      = $request->getUri()->getPath();
        $pathParts = explode('/', trim($path, '/'));
        $partCount = count($pathParts);

        if ($partCount < 4) {
            throw new ValidationException('Invalid request path. Expected: /admin/access-requests/{id}/{action}');
        }

        // Parse from the end to handle different route prefixes
        $action    = $pathParts[$partCount - 1];
        $requestId = $pathParts[$partCount - 2];

        // Validate UUID format
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $requestId)) {
            throw new ValidationException('Invalid request ID format');
        }

        $body  = $request->getParsedBody();
        $notes = null;
        if (is_array($body) && isset($body['notes']) && is_string($body['notes'])) {
            $notes = $body['notes'];
        }

        if ($action === 'approve') {
            return $this->approveRequest($response, $requestId, $adminId, $isGlobalAdmin, $notes);
        }

        if ($action === 'reject') {
            return $this->rejectRequest($response, $requestId, $adminId, $isGlobalAdmin, $notes);
        }

        if ($action === 'revoke') {
            return $this->revokeRequest($response, $requestId, $adminId, $isGlobalAdmin, $notes);
        }

        throw new ValidationException('Invalid action. Use "approve", "reject", or "revoke"');
    }

    /**
     * GET /admin/access-requests — List access requests, paginated.
     *
     * Global admins see all requests. Resource admins see only requests
     * for resources they administer (filterByAdminAccess is applied
     * post-fetch to each page).
     *
     * Query parameters:
     *   - status: Filter by status (pending, approved, rejected, revoked).
     *             If omitted, returns all statuses.
     *   - limit:  Max items in this page (1..500, default 100).
     *   - offset: Zero-based item index where this page starts (default 0).
     *
     * Note: when filterByAdminAccess is applied (non-global admin), the
     * returned `count` may be smaller than `limit` and smaller than
     * `total` — `total` reflects the pre-filter SQL count, `has_more`
     * reflects the SQL paginator. Clients should keep paging until
     * `has_more` is false even when individual pages come back short.
     * Same caveat as PR #623's /admin/permissions endpoint.
     */
    private function listRequests(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $adminId,
        bool $isGlobalAdmin
    ): ResponseInterface {
        $repo        = $this->getRepository();
        $queryParams = $request->getQueryParams();

        $statusFilter = isset($queryParams['status']) && is_string($queryParams['status'])
            ? $queryParams['status']
            : null;

        if ($statusFilter !== null && !in_array($statusFilter, AccessRequestRepository::VALID_STATUSES, true)) {
            throw new ValidationException(
                sprintf('Invalid status. Valid values are: %s', implode(', ', AccessRequestRepository::VALID_STATUSES))
            );
        }

        $limit  = $this->parseLimit($queryParams['limit']   ?? null);
        $offset = $this->parseOffset($queryParams['offset'] ?? null);

        $page  = $repo->getAll($statusFilter, $limit, $offset);
        $total = $repo->countAll($statusFilter);

        // Snapshot the SQL page size BEFORE filterByAdminAccess shrinks the page.
        // `has_more` must reflect the SQL paginator's state, not the filtered
        // page count: when SQL returns its final page (sqlPageCount < limit so
        // offset + sqlPageCount == total), the client should stop. If we used
        // the post-filter count instead, a heavily-filtered final page would
        // falsely advertise has_more=true and the client would fetch an empty
        // next page.
        $sqlPageCount = count($page);

        if (!$isGlobalAdmin) {
            $page = $this->filterByAdminAccess($page, $adminId);
        }

        return $this->encodeResponseBody($response, [
            'requests' => $page,
            'count'    => count($page),
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'has_more' => ( $offset + $sqlPageCount ) < $total,
        ]);
    }

    /**
     * POST /admin/access-requests/{id}/approve — Approve access request.
     *
     * Flow (outbox pattern):
     * 1. Validate request exists and is pending
     * 2. Check admin has authority (global admin OR resource admin for ALL requested resources)
     * 3. If permissions is empty: approve in DB and return immediately
     * 4. Otherwise:
     *    a. PG BEGIN
     *       - repo.approve(requestId, adminId, notes)
     *       - outbox.insertBatch(rows) — one row per permission, idempotency_key ensures
     *         that re-approving the same request collapses to the same outbox row IDs
     *    b. PG COMMIT
     *    c. For each outbox row: processor.processSync(), then notifier.notify() when
     *       row is still in a non-terminal state (pending/retrying)
     * 5. Sync role to Zitadel (unchanged)
     * 6. Return response with success=true (whenever DB committed), plus outbox counters
     *
     * `success` is true whenever the DB commit succeeded — this is the deliberate
     * semantic shift from #628. Tuple delivery failures surface via outbox_pending /
     * outbox_failed / fga_errors rather than aborting the entire approval.
     */
    private function approveRequest(
        ResponseInterface $response,
        string $requestId,
        string $adminId,
        bool $isGlobalAdmin,
        ?string $notes
    ): ResponseInterface {
        $repo = $this->getRepository();

        $accessRequest = $repo->getById($requestId);
        if ($accessRequest === null) {
            throw new NotFoundException('Access request not found');
        }

        if (( $accessRequest['status'] ?? '' ) !== 'pending') {
            throw new ValidationException(
                sprintf('Cannot approve a request with status: %s', is_string($accessRequest['status'] ?? null) ? $accessRequest['status'] : 'unknown')
            );
        }

        $userId        = is_string($accessRequest['zitadel_user_id'] ?? null) ? $accessRequest['zitadel_user_id'] : '';
        $requestedRole = is_string($accessRequest['requested_role'] ?? null) ? $accessRequest['requested_role'] : '';
        /** @var array<int, array{object_type: string, object_id: string, relation: string}> $permissions */
        $permissions = is_array($accessRequest['permissions'] ?? null) ? $accessRequest['permissions'] : [];

        if ($userId === '' || $requestedRole === '') {
            throw new ValidationException('Access request has incomplete data');
        }

        // Check admin authority over ALL requested resources
        $this->requireAdminForAllResources($adminId, $isGlobalAdmin, $permissions);

        // Fast path: no permissions to write — just approve in DB and return.
        if (empty($permissions) || !$this->isFgaClientAvailable()) {
            $approved = $repo->approve($requestId, $adminId, $notes);
            if (!$approved) {
                throw new ValidationException('Failed to approve request');
            }

            [$roleAssigned, $zitadelError] = $this->syncZitadelRole($repo, $requestId, $userId, $requestedRole);

            return $this->encodeResponseBody($response, [
                'success'        => true,
                'role_assigned'  => $roleAssigned,
                'zitadel_error'  => $zitadelError,
                'tuples_created' => [],
                'fga_errors'     => [],
                'outbox_ids'     => [],
                'outbox_pending' => 0,
                'outbox_failed'  => 0,
                'message'        => $this->approvalMessage($roleAssigned, $zitadelError, 0, 0),
            ]);
        }

        // Step 1: Build outbox rows — one per permission tuple.
        $fgaUser    = "user:{$userId}";
        $outboxRows = [];
        foreach ($permissions as $perm) {
            $objectType = is_string($perm['object_type'] ?? null) ? $perm['object_type'] : '';
            $objectId   = is_string($perm['object_id'] ?? null) ? $perm['object_id'] : '';
            $relation   = is_string($perm['relation'] ?? null) ? $perm['relation'] : '';
            $fgaObject  = "{$objectType}:{$objectId}";

            // Idempotency key: scoped to this access request + the specific tuple,
            // so re-approving the same request after a partial first attempt collapses
            // to the same row rather than inserting a duplicate.
            $idempotencyKey = "access_request:{$requestId}:write_tuple:{$fgaUser}:{$relation}:{$fgaObject}";

            $outboxRows[] = [
                'operation'       => OutboxOperation::WRITE_TUPLE,
                'fga_user'        => $fgaUser,
                'fga_relation'    => $relation,
                'fga_object'      => $fgaObject,
                'idempotency_key' => $idempotencyKey,
                'metadata'        => ['access_request_id' => $requestId],
            ];
        }

        // Step 2: Atomically approve in DB + insert outbox rows in one PG transaction.
        $pdo    = Connection::getInstance();
        $outbox = $this->getOutboxRepository();

        $pdo->beginTransaction();
        try {
            $approved = $repo->approve($requestId, $adminId, $notes);
            if (!$approved) {
                $pdo->rollBack();
                throw new ValidationException('Failed to approve request');
            }
            $outboxIds = $outbox->insertBatch($outboxRows);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Step 3: Sync fast path — attempt each outbox row immediately.
        // Rows that fail (transient errors) stay in 'retrying' state and will be
        // picked up by the cron backstop. Rows that fail terminally (4xx validation)
        // are marked 'failed_terminal' and surface in outbox_failed.
        $processor = $this->getOutboxProcessor();
        $notifier  = $this->getOutboxNotifier();

        /** @var list<array{id: int, disposition: string, status: string}> $outboxResult */
        $outboxResult  = [];
        $createdTuples = [];
        $fgaErrors     = [];

        foreach ($outboxIds as $rowId) {
            $disposition = $processor->processSync($rowId);
            $current     = $outbox->getById($rowId);
            $statusValue = $current !== null ? $current->status->value : OutboxStatus::PENDING->value;

            $outboxResult[] = [
                'id'          => $rowId,
                'disposition' => $disposition->name,
                'status'      => $statusValue,
            ];

            if ($disposition === OutboxDisposition::BENIGN_SUCCESS) {
                // Row is in succeeded state (or was already succeeded idempotently).
                $createdTuples[] = [
                    'user'     => $current !== null ? $current->fgaUser     : $fgaUser,
                    'relation' => $current !== null ? $current->fgaRelation : '',
                    'object'   => $current !== null ? $current->fgaObject   : '',
                ];
            } else {
                // RETRY or TERMINAL — surface in fga_errors for back-compat.
                $fgaErrors[] = [
                    'outbox_id' => $rowId,
                    'status'    => $statusValue,
                    'error'     => $current !== null ? ( $current->lastError ?? 'unknown' ) : 'unknown',
                ];
            }

            // Notify the async consumer if the row is still non-terminal.
            if (
                $current !== null
                && in_array($current->status, [OutboxStatus::PENDING, OutboxStatus::RETRYING], true)
            ) {
                $notifier->notify($rowId, OutboxOperation::WRITE_TUPLE->value);
            }
        }

        $outboxPending = count(array_filter($outboxResult, static fn($r) => $r['status'] === OutboxStatus::RETRYING->value || $r['status'] === OutboxStatus::PENDING->value));
        $outboxFailed  = count(array_filter($outboxResult, static fn($r) => $r['status'] === OutboxStatus::FAILED_TERMINAL->value));

        // Step 4: Sync role to Zitadel (unchanged)
        [$roleAssigned, $zitadelError] = $this->syncZitadelRole($repo, $requestId, $userId, $requestedRole);

        return $this->encodeResponseBody($response, [
            'success'        => true,
            'role_assigned'  => $roleAssigned,
            'zitadel_error'  => $zitadelError,
            'tuples_created' => $createdTuples,
            'fga_errors'     => $fgaErrors,
            'outbox_ids'     => $outboxIds,
            'outbox_pending' => $outboxPending,
            'outbox_failed'  => $outboxFailed,
            'message'        => $this->approvalMessage($roleAssigned, $zitadelError, $outboxPending, $outboxFailed),
        ]);
    }

    /**
     * Sync a Zitadel role assignment after DB approval.
     *
     * Extracted to avoid duplicating the same try/catch in the fast-path and
     * the outbox-path branches of approveRequest.
     *
     * @return array{0: bool, 1: string|null}  [roleAssigned, zitadelError]
     */
    private function syncZitadelRole(
        AccessRequestRepository $repo,
        string $requestId,
        string $userId,
        string $requestedRole,
    ): array {
        $roleAssigned = false;
        $zitadelError = null;

        if (ZitadelService::isConfigured()) {
            if ($userId === '' || $requestedRole === '') {
                $zitadelError = 'Missing user ID or role in approved request';
                $repo->updateZitadelSyncStatus($requestId, 'failed', $zitadelError);
            } else {
                $repo->updateZitadelSyncStatus($requestId, 'pending');

                try {
                    $zitadel = ZitadelService::fromEnv();
                    $zitadel->assignUserRole($userId, $requestedRole);
                    $roleAssigned = true;

                    $repo->updateZitadelSyncStatus($requestId, 'synced');
                } catch (\Exception $e) {
                    $zitadelError = $e->getMessage();
                    $repo->updateZitadelSyncStatus($requestId, 'failed', $zitadelError);
                }
            }
        }

        return [$roleAssigned, $zitadelError];
    }

    /**
     * Build the human-readable approval message, incorporating outbox deferral/failure notes.
     */
    private function approvalMessage(
        bool $roleAssigned,
        ?string $zitadelError,
        int $outboxPending,
        int $outboxFailed,
    ): string {
        $base = $roleAssigned
            ? 'Access request approved, role assigned and permissions granted'
            : ( $zitadelError !== null
                ? 'Access request approved but Zitadel sync failed (will retry)'
                : 'Access request approved (Zitadel not configured)' );

        if ($outboxPending > 0 && $outboxFailed > 0) {
            return sprintf(
                '%s; %d permission tuple(s) deferred for async delivery, %d failed terminally (check outbox)',
                $base,
                $outboxPending,
                $outboxFailed,
            );
        }

        if ($outboxPending > 0) {
            return sprintf(
                '%s; %d permission tuple(s) deferred for async delivery',
                $base,
                $outboxPending,
            );
        }

        if ($outboxFailed > 0) {
            return sprintf(
                '%s; %d permission tuple(s) failed terminally (check outbox)',
                $base,
                $outboxFailed,
            );
        }

        return $base;
    }

    /**
     * POST /admin/access-requests/{id}/reject — Reject the request.
     */
    private function rejectRequest(
        ResponseInterface $response,
        string $requestId,
        string $adminId,
        bool $isGlobalAdmin,
        ?string $notes
    ): ResponseInterface {
        $repo = $this->getRepository();

        $accessRequest = $repo->getById($requestId);
        if ($accessRequest === null) {
            throw new NotFoundException('Access request not found');
        }

        if (( $accessRequest['status'] ?? '' ) !== 'pending') {
            throw new ValidationException(
                sprintf('Cannot reject a request with status: %s', is_string($accessRequest['status'] ?? null) ? $accessRequest['status'] : 'unknown')
            );
        }

        /** @var array<int, array{object_type: string, object_id: string, relation: string}> $permissions */
        $permissions = is_array($accessRequest['permissions'] ?? null) ? $accessRequest['permissions'] : [];

        $this->requireAdminForAllResources($adminId, $isGlobalAdmin, $permissions);

        $rejected = $repo->reject($requestId, $adminId, $notes);
        if (!$rejected) {
            throw new ValidationException('Failed to reject request');
        }

        return $this->encodeResponseBody($response, [
            'success' => true,
            'message' => 'Access request rejected',
        ]);
    }

    /**
     * POST /admin/access-requests/{id}/revoke — Revoke a previously approved request.
     *
     * Flow (outbox pattern, mirrors approveRequest):
     * 1. Validate request exists and is approved
     * 2. Check admin has authority (global admin OR resource admin for ALL requested resources)
     * 3. If permissions is empty: revoke in DB and return immediately
     * 4. Otherwise:
     *    a. PG BEGIN
     *       - repo.revoke(requestId, adminId, notes)
     *       - outbox.insertBatch(rows) — one row per permission, idempotency_key ensures
     *         that re-revoking the same request collapses to the same outbox row IDs
     *    b. PG COMMIT
     *    c. For each outbox row: processor.processSync(), then notifier.notify() when
     *       row is still in a non-terminal state (pending/retrying)
     * 5. Sync role cascade to Zitadel (unchanged)
     * 6. Return response with success=true (whenever DB committed), plus outbox counters
     *
     * `success` is true whenever the DB commit succeeded. Tuple deletion failures
     * surface via outbox_pending / outbox_failed / fga_errors rather than aborting
     * the entire revocation.
     */
    private function revokeRequest(
        ResponseInterface $response,
        string $requestId,
        string $adminId,
        bool $isGlobalAdmin,
        ?string $notes
    ): ResponseInterface {
        $repo = $this->getRepository();

        $accessRequest = $repo->getById($requestId);
        if ($accessRequest === null) {
            throw new NotFoundException('Access request not found');
        }

        if (( $accessRequest['status'] ?? '' ) !== 'approved') {
            throw new ValidationException(
                sprintf('Cannot revoke a request with status: %s', is_string($accessRequest['status'] ?? null) ? $accessRequest['status'] : 'unknown')
            );
        }

        $userId        = is_string($accessRequest['zitadel_user_id'] ?? null) ? $accessRequest['zitadel_user_id'] : '';
        $requestedRole = is_string($accessRequest['requested_role'] ?? null) ? $accessRequest['requested_role'] : '';
        /** @var array<int, array{object_type: string, object_id: string, relation: string}> $permissions */
        $permissions = is_array($accessRequest['permissions'] ?? null) ? $accessRequest['permissions'] : [];

        if ($userId === '' || $requestedRole === '') {
            throw new ValidationException('Access request has incomplete data');
        }

        $this->requireAdminForAllResources($adminId, $isGlobalAdmin, $permissions);

        // Fast path: no permissions to delete — just revoke in DB and return.
        if (empty($permissions) || !$this->isFgaClientAvailable()) {
            $revoked = $repo->revoke($requestId, $adminId, $notes);
            if (!$revoked) {
                throw new NotFoundException('Request not found or not in approved status');
            }

            [$roleRemoved, $zitadelError] = $this->syncZitadelRoleRevoke($repo, $requestId, $userId, $requestedRole);

            return $this->encodeResponseBody($response, [
                'success'        => true,
                'role_removed'   => $roleRemoved,
                'zitadel_error'  => $zitadelError,
                'tuples_deleted' => [],
                'fga_errors'     => [],
                'outbox_ids'     => [],
                'outbox_pending' => 0,
                'outbox_failed'  => 0,
                'message'        => $this->revocationMessage($roleRemoved, $zitadelError, 0, 0),
            ]);
        }

        // Step 1: Build outbox rows — one per permission tuple.
        $fgaUser    = "user:{$userId}";
        $outboxRows = [];
        foreach ($permissions as $perm) {
            $objectType = is_string($perm['object_type'] ?? null) ? $perm['object_type'] : '';
            $objectId   = is_string($perm['object_id'] ?? null) ? $perm['object_id'] : '';
            $relation   = is_string($perm['relation'] ?? null) ? $perm['relation'] : '';
            $fgaObject  = "{$objectType}:{$objectId}";

            // Idempotency key: scoped to this access request + the specific tuple,
            // so re-revoking the same request after a partial first attempt collapses
            // to the same row rather than inserting a duplicate.
            $idempotencyKey = "access_request:{$requestId}:delete_tuple:{$fgaUser}:{$relation}:{$fgaObject}";

            $outboxRows[] = [
                'operation'       => OutboxOperation::DELETE_TUPLE,
                'fga_user'        => $fgaUser,
                'fga_relation'    => $relation,
                'fga_object'      => $fgaObject,
                'idempotency_key' => $idempotencyKey,
                'metadata'        => ['access_request_id' => $requestId],
            ];
        }

        // Step 2: Atomically revoke in DB + insert outbox rows in one PG transaction.
        $pdo    = Connection::getInstance();
        $outbox = $this->getOutboxRepository();

        $pdo->beginTransaction();
        try {
            $revoked = $repo->revoke($requestId, $adminId, $notes);
            if (!$revoked) {
                $pdo->rollBack();
                throw new NotFoundException('Request not found or not in approved status');
            }
            $outboxIds = $outbox->insertBatch($outboxRows);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Step 3: Sync fast path — attempt each outbox row immediately.
        // Rows that fail (transient errors) stay in 'retrying' state and will be
        // picked up by the cron backstop. Rows that fail terminally (4xx validation)
        // are marked 'failed_terminal' and surface in outbox_failed.
        $processor = $this->getOutboxProcessor();
        $notifier  = $this->getOutboxNotifier();

        /** @var list<array{id: int, disposition: string, status: string}> $outboxResult */
        $outboxResult  = [];
        $deletedTuples = [];
        $fgaErrors     = [];

        foreach ($outboxIds as $rowId) {
            $disposition = $processor->processSync($rowId);
            $current     = $outbox->getById($rowId);
            $statusValue = $current !== null ? $current->status->value : OutboxStatus::PENDING->value;

            $outboxResult[] = [
                'id'          => $rowId,
                'disposition' => $disposition->name,
                'status'      => $statusValue,
            ];

            if ($disposition === OutboxDisposition::BENIGN_SUCCESS) {
                // Row is in succeeded state (or was already succeeded idempotently).
                $deletedTuples[] = [
                    'user'     => $current !== null ? $current->fgaUser     : $fgaUser,
                    'relation' => $current !== null ? $current->fgaRelation : '',
                    'object'   => $current !== null ? $current->fgaObject   : '',
                ];
            } else {
                // RETRY or TERMINAL — surface in fga_errors for back-compat.
                $fgaErrors[] = [
                    'outbox_id' => $rowId,
                    'status'    => $statusValue,
                    'error'     => $current !== null ? ( $current->lastError ?? 'unknown' ) : 'unknown',
                ];
            }

            // Notify the async consumer if the row is still non-terminal.
            if (
                $current !== null
                && in_array($current->status, [OutboxStatus::PENDING, OutboxStatus::RETRYING], true)
            ) {
                $notifier->notify($rowId, OutboxOperation::DELETE_TUPLE->value);
            }
        }

        $outboxPending = count(array_filter($outboxResult, static fn($r) => $r['status'] === OutboxStatus::RETRYING->value || $r['status'] === OutboxStatus::PENDING->value));
        $outboxFailed  = count(array_filter($outboxResult, static fn($r) => $r['status'] === OutboxStatus::FAILED_TERMINAL->value));

        // Step 4: Conditionally remove role from Zitadel (unchanged)
        [$roleRemoved, $zitadelError] = $this->syncZitadelRoleRevoke($repo, $requestId, $userId, $requestedRole);

        return $this->encodeResponseBody($response, [
            'success'        => true,
            'role_removed'   => $roleRemoved,
            'zitadel_error'  => $zitadelError,
            'tuples_deleted' => $deletedTuples,
            'fga_errors'     => $fgaErrors,
            'outbox_ids'     => $outboxIds,
            'outbox_pending' => $outboxPending,
            'outbox_failed'  => $outboxFailed,
            'message'        => $this->revocationMessage($roleRemoved, $zitadelError, $outboxPending, $outboxFailed),
        ]);
    }

    /**
     * Sync a Zitadel role revocation (cascade) after DB revoke.
     *
     * Extracted to avoid duplicating the same try/catch in the fast-path and
     * the outbox-path branches of revokeRequest.
     *
     * @return array{0: bool, 1: string|null}  [roleRemoved, zitadelError]
     */
    private function syncZitadelRoleRevoke(
        AccessRequestRepository $repo,
        string $requestId,
        string $userId,
        string $requestedRole,
    ): array {
        $roleRemoved  = false;
        $zitadelError = null;

        if (ZitadelService::isConfigured()) {
            if ($userId === '' || $requestedRole === '') {
                $zitadelError = 'Missing user ID or role in revoked request';
                $repo->updateZitadelSyncStatus($requestId, 'failed', $zitadelError);
            } else {
                $repo->updateZitadelSyncStatus($requestId, 'pending');

                try {
                    $cascade     = RoleCascadeService::fromEnv();
                    $roleRemoved = $cascade->maybeCascadeRoleRevoke($userId, $requestedRole);

                    $repo->updateZitadelSyncStatus($requestId, 'synced');
                } catch (\Exception $e) {
                    $zitadelError = $e->getMessage();
                    $repo->updateZitadelSyncStatus($requestId, 'failed', $zitadelError);
                }
            }
        }

        return [$roleRemoved, $zitadelError];
    }

    /**
     * Build the human-readable revocation message, incorporating outbox deferral/failure notes.
     */
    private function revocationMessage(
        bool $roleRemoved,
        ?string $zitadelError,
        int $outboxPending,
        int $outboxFailed,
    ): string {
        $base = $roleRemoved
            ? 'Access revoked, role removed (no remaining permissions in scope) and permissions deleted'
            : ( $zitadelError !== null
                ? 'Access revoked but Zitadel sync failed (will retry)'
                : ( ZitadelService::isConfigured()
                    ? 'Access revoked, permissions deleted; role retained (other in-scope permissions remain)'
                    : 'Access revoked (Zitadel not configured)' ) );

        if ($outboxPending > 0 && $outboxFailed > 0) {
            return sprintf(
                '%s; %d permission tuple(s) deferred for async deletion, %d failed terminally (check outbox)',
                $base,
                $outboxPending,
                $outboxFailed,
            );
        }

        if ($outboxPending > 0) {
            return sprintf(
                '%s; %d permission tuple(s) deferred for async deletion',
                $base,
                $outboxPending,
            );
        }

        if ($outboxFailed > 0) {
            return sprintf(
                '%s; %d permission tuple(s) failed terminally (check outbox)',
                $base,
                $outboxFailed,
            );
        }

        return $base;
    }

    /**
     * Check that the admin has authority over ALL resources in the permissions array.
     *
     * Global admins always pass. Resource admins must have the 'admin' relation
     * on every resource referenced in the permissions.
     *
     * @param string $adminId Admin's Zitadel user ID
     * @param bool $isGlobalAdmin Whether the admin has global admin role
     * @param array<int, array{object_type: string, object_id: string, relation: string}> $permissions
     * @throws ForbiddenException If the admin lacks authority over any resource
     */
    private function requireAdminForAllResources(
        string $adminId,
        bool $isGlobalAdmin,
        array $permissions
    ): void {
        if ($isGlobalAdmin) {
            return;
        }

        if (!$this->isFgaClientAvailable()) {
            throw new ForbiddenException('Admin role required');
        }

        $fgaUser = "user:{$adminId}";

        // Cache admin checks to avoid redundant API calls
        /** @var array<string, bool> $cache */
        $cache = [];

        foreach ($permissions as $perm) {
            $objectType = $perm['object_type'] ?? '';
            $objectId   = $perm['object_id'] ?? '';
            $key        = "{$objectType}:{$objectId}";

            if (!isset($cache[$key])) {
                $cache[$key] = $this->getFgaClient()->check($fgaUser, 'admin', $key);
            }

            if (!$cache[$key]) {
                throw new ForbiddenException(
                    sprintf('No admin permission for %s', $key)
                );
            }
        }
    }

    /**
     * Filter requests to only those the resource admin can manage.
     *
     * A resource admin can manage a request only if they have the 'admin'
     * relation on ALL resources in that request's permissions array.
     *
     * @param array<int, array<string, mixed>> $requests All requests
     * @param string $adminId Admin's Zitadel user ID
     * @return array<int, array<string, mixed>> Filtered requests
     */
    private function filterByAdminAccess(array $requests, string $adminId): array
    {
        if (!$this->isFgaClientAvailable()) {
            return [];
        }

        $fgaUser = "user:{$adminId}";

        // Cache admin checks to avoid redundant API calls
        /** @var array<string, bool> $cache */
        $cache = [];

        return array_values(array_filter($requests, function (array $req) use ($fgaUser, &$cache): bool {
            /** @var array<int, array{object_type: string, object_id: string, relation: string}> $permissions */
            $permissions = is_array($req['permissions'] ?? null) ? $req['permissions'] : [];

            if (empty($permissions)) {
                return false;
            }

            // Admin must have access to ALL resources in the request
            foreach ($permissions as $perm) {
                $objectType = $perm['object_type'] ?? '';
                $objectId   = $perm['object_id'] ?? '';
                $key        = "{$objectType}:{$objectId}";

                if (!isset($cache[$key])) {
                    $cache[$key] = $this->getFgaClient()->check($fgaUser, 'admin', $key);
                }

                if (!$cache[$key]) {
                    return false;
                }
            }

            return true;
        }));
    }
}
