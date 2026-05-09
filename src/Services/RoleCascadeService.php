<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
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
        private ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Build a service from environment-configured collaborators.
     */
    public static function fromEnv(?LoggerInterface $logger = null): self
    {
        return new self(
            OpenFgaClient::fromEnv($logger),
            ZitadelService::fromEnv($logger),
            new AccessRequestRepository(),
            $logger
        );
    }

    /**
     * Does the user have any OpenFGA tuple within the given role's scope?
     *
     * Iterates the role's allowed object types × all valid relations, querying
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

        $fgaUser = "user:{$userId}";
        foreach ($allowedTypes as $type) {
            foreach (AccessRequestRepository::VALID_RELATIONS as $relation) {
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
     *              still has tuples (no cascade needed).
     */
    public function maybeCascadeRoleRevoke(string $userId, string $role): bool
    {
        if ($this->userHasAnyTupleInRoleScope($userId, $role)) {
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

        $fgaUser = "user:{$userId}";
        $deleted = [];
        foreach ($allowedTypes as $type) {
            foreach (AccessRequestRepository::VALID_RELATIONS as $relation) {
                $objectIds = $this->fga->listObjects($fgaUser, $relation, $type);
                foreach ($objectIds as $objectId) {
                    $fgaObject = "{$type}:{$objectId}";
                    try {
                        $this->fga->deleteTuple($fgaUser, $relation, $fgaObject);
                        $deleted[] = [
                            'user'     => $fgaUser,
                            'relation' => $relation,
                            'object'   => $fgaObject,
                        ];
                    } catch (\Throwable $e) {
                        $this->logger?->warning('RoleCascadeService: deleteTuple failed during cascade', [
                            'user_id'  => $userId,
                            'role'     => $role,
                            'relation' => $relation,
                            'object'   => $fgaObject,
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        $this->repo->cascadeRevokeByRole($userId, $role);
        return $deleted;
    }
}
