<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Admin;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
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
use Psr\Log\LoggerInterface;

/**
 * Permission Admin Handler — OpenFGA tuple management.
 *
 * Provides endpoints for managing fine-grained permissions:
 *
 * - GET    /admin/permissions              — List tuples (optional filters)
 * - POST   /admin/permissions              — Grant permission (create tuple)
 * - DELETE /admin/permissions              — Revoke permission (delete tuple)
 * - GET    /admin/permissions/check        — Check a specific permission
 *
 * Access control:
 * - Global admins (Zitadel "admin" role) can manage all resources
 * - Resource admins (OpenFGA "admin" relation on a resource) can manage
 *   permissions for that specific resource only
 */
final class PermissionAdminHandler extends AbstractHandler
{
    /**
     * Valid OpenFGA object types that can be managed.
     *
     * @var array<string>
     */
    private const VALID_OBJECT_TYPES = [
        'national_calendar',
        'diocesan_calendar',
        'wider_region',
        'test_definition',
    ];

    /**
     * Valid OpenFGA relations.
     *
     * @var array<string>
     */
    private const VALID_RELATIONS = ['admin', 'viewer', 'editor', 'deleter'];

    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT     = 500;

    private ?OpenFgaClient $fgaClient           = null;
    private ?OutboxRepository $outboxRepository = null;
    private ?OutboxNotifier $outboxNotifier     = null;
    private ?OutboxProcessor $outboxProcessor   = null;
    private LoggerInterface $logger;

    public function __construct(?OpenFgaClient $client = null)
    {
        parent::__construct();

        // Pre-seed the lazy client slot so tests can inject a mock.
        // When null (Router path: `new PermissionAdminHandler()`), getClient()
        // falls back to OpenFgaClient::fromEnv() on first use — existing
        // behavior unchanged.
        $this->fgaClient = $client;

        $this->allowedRequestMethods      = [RequestMethod::GET, RequestMethod::POST, RequestMethod::DELETE];
        $this->allowedAcceptHeaders       = [AcceptHeader::JSON];
        $this->allowedRequestContentTypes = [RequestContentType::JSON];
        $this->allowCredentials           = true;
        $this->logger                     = LoggerFactory::create('admin', null, 30, false, true, false);
    }

    private function getClient(): OpenFgaClient
    {
        if ($this->fgaClient === null) {
            $this->fgaClient = OpenFgaClient::fromEnv();
        }
        return $this->fgaClient;
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
            $this->outboxProcessor = new OutboxProcessor(
                $this->getOutboxRepository(),
                $this->getClient(),
                (int) ( $_ENV['OUTBOX_MAX_ATTEMPTS'] ?? 10 ),
            );
        }
        return $this->outboxProcessor;
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

        // Check authentication
        /** @var array{sub?: string, roles?: array<string>}|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');

        if ($oidcUser === null) {
            throw new UnauthorizedException('Authentication required');
        }

        $userId = $oidcUser['sub'] ?? null;
        if (!is_string($userId) || trim($userId) === '') {
            throw new UnauthorizedException('Invalid authentication token');
        }

        $isGlobalAdmin = OidcAuthMiddleware::isAdmin($oidcUser);

        // Determine sub-route: /admin/permissions or /admin/permissions/check
        $path      = $request->getUri()->getPath();
        $pathParts = explode('/', trim($path, '/'));
        $lastPart  = end($pathParts);

        if ($method === RequestMethod::GET && $lastPart === 'check') {
            return $this->checkPermission($request, $response, $userId, $isGlobalAdmin);
        }

        if ($method === RequestMethod::GET) {
            return $this->listPermissions($request, $response, $userId, $isGlobalAdmin);
        }

        if ($method === RequestMethod::POST) {
            return $this->grantPermission($request, $response, $userId, $isGlobalAdmin);
        }

        // DELETE
        return $this->revokePermission($request, $response, $userId, $isGlobalAdmin);
    }

    /**
     * Check if the current user can manage permissions on a specific resource.
     *
     * A user can manage permissions if they are a global admin (Zitadel role)
     * or a resource admin (OpenFGA "admin" relation on the resource).
     *
     * @param string $userId The current user's Zitadel ID
     * @param bool $isGlobalAdmin Whether the user has the Zitadel admin role
     * @param string $objectType The OpenFGA object type
     * @param string $objectId The resource ID
     * @throws ForbiddenException If the user cannot manage this resource
     */
    private function requireResourceAdmin(
        string $userId,
        bool $isGlobalAdmin,
        string $objectType,
        string $objectId
    ): void {
        if ($isGlobalAdmin) {
            return;
        }

        // Check if user has the "admin" relation on this specific resource
        $fgaUser   = "user:{$userId}";
        $fgaObject = "{$objectType}:{$objectId}";

        $isResourceAdmin = $this->getClient()->check($fgaUser, 'admin', $fgaObject);

        if (!$isResourceAdmin) {
            throw new ForbiddenException(
                sprintf('No admin permission for %s', $fgaObject)
            );
        }
    }

