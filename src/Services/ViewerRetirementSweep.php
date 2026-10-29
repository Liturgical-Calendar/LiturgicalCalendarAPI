<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Repositories\AuditLogRepository;
use LiturgicalCalendar\Api\Repositories\OutboxBatchInsertInterface;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\Exception\OpenFgaApiException;
use LiturgicalCalendar\Api\Services\Outbox\OutboxFallbackTupleDeleter;
use LiturgicalCalendar\Api\Services\Outbox\OutboxNotifier;
use Psr\Log\LoggerInterface;

/**
 * Retires the `viewer` relation for people (API #1049).
 *
 * People now read admin data through the role they hold, so the `viewer` tuples they were granted no longer do
 * anything. This sweep cleans up what is left:
 *
 *   1. deletes every `viewer` tuple held by a `user:` subject (a failed delete goes to the outbox);
 *   2. strips `viewer` from pending access requests, rejecting a request that ends up empty;
 *   3. revokes a scoped role that was held only through `viewer`, via
 *      {@see RoleCascadeService::maybeCascadeRoleRevoke()}, which re-checks before it revokes.
 *
 * {@see plan()} makes no writes; {@see apply()} carries a plan out and writes one audit entry per change. A second
 * plan() after apply() is empty.
 *
 * @phpstan-import-type Permission from ViewerRetirementPlan
 * @phpstan-import-type PendingRequestChange from ViewerRetirementPlan
 * @phpstan-import-type RoleRevocation from ViewerRetirementPlan
 * @phpstan-type ApplyCounts array{
 *     tuples_deleted: int,
 *     tuples_already_gone: int,
 *     tuples_enqueued: int,
 *     tuples_failed: int,
 *     requests_rewritten: int,
 *     requests_rejected: int,
 *     requests_skipped: int,
 *     roles_revoked: int,
 *     roles_kept: int,
 *     roles_failed: int
 * }
 */
final class ViewerRetirementSweep
{
    public const string REVIEWER_ID = 'system:viewer-retirement';

    public const string REJECTION_NOTE = 'Read access now comes with the role. Please request editor or admin access on the calendars you want to work on.';

    private const int READ_PAGE_SIZE = 100;

