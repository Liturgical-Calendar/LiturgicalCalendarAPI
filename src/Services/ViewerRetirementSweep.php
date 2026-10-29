<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Repositories\AuditLogRepository;
use LiturgicalCalendar\Api\Repositories\OutboxBatchInsertInterface;
use LiturgicalCalendar\Api\Repositories\OutboxPendingWritesInterface;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\Exception\OpenFgaApiException;
use LiturgicalCalendar\Api\Services\Exception\ViewerRetirementAbortedException;
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
 *     requests_failed: int,
 *     roles_revoked: int,
 *     roles_kept: int,
 *     roles_deferred: int,
 *     roles_failed: int
 * }
 * @phpstan-type UnrealisedRevocation array{user: string, role: string, outcome: 'kept'|'deferred'|'failed', reason: string}
 * @phpstan-type ApplyResult array{counts: ApplyCounts, unrealised_revocations: list<UnrealisedRevocation>}
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
        private readonly ?OutboxPendingWritesInterface $pendingWrites = null,
    ) {
    }

    /**
     * Build a sweep from environment-configured collaborators, the way {@see RoleCascadeService::fromEnv()} does.
     */
    public static function fromEnv(?LoggerInterface $logger = null): self
    {
        $redis      = RedisConnection::bestEffort($logger);
        $streamName = is_string($_ENV['REDIS_OUTBOX_STREAM'] ?? null) ? $_ENV['REDIS_OUTBOX_STREAM'] : 'litcal:reconcile-stream';
        $outbox     = new OutboxRepository(Connection::getInstance());

        return new self(
            OpenFgaClient::fromEnv(),
            ZitadelService::fromEnv($logger),
            new AccessRequestRepository(),
            RoleCascadeService::fromEnv($logger),
            new AuditLogRepository(),
            $outbox,
            new OutboxNotifier($redis, $streamName, $logger),
            $logger,
            $outbox,
        );
    }

    /**
     * Work out what apply() would change. Reads OpenFGA, the pending requests, Zitadel roles and the outbox; writes
     * nothing.
     *
     * @throws \RuntimeException when a viewer holder's Zitadel roles cannot be read: an unreadable role list must
     *                           not pass for an empty one, since a role it hid would never be planned for revocation
     *                           and, once the viewer tuples are gone, no later run could find the person again
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
        $rolesRead       = [];
        foreach ($viewerObjectsByUser as $userId => $viewerObjects) {
            $userId             = (string) $userId;
            $roles              = $this->zitadel->getUserRolesOrFail($userId);
            $rolesRead[$userId] = $roles;
            foreach ($this->rolesHeldOnlyThroughViewer($userId, $roles, $viewerObjects) as $role) {
                $roleRevocations[] = ['user' => $userId, 'role' => $role];
            }
        }

        return new ViewerRetirementPlan(
            $viewerTuples,
            $this->pendingRequestChanges(),
            $roleRevocations,
            $rolesRead,
            $this->pendingWrites?->listPendingPeopleViewerWrites() ?? [],
        );
    }

    /**
     * Carry out a plan: delete the tuples, then rewrite or reject the requests, then cascade the roles.
     *
     * Every planned role revocation that did not happen is returned in `unrealised_revocations`, with its user, role,
     * outcome and reason, and audited, because no later run can find that person again once their viewer tuples are
     * gone:
     *   - `deferred` — a viewer delete in the role's scope was enqueued or failed, so the tuple still exists and the
     *                  cascade would keep the role; the cascade is not called. Only for a role a viewer tuple can
     *                  justify (developer): the calendar_editor / test_editor cascade deletes leftover viewers itself;
     *   - `kept`     — the cascade's own re-check found something that justifies the role;
     *   - `failed`   — the cascade threw, answered true while Zitadel still lists the role (its revoke swallows
     *                  failures), or Zitadel could not be read back.
     *
     * A tuple or request that throws is counted as failed and the sweep goes on, so the role phase always runs. If
     * anything else throws, the sweep stops with a {@see ViewerRetirementAbortedException} carrying what was done and
     * every planned revocation not carried out.
     *
     * @return ApplyResult
     * @throws ViewerRetirementAbortedException
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
            'requests_failed'     => 0,
            'roles_revoked'       => 0,
            'roles_kept'          => 0,
            'roles_deferred'      => 0,
            'roles_failed'        => 0,
        ];
        /** @var list<UnrealisedRevocation> $unrealised */
        $unrealised = [];
        /** @var array<string, list<string>> $undeletedViewers bare user id => viewer objects still in place */
        $undeletedViewers = [];

        $nextRevocation = 0;
        try {
            // Unique per run: an outbox row left by an earlier run (succeeded or failed terminally) must not suppress,
            // through ON CONFLICT DO NOTHING, the retry of a delete that failed again in this run.
            $run     = bin2hex(random_bytes(8));
            $deleter = new OutboxFallbackTupleDeleter($this->fga, $this->outbox, $this->outboxNotifier);

            foreach ($plan->viewerTuples as $tuple) {
                try {
                    $this->deleteViewerTuple($deleter, $run, $tuple, $counts, $undeletedViewers);
                } catch (\Throwable $e) {
                    $counts['tuples_failed']++;
                    $undeletedViewers[substr($tuple['user'], strlen('user:'))][] = $tuple['object'];
                    $this->logger?->warning('ViewerRetirementSweep: viewer tuple delete threw', [
                        'user'   => $tuple['user'],
                        'object' => $tuple['object'],
                        'error'  => $e->getMessage(),
                    ]);
                }
            }

            foreach ($plan->pendingRequests as $request) {
                try {
                    $this->retirePendingRequest($request, $counts);
                } catch (\Throwable $e) {
                    $counts['requests_failed']++;
                    $this->logger?->warning('ViewerRetirementSweep: pending request change threw', [
                        'request_id' => $request['id'],
                        'action'     => $request['action'],
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            foreach ($plan->roleRevocations as $index => $revocation) {
                $nextRevocation = $index;
                $this->revokeRole($revocation['user'], $revocation['role'], $undeletedViewers, $counts, $unrealised);
                $nextRevocation = $index + 1;
            }
        } catch (\Throwable $e) {
            foreach (array_slice($plan->roleRevocations, $nextRevocation) as $revocation) {
                $unrealised[] = [
                    'user'    => $revocation['user'],
                    'role'    => $revocation['role'],
                    'outcome' => 'failed',
                    'reason'  => 'not carried out: the sweep stopped early (' . $e->getMessage() . ')',
                ];
            }
            throw new ViewerRetirementAbortedException(['counts' => $counts, 'unrealised_revocations' => $unrealised], $e);
        }

        return ['counts' => $counts, 'unrealised_revocations' => $unrealised];
    }

    /**
     * @param array{user: string, object: string} $tuple
     * @param ApplyCounts                          $counts
     * @param array<string, list<string>>          $undeletedViewers
     */
    private function deleteViewerTuple(
        OutboxFallbackTupleDeleter $deleter,
        string $run,
        array $tuple,
        array &$counts,
        array &$undeletedViewers
    ): void {
        $key    = "viewer_retirement:{$run}:delete_tuple:{$tuple['user']}:viewer:{$tuple['object']}";
        $result = $deleter->delete($tuple['user'], 'viewer', $tuple['object'], $key, ['viewer_retirement' => true]);

        switch ($result['outcome']) {
            case 'deleted':
                $counts['tuples_deleted']++;
                break;
            case 'already_gone':
                $counts['tuples_already_gone']++;
                return; // nothing changed, nothing to audit
            case 'enqueued':
                $counts['tuples_enqueued']++;
                break;
            case 'failed':
                $counts['tuples_failed']++;
                break;
        }

        if ($result['outcome'] !== 'deleted') {
            $undeletedViewers[substr($tuple['user'], strlen('user:'))][] = $tuple['object'];
            $e                                                           = $result['error'];
            $this->logger?->warning('ViewerRetirementSweep: viewer tuple delete failed', [
                'user'           => $tuple['user'],
                'object'         => $tuple['object'],
                'outcome'        => $result['outcome'],
                'outbox_row_ids' => $result['outbox_row_ids'],
                'error_code'     => $e instanceof OpenFgaApiException ? $e->getErrorCode() : null,
                'error'          => $e?->getMessage(),
            ]);
            if ($result['outcome'] === 'failed') {
                return; // not changed and not queued
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

    /**
     * @param PendingRequestChange $request
     * @param ApplyCounts          $counts
     */
    private function retirePendingRequest(array $request, array &$counts): void
    {
        $details = ['user' => $request['user'], 'removed' => $request['removed'], 'keep' => $request['keep']];

        if ($request['action'] === 'rewrite') {
            if ($this->requests->replacePendingPermissions($request['id'], $request['keep'])) {
                $counts['requests_rewritten']++;
                $this->audit('viewer_retirement_rewrite_request', 'access_request', $request['id'], $details);
            } else {
                $counts['requests_skipped']++; // no longer pending
            }
            return;
        }

        if ($this->requests->reject($request['id'], self::REVIEWER_ID, self::REJECTION_NOTE)) {
            $counts['requests_rejected']++;
            $this->audit('viewer_retirement_reject_request', 'access_request', $request['id'], $details);
        } else {
            $counts['requests_skipped']++;
        }
    }

    /**
     * @param array<string, list<string>> $undeletedViewers
     * @param ApplyCounts                 $counts
     * @param list<UnrealisedRevocation>  $unrealised
     */
    private function revokeRole(string $user, string $role, array $undeletedViewers, array &$counts, array &$unrealised): void
    {
        $types = AccessRequestRepository::ROLE_OBJECT_TYPES[$role] ?? [];

        // Only a role a viewer tuple can justify (developer) is held up by a viewer delete still pending; for
        // calendar_editor / test_editor the cascade does not count viewer and deletes the leftovers itself.
        if (
            in_array('viewer', RoleCascadeService::justifyingRelations($role), true)
            && self::inScope($undeletedViewers[$user] ?? [], $types) !== []
        ) {
            $counts['roles_deferred']++;
            $unrealised[] = $this->unrealised($user, $role, 'deferred', 'a viewer delete in scope was enqueued or failed');
            return;
        }

        try {
            $revoked = $this->roleCascade->maybeCascadeRoleRevoke($user, $role);
        } catch (\Throwable $e) {
            $counts['roles_failed']++;
            $unrealised[] = $this->unrealised($user, $role, 'failed', 'the cascade threw: ' . $e->getMessage());
            return;
        }

        if (!$revoked) {
            $counts['roles_kept']++;
            $unrealised[] = $this->unrealised($user, $role, 'kept', 'the cascade found a tuple, or a queued write, that justifies the role');
            return;
        }

        // The cascade marks the requests revoked even when Zitadel's revoke failed quietly: check the role is gone,
        // with a read that cannot mistake a failure for "no roles".
        try {
            $stillHeld = in_array($role, $this->zitadel->getUserRolesOrFail($user), true);
        } catch (\Throwable $e) {
            $counts['roles_failed']++;
            $unrealised[] = $this->unrealised($user, $role, 'failed', 'could not re-read the Zitadel roles after the cascade: ' . $e->getMessage());
            return;
        }

        if ($stillHeld) {
            $counts['roles_failed']++;
            $unrealised[] = $this->unrealised($user, $role, 'failed', 'Zitadel still lists the role after the cascade');
            return;
        }

        $counts['roles_revoked']++;
        $this->audit('viewer_retirement_revoke_role', 'role', $user, ['role' => $role, 'outcome' => 'revoked']);
    }

    /**
     * The script's exit code for an apply: 2 when a tuple delete or a request change failed outright or a planned
     * role revocation did not happen, else 0.
     *
     * @param ApplyResult $result
     */
    public static function exitCode(array $result): int
    {
        return $result['counts']['tuples_failed'] > 0
            || $result['counts']['requests_failed'] > 0
            || $result['unrealised_revocations'] !== [] ? 2 : 0;
    }

    /**
     * Log and audit a planned revocation that did not happen, and return its entry.
     *
     * @param 'kept'|'deferred'|'failed' $outcome
     * @return UnrealisedRevocation
     */
    private function unrealised(string $user, string $role, string $outcome, string $reason): array
    {
        $this->logger?->warning('ViewerRetirementSweep: planned role revocation not carried out', [
            'user_id' => $user,
            'role'    => $role,
            'outcome' => $outcome,
            'reason'  => $reason,
        ]);
        $this->audit('viewer_retirement_revoke_role', 'role', $user, ['role' => $role, 'outcome' => $outcome, 'reason' => $reason]);

        return ['user' => $user, 'role' => $role, 'outcome' => $outcome, 'reason' => $reason];
    }

    /**
     * The objects ("type:id") whose type is one of the given types.
     *
     * @param list<string> $objects
     * @param list<string> $types
     * @return list<string>
     */
    private static function inScope(array $objects, array $types): array
    {
        return array_values(array_filter(
            $objects,
            static fn (string $object): bool => in_array(explode(':', $object, 2)[0], $types, true)
        ));
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
                    // Not ours to judge or erase: a malformed entry is kept exactly as it is.
                    $keep[] = $permission;
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
     * @param list<string> $heldRoles     the user's Zitadel roles
     * @param list<string> $viewerObjects full objects ("type:id") of the user's viewer tuples
     * @return list<string>
     */
    private function rolesHeldOnlyThroughViewer(string $userId, array $heldRoles, array $viewerObjects): array
    {
        $fgaUser = "user:{$userId}";
        $roles   = [];
        foreach ($heldRoles as $role) {
            $types = AccessRequestRepository::ROLE_OBJECT_TYPES[$role] ?? [];
            if ($types === []) {
                continue; // not a scoped role (e.g. the global admin role)
            }

            if (self::inScope($viewerObjects, $types) === []) {
                continue;
            }

            if (!$this->hasJustifyingTuple($fgaUser, $role, $types, $viewerObjects)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /**
     * Does something other than the viewer tuples being deleted justify the role: a tuple, or a justifying write
     * still queued in the outbox (which {@see RoleCascadeService::maybeCascadeRoleRevoke()} also counts)?
     *
     * @param list<string> $types
     * @param list<string> $ignoredViewerObjects
     */
    private function hasJustifyingTuple(string $fgaUser, string $role, array $types, array $ignoredViewerObjects): bool
    {
        $relations = RoleCascadeService::justifyingRelations($role);
        foreach ($types as $type) {
            foreach ($relations as $relation) {
                foreach ($this->fga->listObjects($fgaUser, $relation, $type) as $objectId) {
                    if ($relation === 'viewer' && in_array("{$type}:{$objectId}", $ignoredViewerObjects, true)) {
                        continue;
                    }
                    return true;
                }
            }
        }

        return $this->pendingWrites?->hasPendingWrite($fgaUser, $relations, $types) === true;
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