    /**
     * Parse the `limit` query param: returns DEFAULT_LIMIT when absent/empty,
     * throws ValidationException when present but invalid or out of range.
     */
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
            throw new ValidationException(sprintf(
                'limit must be between 1 and %d',
                self::MAX_LIMIT
            ));
        }
        return $limit;
    }

    /**
     * Parse the `page_token` query param: returns the string (possibly empty)
     * when absent/string, throws ValidationException when present as a non-string
     * (matches the strictness applied to `limit`). Empty string downstream is
     * treated as "no token / first page".
     */
    private function parsePageToken(mixed $raw): string
    {
        if ($raw === null) {
            return '';
        }
        if (!is_string($raw)) {
            throw new ValidationException('page_token must be a string');
        }
        return $raw;
    }

    /**
     * GET /admin/permissions — List relationship tuples with cursor pagination.
     *
     * Global admins see all tuples. Resource admins see only tuples
     * for resources they administer (filterByAdminAccess is applied
     * post-fetch when object_id is unset).
     *
     * Query parameters:
     *   - user, object_type, object_id, relation: filters (existing)
     *   - limit: max items in this page (1..500, default 100)
     *   - page_token: opaque cursor from a previous response's
     *     `next_page_token`; empty/omitted means first page
     *
     * Note: when filterByAdminAccess is applied, the page returned may be
     * smaller than `limit` (some OpenFGA tuples in the page are filtered
     * out). `has_more` continues to reflect OpenFGA's pagination state, so
     * clients should keep paging until `has_more` is false even if a page
     * comes back smaller than expected.
     */
    private function listPermissions(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $userId,
        bool $isGlobalAdmin
    ): ResponseInterface {
        $params     = $request->getQueryParams();
        $user       = is_string($params['user'] ?? null) ? $params['user'] : '';
        $objectType = is_string($params['object_type'] ?? null) ? $params['object_type'] : '';
        $objectId   = is_string($params['object_id'] ?? null) ? $params['object_id'] : '';
        $relation   = is_string($params['relation'] ?? null) ? $params['relation'] : '';
        $limit      = $this->parseLimit($params['limit'] ?? null);
        $pageToken  = $this->parsePageToken($params['page_token'] ?? null);

        if (!$isGlobalAdmin && $objectType === '') {
            throw new ValidationException('Resource admins must specify object_type filter');
        }

        if ($objectType !== '' && !in_array($objectType, self::VALID_OBJECT_TYPES, true)) {
            throw new ValidationException(
                sprintf('Invalid object_type. Valid types: %s', implode(', ', self::VALID_OBJECT_TYPES))
            );
        }

        if ($relation !== '' && !in_array($relation, self::VALID_RELATIONS, true)) {
            throw new ValidationException(
                sprintf('Invalid relation. Valid relations: %s', implode(', ', self::VALID_RELATIONS))
            );
        }

        if (!$isGlobalAdmin && $objectId !== '') {
            $this->requireResourceAdmin($userId, false, $objectType, $objectId);
        }

        $normalizedUser = $user !== '' ? $this->normalizeUser($user) : '';
        $relationFilter = $relation !== '' ? $relation : null;
        $objectFilter   = $objectType !== ''
            ? ( $objectId !== '' ? "{$objectType}:{$objectId}" : "{$objectType}:" )
            : '';

        $page = $this->getClient()->readTuples(
            $normalizedUser,
            $objectFilter,
            $relationFilter,
            $limit,
            $pageToken === '' ? null : $pageToken
        );

        /** @var list<array{user: string, relation: string, object: string}> $tuples */
        $tuples    = $page['tuples'];
        $nextToken = $page['next_continuation_token'];

        // Preserve the existing post-filter for resource admins listing without
        // a specific object_id. May reduce this page's item count below `limit`.
        if (!$isGlobalAdmin && $objectType !== '' && $objectId === '') {
            $tuples = $this->filterByAdminAccess($tuples, $userId, $objectType);
        }

        $hasMore = $nextToken !== '';

        return $this->encodeResponseBody($response, [
            'permissions'     => $tuples,
            'count'           => count($tuples),
            'has_more'        => $hasMore,
            'next_page_token' => $hasMore ? $nextToken : null,
        ]);
    }

    /**
     * POST /admin/permissions — Grant a permission (create tuple).
     *
     * Request body:
     *   - user: string (required) — Zitadel user ID (with or without "user:" prefix)
     *   - object_type: string (required) — e.g., "national_calendar"
     *   - object_id: string (required) — e.g., "IT"
     *   - relation: string (required) — "admin", "viewer", "editor", or "deleter"
     *
     * Flow (outbox pattern):
     * 1. Validate and authorize
     * 2. PG BEGIN
     *    - outbox.insertBatch([row]) — one row, idempotency_key ensures
     *      re-granting the same tuple collapses to the same outbox row ID
     * 3. PG COMMIT
     * 4. processor.processSync(), then notifier.notify() when row is still non-terminal
     * 5. Return response with success=true, plus outbox counters
     *
     * `success` is true whenever the DB commit succeeded. Tuple delivery
     * failures surface via outbox_pending / outbox_failed rather than
     * aborting the grant.
     */
    private function grantPermission(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $userId,
        bool $isGlobalAdmin
    ): ResponseInterface {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ValidationException('Request body must be JSON');
        }

        $user       = is_string($body['user'] ?? null) ? $body['user'] : '';
        $objectType = is_string($body['object_type'] ?? null) ? $body['object_type'] : '';
        $objectId   = is_string($body['object_id'] ?? null) ? $body['object_id'] : '';
        $relation   = is_string($body['relation'] ?? null) ? $body['relation'] : '';

        $this->validateTupleParams($user, $objectType, $objectId, $relation);
        $this->requireResourceAdmin($userId, $isGlobalAdmin, $objectType, $objectId);

        $fgaUser   = $this->normalizeUser($user);
        $fgaObject = "{$objectType}:{$objectId}";

        // Idempotency key: scoped to the admin + the specific tuple, so
        // re-granting the same permission collapses to the same outbox row ID.
        $idempotencyKey = "permission_grant:{$userId}:write_tuple:{$fgaUser}:{$relation}:{$fgaObject}";

        $outboxRow = [
            'operation'       => OutboxOperation::WRITE_TUPLE,
            'fga_user'        => $fgaUser,
            'fga_relation'    => $relation,
            'fga_object'      => $fgaObject,
            'idempotency_key' => $idempotencyKey,
            'metadata'        => ['admin_user' => "user:{$userId}"],
        ];

        // Atomically insert the outbox row in a PG transaction for durability
        // before any sync fast-path is attempted.
        $pdo    = Connection::getInstance();
        $outbox = $this->getOutboxRepository();

        $pdo->beginTransaction();
        try {
            $outboxIds = $outbox->insertBatch([$outboxRow]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Sync fast path — attempt the outbox row immediately.
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
                $createdTuples[] = [
                    'user'     => $current !== null ? $current->fgaUser     : $fgaUser,
                    'relation' => $current !== null ? $current->fgaRelation : '',
                    'object'   => $current !== null ? $current->fgaObject   : '',
                ];
            } else {
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

        return $this->encodeResponseBody($response, [
            'success'        => true,
            'message'        => 'Permission granted',
            'user'           => $fgaUser,
            'relation'       => $relation,
            'object'         => $fgaObject,
            'tuples_created' => $createdTuples,
            'fga_errors'     => $fgaErrors,
            'outbox_ids'     => $outboxIds,
            'outbox_pending' => $outboxPending,
            'outbox_failed'  => $outboxFailed,
        ]);
    }

    /**
     * DELETE /admin/permissions — Revoke a permission (delete tuple).
     *
     * Request body:
     *   - user: string (required)
     *   - object_type: string (required)
     *   - object_id: string (required)
     *   - relation: string (required)
     *
     * Flow (outbox pattern, mirrors grantPermission):
     * 1. Validate and authorize
     * 2. PG BEGIN
     *    - outbox.insertBatch([row]) — one row, idempotency_key ensures
     *      re-revoking the same tuple collapses to the same outbox row ID
     * 3. PG COMMIT
     * 4. processor.processSync(), then notifier.notify() when row is still non-terminal
     * 5. DB sync and role cascade (non-fatal post-commit work)
     * 6. Return response with success=true, plus outbox counters
     *
     * `success` is true whenever the DB commit succeeded. Tuple deletion
     * failures surface via outbox_pending / outbox_failed rather than
     * aborting the revoke.
     */
    private function revokePermission(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $userId,
        bool $isGlobalAdmin
    ): ResponseInterface {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ValidationException('Request body must be JSON');
        }

        $user       = is_string($body['user'] ?? null) ? $body['user'] : '';
        $objectType = is_string($body['object_type'] ?? null) ? $body['object_type'] : '';
        $objectId   = is_string($body['object_id'] ?? null) ? $body['object_id'] : '';
        $relation   = is_string($body['relation'] ?? null) ? $body['relation'] : '';

        $this->validateTupleParams($user, $objectType, $objectId, $relation);
        $this->requireResourceAdmin($userId, $isGlobalAdmin, $objectType, $objectId);

        $fgaUser   = $this->normalizeUser($user);
        $fgaObject = "{$objectType}:{$objectId}";

        // Idempotency key: scoped to the admin + the specific tuple, so
        // re-revoking the same permission collapses to the same outbox row ID.
        $idempotencyKey = "permission_revoke:{$userId}:delete_tuple:{$fgaUser}:{$relation}:{$fgaObject}";

        $outboxRow = [
            'operation'       => OutboxOperation::DELETE_TUPLE,
            'fga_user'        => $fgaUser,
            'fga_relation'    => $relation,
            'fga_object'      => $fgaObject,
            'idempotency_key' => $idempotencyKey,
            'metadata'        => ['admin_user' => "user:{$userId}"],
        ];

        // Atomically insert the outbox row in a PG transaction for durability
        // before any sync fast-path is attempted.
        $pdo    = Connection::getInstance();
        $outbox = $this->getOutboxRepository();

        $pdo->beginTransaction();
        try {
            $outboxIds = $outbox->insertBatch([$outboxRow]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Sync fast path — attempt the outbox row immediately.
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
                $deletedTuples[] = [
                    'user'     => $current !== null ? $current->fgaUser     : $fgaUser,
                    'relation' => $current !== null ? $current->fgaRelation : '',
                    'object'   => $current !== null ? $current->fgaObject   : '',
                ];
            } else {
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

        // Keep access_requests DB in sync: remove this permission from
        // any approved access request for this user. This is a best-effort
        // post-commit sync; failures here are non-fatal.
        $bareUserId = str_starts_with($fgaUser, 'user:')
            ? substr($fgaUser, 5)
            : $fgaUser;

        if (Connection::isConfigured()) {
            $repo = new AccessRequestRepository();
            $repo->removePermissionTuple($bareUserId, $objectType, $objectId, $relation);
        }

        // Cascade: if this delete leaves any of the user's currently-held roles
        // with zero in-scope tuples, revoke the role too. Only check roles whose
        // scope includes the deleted tuple's object_type — others can't have
        // been affected. Failures here are non-fatal: the outbox row already
        // committed.
        $cascadedRoles = [];
        if (ZitadelService::isConfigured() && OpenFgaClient::isConfigured()) {
            try {
                $cascade   = RoleCascadeService::fromEnv();
                $userRoles = ZitadelService::fromEnv()->getUserRoles($bareUserId);
                foreach ($userRoles as $role) {
                    $allowedTypes = AccessRequestRepository::ROLE_OBJECT_TYPES[$role] ?? [];
                    if (!in_array($objectType, $allowedTypes, true)) {
                        continue;
                    }
                    if ($cascade->maybeCascadeRoleRevoke($bareUserId, $role)) {
                        $cascadedRoles[] = $role;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('PermissionAdminHandler cascade check failed', ['exception' => $e]);
            }
        }

        $message = empty($cascadedRoles)
            ? 'Permission revoked'
            : 'Permission revoked; cascaded role(s) revoked: ' . implode(', ', $cascadedRoles);

        return $this->encodeResponseBody($response, [
            'success'        => true,
            'message'        => $message,
            'user'           => $fgaUser,
            'relation'       => $relation,
            'object'         => $fgaObject,
            'cascaded_roles' => $cascadedRoles,
            'tuples_deleted' => $deletedTuples,
            'fga_errors'     => $fgaErrors,
            'outbox_ids'     => $outboxIds,
            'outbox_pending' => $outboxPending,
            'outbox_failed'  => $outboxFailed,
        ]);
    }

    /**
     * GET /admin/permissions/check — Check if a user has a specific permission.
     *
     * Query parameters:
     *   - user: string (required)
     *   - object_type: string (required)
     *   - object_id: string (required)
     *   - relation: string (required)
     */
    private function checkPermission(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $userId,
        bool $isGlobalAdmin
    ): ResponseInterface {
        $params     = $request->getQueryParams();
        $user       = is_string($params['user'] ?? null) ? $params['user'] : '';
        $objectType = is_string($params['object_type'] ?? null) ? $params['object_type'] : '';
        $objectId   = is_string($params['object_id'] ?? null) ? $params['object_id'] : '';
        $relation   = is_string($params['relation'] ?? null) ? $params['relation'] : '';

        $this->validateTupleParams($user, $objectType, $objectId, $relation);
        $this->requireResourceAdmin($userId, $isGlobalAdmin, $objectType, $objectId);

        $fgaUser   = $this->normalizeUser($user);
        $fgaObject = "{$objectType}:{$objectId}";

        $allowed = $this->getClient()->check($fgaUser, $relation, $fgaObject);

        return $this->encodeResponseBody($response, [
            'allowed'  => $allowed,
            'user'     => $fgaUser,
            'relation' => $relation,
            'object'   => $fgaObject,
        ]);
    }

    /**
     * Filter tuples to only those on resources the user administers.
     *
     * Resolves the admin's allowed-object set via OpenFGA's `ListObjects`
     * in one round-trip — issue #571's fix for the previous N+1 `check()`
     * loop, one call per unique `object_id` in the page. The trade-off is
     * payload size: `ListObjects` returns every object of `objectType` the
     * user has the `admin` relation on, which may exceed the N referenced
     * in this page. In practice the admin's administered set is bounded by
     * their actual responsibilities, while the tuple page size grows with
     * traffic; one HTTP call per page comfortably wins on cold caches.
     *
     * @param array<int, array{user: string, relation: string, object: string}> $tuples All tuples
     * @param string $userId The current user's Zitadel ID
     * @param string $objectType The object type being listed
     * @return array<int, array{user: string, relation: string, object: string}> Filtered tuples
     */
    private function filterByAdminAccess(array $tuples, string $userId, string $objectType): array
    {
        $allowedIds = array_flip(
            $this->getClient()->listObjects("user:{$userId}", 'admin', $objectType)
        );

        return array_values(array_filter($tuples, function (array $tuple) use ($allowedIds): bool {
            $parts = explode(':', $tuple['object'], 2);
            $objId = $parts[1] ?? '';
            return isset($allowedIds[$objId]);
        }));
    }

    /**
     * Validate tuple parameters.
     *
     * @throws ValidationException If any parameter is invalid
     */
    private function validateTupleParams(string $user, string $objectType, string $objectId, string $relation): void
    {
        // Trim and reject empty / whitespace-only user. Also catches "user:" with empty subject after the prefix.
        $trimmed = trim($user);
        if ($trimmed === '') {
            throw new ValidationException('Missing required parameter: user');
        }
        $subject = str_starts_with($trimmed, 'user:') ? substr($trimmed, 5) : $trimmed;
        if (trim($subject) === '') {
            throw new ValidationException('Invalid user: subject id must not be empty');
        }

        if ($objectType === '') {
            throw new ValidationException('Missing required parameter: object_type');
        }

        if (!in_array($objectType, self::VALID_OBJECT_TYPES, true)) {
            throw new ValidationException(
                sprintf('Invalid object_type "%s". Valid types: %s', $objectType, implode(', ', self::VALID_OBJECT_TYPES))
            );
        }

        if ($objectId === '') {
            throw new ValidationException('Missing required parameter: object_id');
        }

        if ($relation === '') {
            throw new ValidationException('Missing required parameter: relation');
        }

        if (!in_array($relation, self::VALID_RELATIONS, true)) {
            throw new ValidationException(
                sprintf('Invalid relation "%s". Valid relations: %s', $relation, implode(', ', self::VALID_RELATIONS))
            );
        }
    }

    /**
     * Normalize user identifier to OpenFGA format.
     *
     * Accepts either a bare Zitadel user ID or a prefixed "user:id" format.
     */
    private function normalizeUser(string $user): string
    {
        if (!str_starts_with($user, 'user:')) {
            return "user:{$user}";
        }
        return $user;
    }
}
