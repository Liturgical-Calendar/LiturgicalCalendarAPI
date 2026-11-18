<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\Exception\OpenFgaApiException;
use LiturgicalCalendar\Api\Services\Outbox\OutboxFallbackTupleDeleter;
use LiturgicalCalendar\Api\Services\Outbox\OutboxNotifier;
use Psr\Log\LoggerInterface;

/**
 * Coordinates cascade revokes between OpenFGA tuples, Zitadel role grants,
 * and the access_requests table.
 *
 * Centralises the "no permissions in scope ⇒ no role" rule, which all three
 * revoke paths (single-tuple revoke, access-request revoke, direct role revoke)
 * must enforce consistently. Without this service the rule was either missing
 * (tuple/direct paths) or incorrect (access-request path always revoked the
 * role even when other access requests still granted in-scope tuples).
 */
class RoleCascadeService
{
    public function __construct(
        private OpenFgaClient $fga,
        private ZitadelService $zitadel,
        private AccessRequestRepository $repo,
        private ?LoggerInterface $logger = null,
        private ?OutboxRepository $outboxRepo = null,
        private ?OutboxNotifier $outboxNotifier = null,
    ) {
    }

    /**
     * Build a service from environment-configured collaborators.
     */
    public static function fromEnv(?LoggerInterface $logger = null): self
    {
        // Best-effort; null falls back to PG-only durability. See {@see RedisConnection}.
        $redis      = RedisConnection::bestEffort($logger);
        $streamName = is_string($_ENV['REDIS_OUTBOX_STREAM'] ?? null) ? $_ENV['REDIS_OUTBOX_STREAM'] : 'litcal:reconcile-stream';

        return new self(
            OpenFgaClient::fromEnv(),
            ZitadelService::fromEnv($logger),
            new AccessRequestRepository(),
            $logger,
            new OutboxRepository(Connection::getInstance()),
            new OutboxNotifier($redis, $streamName, $logger),
        );
    }

    /**
     * The relations whose tuples justify holding the role.
     *
     * A viewer grant assigns no calendar_editor / test_editor role, so a viewer tuple must not keep one
     * either: those roles count admin and editor. developer keeps every relation.
     *
     * @return list<string>
     */
    public static function justifyingRelations(string $role): array
    {
        return in_array($role, ['calendar_editor', 'test_editor'], true)
            ? ['admin', 'editor']
            : AccessRequestRepository::VALID_RELATIONS;
    }