    public function __construct(
        private readonly OpenFgaClient $fga,
        private readonly ZitadelService $zitadel,
        private readonly AccessRequestRepository $requests,
        private readonly RoleCascadeService $roleCascade,
        private readonly AuditLogRepository $auditLog,
        private readonly ?OutboxBatchInsertInterface $outbox = null,
        private readonly ?OutboxNotifier $outboxNotifier = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Build a sweep from environment-configured collaborators, the way {@see RoleCascadeService::fromEnv()} does.
     */
    public static function fromEnv(?LoggerInterface $logger = null): self
    {
        $redis      = RedisConnection::bestEffort($logger);
        $streamName = is_string($_ENV['REDIS_OUTBOX_STREAM'] ?? null) ? $_ENV['REDIS_OUTBOX_STREAM'] : 'litcal:reconcile-stream';

        return new self(
            OpenFgaClient::fromEnv(),
            ZitadelService::fromEnv($logger),
            new AccessRequestRepository(),
            RoleCascadeService::fromEnv($logger),
            new AuditLogRepository(),
            new OutboxRepository(Connection::getInstance()),
            new OutboxNotifier($redis, $streamName, $logger),
            $logger,
        );
    }

    /**
     * Work out what apply() would change. Reads OpenFGA, the pending requests and Zitadel roles; writes nothing.
     */
    public function plan(): ViewerRetirementPlan
    {
        $viewerTuples = $this->readPeopleViewerTuples();

        /** @var array<string, list<string>> $viewerObjectsByUser bare user id => viewer objects being deleted */
        $viewerObjectsByUser = [];
        foreach ($viewerTuples as $tuple) {
            $viewerObjectsByUser[substr($tuple['user'], strlen('user:'))][] = $tuple['object'];
        }

        $roleRevocations = [];
        foreach ($viewerObjectsByUser as $userId => $viewerObjects) {
            foreach ($this->rolesHeldOnlyThroughViewer((string) $userId, $viewerObjects) as $role) {
                $roleRevocations[] = ['user' => (string) $userId, 'role' => $role];
            }
        }

        return new ViewerRetirementPlan($viewerTuples, $this->pendingRequestChanges(), $roleRevocations);
    }

    /**
     * Carry out a plan: delete the tuples, then rewrite or reject the requests, then cascade the roles.
     *
     * @return ApplyCounts
     */
    public function apply(ViewerRetirementPlan $plan): array
    {
        $counts = [
            'tuples_deleted'      => 0,
            'tuples_already_gone' => 0,
            'tuples_enqueued'     => 0,
            'tuples_failed'       => 0,
            'requests_rewritten'  => 0,
            'requests_rejected'   => 0,
            'requests_skipped'    => 0,
            'roles_revoked'       => 0,
            'roles_kept'          => 0,
            'roles_failed'        => 0,
        ];

        // Unique per run: an outbox row left by an earlier run (succeeded or failed terminally) must not suppress,
        // through ON CONFLICT DO NOTHING, the retry of a delete that failed again in this run.
        $run     = bin2hex(random_bytes(8));
        $deleter = new OutboxFallbackTupleDeleter($this->fga, $this->outbox, $this->outboxNotifier);

        foreach ($plan->viewerTuples as $tuple) {
            $key    = "viewer_retirement:{$run}:delete_tuple:{$tuple['user']}:viewer:{$tuple['object']}";
            $result = $deleter->delete($tuple['user'], 'viewer', $tuple['object'], $key, ['viewer_retirement' => true]);

            switch ($result['outcome']) {
                case 'deleted':
                    $counts['tuples_deleted']++;
                    break;
                case 'already_gone':
                    $counts['tuples_already_gone']++;
                    continue 2; // nothing changed, nothing to audit
                case 'enqueued':
                    $counts['tuples_enqueued']++;
                    break;
                case 'failed':
                    $counts['tuples_failed']++;
                    break;
            }

            if ($result['outcome'] !== 'deleted') {
                $e = $result['error'];
                $this->logger?->warning('ViewerRetirementSweep: viewer tuple delete failed', [
                    'user'           => $tuple['user'],
                    'object'         => $tuple['object'],
                    'outcome'        => $result['outcome'],
                    'outbox_row_ids' => $result['outbox_row_ids'],
                    'error_code'     => $e instanceof OpenFgaApiException ? $e->getErrorCode() : null,
                    'error'          => $e?->getMessage(),
                ]);
                if ($result['outcome'] === 'failed') {
                    continue; // not changed and not queued
                }
            }

            $this->audit('viewer_retirement_delete_tuple', 'openfga_tuple', $tuple['object'], [
                'user'           => $tuple['user'],
                'relation'       => 'viewer',
                'object'         => $tuple['object'],
                'outcome'        => $result['outcome'],
                'outbox_row_ids' => $result['outbox_row_ids'],
            ]);
        }

        foreach ($plan->pendingRequests as $request) {
            $details = ['user' => $request['user'], 'removed' => $request['removed'], 'keep' => $request['keep']];

            if ($request['action'] === 'rewrite') {
                if ($this->requests->replacePendingPermissions($request['id'], $request['keep'])) {
                    $counts['requests_rewritten']++;
                    $this->audit('viewer_retirement_rewrite_request', 'access_request', $request['id'], $details);
                } else {
                    $counts['requests_skipped']++; // no longer pending
                }
                continue;
            }

            if ($this->requests->reject($request['id'], self::REVIEWER_ID, self::REJECTION_NOTE)) {
                $counts['requests_rejected']++;
                $this->audit('viewer_retirement_reject_request', 'access_request', $request['id'], $details);
            } else {
                $counts['requests_skipped']++;
            }
        }

        foreach ($plan->roleRevocations as $revocation) {
            try {
                $revoked = $this->roleCascade->maybeCascadeRoleRevoke($revocation['user'], $revocation['role']);
            } catch (\Throwable $e) {
                $counts['roles_failed']++;
                $this->logger?->error('ViewerRetirementSweep: role cascade failed', [
                    'user_id' => $revocation['user'],
                    'role'    => $revocation['role'],
                    'error'   => $e->getMessage(),
                ]);
                continue;
            }

            if (!$revoked) {
                $counts['roles_kept']++; // the cascade's own re-check found something that justifies the role
                continue;
            }

            $counts['roles_revoked']++;
            $this->audit('viewer_retirement_revoke_role', 'role', $revocation['user'], ['role' => $revocation['role']]);
        }

        return $counts;
    }

    /**
     * Every `viewer` tuple on a `user:` subject.
     *
     * Reads the whole store: OpenFGA Read rejects a tuple key with a relation but no object, and a type-only object
     * needs a user, so no server-side filter can select "all viewer tuples". The `user:*` wildcard is public access,
     * not a person, and is left alone.
     *
     * @return list<array{user: string, object: string}>
     */
    private function readPeopleViewerTuples(): array
    {
        $viewers = [];
        $token   = null;
        do {
            $page = $this->fga->readTuples('', '', null, self::READ_PAGE_SIZE, $token);
            foreach ($page['tuples'] as $tuple) {
                if (
                    $tuple['relation'] === 'viewer'
                    && str_starts_with($tuple['user'], 'user:')
                    && $tuple['user'] !== 'user:*'
                ) {
                    $viewers[] = ['user' => $tuple['user'], 'object' => $tuple['object']];
                }
            }
            $token = $page['next_continuation_token'] !== '' ? $page['next_continuation_token'] : null;
        } while ($token !== null);

        return $viewers;
    }

    /**
     * Pending requests that name `viewer`, with what is left once those entries go.
     *
     * @return list<PendingRequestChange>
     */
    private function pendingRequestChanges(): array
    {
        $changes = [];
        foreach ($this->requests->getPending() as $row) {
            $permissions = is_array($row['permissions'] ?? null) ? $row['permissions'] : [];
            $keep        = [];
            $removed     = [];
            foreach ($permissions as $permission) {
                if (
                    !is_array($permission)
                    || !is_string($permission['object_type'] ?? null)
                    || !is_string($permission['object_id'] ?? null)
                    || !is_string($permission['relation'] ?? null)
                ) {
                    continue;
                }
                $normalised = [
                    'object_type' => $permission['object_type'],
                    'object_id'   => $permission['object_id'],
                    'relation'    => $permission['relation'],
                ];
                if ($normalised['relation'] === 'viewer') {
                    $removed[] = $normalised;
                } else {
                    $keep[] = $normalised;
                }
            }

            if ($removed === []) {
                continue;
            }

            $id        = $row['id'] ?? null;
            $user      = $row['zitadel_user_id'] ?? null;
            $changes[] = [
                'id'      => is_string($id) ? $id : '',
                'user'    => is_string($user) ? $user : '',
                'keep'    => $keep,
                'removed' => $removed,
                'action'  => $keep === [] ? 'reject' : 'rewrite',
            ];
        }

        return $changes;
    }

    /**
     * The scoped roles the user holds only through the `viewer` tuples about to be deleted.
     *
     * A role is a candidate only when one of those tuples is in its scope: a role with no viewer tuple in scope (for
     * instance one assigned directly, before any grant) is not held through viewer and is not the sweep's to touch.
     * A candidate is listed when nothing else in its scope justifies it, counting the relations
     * {@see RoleCascadeService::justifyingRelations()} counts and ignoring the viewer tuples being deleted. That is
     * the check {@see RoleCascadeService::maybeCascadeRoleRevoke()} repeats at apply time.
     *
     * @param list<string> $viewerObjects full objects ("type:id") of the user's viewer tuples
     * @return list<string>
     */
    private function rolesHeldOnlyThroughViewer(string $userId, array $viewerObjects): array
    {
        $fgaUser = "user:{$userId}";
        $roles   = [];
        foreach ($this->zitadel->getUserRoles($userId) as $role) {
            $types = AccessRequestRepository::ROLE_OBJECT_TYPES[$role] ?? [];
            if ($types === []) {
                continue; // not a scoped role (e.g. the global admin role)
            }

            $viewerInScope = array_filter(
                $viewerObjects,
                static fn (string $object): bool => in_array(explode(':', $object, 2)[0], $types, true)
            );
            if ($viewerInScope === []) {
                continue;
            }

            if (!$this->hasJustifyingTuple($fgaUser, $role, $types, $viewerObjects)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /**
     * @param list<string> $types
     * @param list<string> $ignoredViewerObjects
     */
    private function hasJustifyingTuple(string $fgaUser, string $role, array $types, array $ignoredViewerObjects): bool
    {
        foreach ($types as $type) {
            foreach (RoleCascadeService::justifyingRelations($role) as $relation) {
                foreach ($this->fga->listObjects($fgaUser, $relation, $type) as $objectId) {
                    if ($relation === 'viewer' && in_array("{$type}:{$objectId}", $ignoredViewerObjects, true)) {
                        continue;
                    }
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * One audit entry per change. The change has already happened, so a failed audit write is logged, not thrown.
     *
     * @param array<string, mixed> $details
     */
    private function audit(string $action, string $resourceType, string $resourceId, array $details): void
    {
        try {
            $this->auditLog->log(self::REVIEWER_ID, $action, $resourceType, $resourceId, $details);
        } catch (\Throwable $e) {
            $this->logger?->error('ViewerRetirementSweep: audit log write failed', [
                'action'      => $action,
                'resource_id' => $resourceId,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