    /**
     * Does the user have any OpenFGA tuple within the given role's scope?
     *
     * Iterates the role's allowed object types × the relations that justify the role
     * (admin and editor for calendar_editor and test_editor; viewer does not; every
     * relation for developer), querying
     * OpenFGA's ListObjects for each pair. Short-circuits on the first hit.
     * Worst case: |types| × |relations| ListObjects calls per check (≤ 16),
     * but the caller is an admin-driven UI revoke action so the cost is fine.
     */
    public function userHasAnyTupleInRoleScope(string $userId, string $role): bool
    {
        $allowedTypes = AccessRequestRepository::ROLE_OBJECT_TYPES[$role] ?? [];
        if (empty($allowedTypes)) {
            return false;
        }

        $relations = self::justifyingRelations($role);

        $fgaUser = "user:{$userId}";
        foreach ($allowedTypes as $type) {
            foreach ($relations as $relation) {
                if (!empty($this->fga->listObjects($fgaUser, $relation, $type))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * If the user has zero remaining tuples in the role's scope, cascade-revoke
     * the role: revoke it in Zitadel and mark all approved access_requests for
     * (user, role) as revoked. Idempotent — calling when the role isn't held
     * or no rows match is a no-op.
     *
     * @return bool True if the role was cascaded-revoked, false if the user
     *              still has tuples, or a justifying write still queued in the outbox (no cascade needed).
     */
    public function maybeCascadeRoleRevoke(string $userId, string $role): bool
    {
        if ($this->userHasAnyTupleInRoleScope($userId, $role)) {
            return false;
        }

        // A justifying grant that is still queued (pending or retrying) has no tuple yet, but will:
        // keep the role rather than remove it and orphan the grant when it applies.
        $relations = self::justifyingRelations($role);
        if (
            $this->outboxRepo?->hasPendingWrite(
                "user:{$userId}",
                $relations,
                AccessRequestRepository::ROLE_OBJECT_TYPES[$role] ?? []
            ) === true
        ) {
            $this->logger?->info('RoleCascadeService: role kept, a justifying permission write is still queued', [
                'user_id' => $userId,
                'role'    => $role,
            ]);
            return false;
        }

        // Zitadel revoke: tolerate failures (e.g., role already absent) — the
        // DB cascade below still runs so audit state stays consistent.
        try {
            $this->zitadel->revokeUserRole($userId, $role);
        } catch (\Throwable $e) {
            $this->logger?->warning('RoleCascadeService: Zitadel revokeUserRole failed during cascade', [
                'user_id' => $userId,
                'role'    => $role,
                'error'   => $e->getMessage(),
            ]);
        }

        // A viewer tuple never justified calendar_editor / test_editor, but the requests about to be
        // marked revoked must not keep live access: delete the leftovers too (failures go to the outbox).
        if (in_array($role, ['calendar_editor', 'test_editor'], true)) {
            $viewers = $this->deleteTuplesInRoleScope(
                $userId,
                $role,
                AccessRequestRepository::ROLE_OBJECT_TYPES[$role],
                ['viewer']
            );
            if (!empty($viewers)) {
                $this->logger?->info('RoleCascadeService: deleted leftover viewer tuples during role cascade', [
                    'user_id' => $userId,
                    'role'    => $role,
                    'tuples'  => $viewers,
                ]);
            }
        }

        $this->repo->cascadeRevokeByRole($userId, $role);
        return true;
    }

    /**
     * Delete every OpenFGA tuple for the user within the role's scope, then
     * cascade-revoke the role's access_requests rows. Used by the
     * direct-role-revoke path (UsersHandler) where the admin removes a Zitadel
     * role and we need to clean up the now-orphaned tuples.
     *
     * Note: caller is responsible for the actual zitadel revokeUserRole call;
     * this method handles the tuple + DB cleanup that should follow.
     *
     * @return array<int, array{user: string, relation: string, object: string}> Deleted tuples (for response/audit)
     */
    public function cascadeTupleRevokeForRole(string $userId, string $role): array
    {
        $allowedTypes = AccessRequestRepository::ROLE_OBJECT_TYPES[$role] ?? [];
        if (empty($allowedTypes)) {
            return [];
        }

        $deleted = $this->deleteTuplesInRoleScope($userId, $role, $allowedTypes, AccessRequestRepository::VALID_RELATIONS);

        $this->repo->cascadeRevokeByRole($userId, $role);
        return $deleted;
    }

    /**
     * Delete the user's tuples for the given relations in the given object types.
     *
     * A failed delete is never lost: a benign failure (tuple already gone) counts as consistent and is skipped,
     * anything else is enqueued as a DELETE_TUPLE outbox row and the outbox notifier is told.
     *
     * @param list<string> $objectTypes
     * @param list<string> $relations
     * @return array<int, array{user: string, relation: string, object: string}> Tuples deleted synchronously
     */
    private function deleteTuplesInRoleScope(string $userId, string $role, array $objectTypes, array $relations): array
    {
        $fgaUser = "user:{$userId}";
        $deleted = [];
        $deleter = new OutboxFallbackTupleDeleter($this->fga, $this->outboxRepo, $this->outboxNotifier);
        foreach ($objectTypes as $type) {
            foreach ($relations as $relation) {
                $objectIds = $this->fga->listObjects($fgaUser, $relation, $type);
                foreach ($objectIds as $objectId) {
                    $fgaObject      = "{$type}:{$objectId}";
                    $idempotencyKey = sprintf(
                        'role_cascade:%s:%s:delete_tuple:%s:%s:%s',
                        $userId,
                        $role,
                        $fgaUser,
                        $relation,
                        $fgaObject,
                    );
                    $result         = $deleter->delete($fgaUser, $relation, $fgaObject, $idempotencyKey, [
                        'role_cascade_user' => $userId,
                        'role_cascade_role' => $role,
                    ]);

                    if ($result['outcome'] === 'deleted') {
                        $deleted[] = [
                            'user'     => $fgaUser,
                            'relation' => $relation,
                            'object'   => $fgaObject,
                        ];
                        continue;
                    }

                    if ($result['outcome'] === 'already_gone') {
                        // Tuple already gone — cascade is already consistent.
                        $this->logger?->info('RoleCascadeService: deleteTuple benign during cascade', [
                            'user_id'  => $userId,
                            'role'     => $role,
                            'relation' => $relation,
                            'object'   => $fgaObject,
                        ]);
                        continue;
                    }

                    $e = $result['error'];
                    $this->logger?->warning(
                        'RoleCascadeService: deleteTuple failed during cascade — outbox enqueued',
                        [
                            'user_id'        => $userId,
                            'role'           => $role,
                            'relation'       => $relation,
                            'object'         => $fgaObject,
                            'outbox_row_ids' => $result['outbox_row_ids'],
                            'error_code'     => $e instanceof OpenFgaApiException ? $e->getErrorCode() : null,
                            'error'          => $e?->getMessage(),
                        ]
                    );
                }
            }
        }
        return $deleted;
    }
}
