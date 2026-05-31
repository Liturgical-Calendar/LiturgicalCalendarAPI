<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Repositories;

use LiturgicalCalendar\Api\Database\Connection;
use PDO;

/**
 * Unified repository for access requests (role + permissions workflow).
 *
 * Replaces both RoleRequestRepository and PermissionRequestRepository
 * with a single `access_requests` table. Each request includes a role
 * and an optional JSONB array of OpenFGA permission tuples.
 *
 * When approved, roles are assigned in Zitadel and permission tuples
 * are created in OpenFGA via the appropriate handlers.
 */
class AccessRequestRepository
{
    private PDO $db;

    /**
     * Valid roles that can be requested.
     */
    public const VALID_ROLES = ['developer', 'calendar_editor', 'test_editor'];

    /**
     * Valid statuses for access requests.
     */
    public const VALID_STATUSES = ['pending', 'approved', 'rejected', 'revoked'];

    /**
     * Valid Zitadel sync statuses.
     */
    public const VALID_SYNC_STATUSES = ['pending', 'synced', 'failed'];

    /**
     * Valid OpenFGA relations on permission tuples.
     */
    public const VALID_RELATIONS = ['admin', 'viewer', 'editor', 'deleter'];

    /**
     * Valid OpenFGA object types on permission tuples.
     */
    public const VALID_OBJECT_TYPES = ['national_calendar', 'diocesan_calendar', 'wider_region', 'test_definition'];

    /**
     * Object types each role is permitted to hold tuples for. Used by RoleCascadeService
     * to detect when revoking a tuple leaves the role with zero in-scope tuples and
     * should therefore cascade to a role-level revoke.
     *
     * @var array<string, array<int, string>>
     */
    public const ROLE_OBJECT_TYPES = [
        'developer'       => ['national_calendar', 'diocesan_calendar', 'wider_region', 'test_definition'],
        'calendar_editor' => ['national_calendar', 'diocesan_calendar', 'wider_region'],
        'test_editor'     => ['test_definition'],
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Create a new access request.
     *
     * @param string $userId Zitadel user ID making the request
     * @param string $userEmail User's email (for display in admin UI)
     * @param string|null $userName User's name (for display in admin UI)
     * @param string $requestedRole Role being requested
     * @param array<int, array{object_type: string, object_id: string, relation: string}> $permissions
     *        OpenFGA permission tuples to request
     * @param string|null $justification Reason for requesting access
     * @param string|null $credentials Supporting credentials
     * @return string The UUID of the created request
     * @throws \InvalidArgumentException If the requested role is invalid
     * @throws \RuntimeException If the insert fails and no ID is returned
     */
    public function create(
        string $userId,
        string $userEmail,
        ?string $userName,
        string $requestedRole,
        array $permissions,
        ?string $justification = null,
        ?string $credentials = null
    ): string {
        if (!in_array($requestedRole, self::VALID_ROLES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid role: %s. Valid roles are: %s', $requestedRole, implode(', ', self::VALID_ROLES))
            );
        }

        $stmt = $this->db->prepare(
            'INSERT INTO access_requests
                (zitadel_user_id, user_email, user_name, requested_role, permissions, justification, credentials)
             VALUES (:user_id, :user_email, :user_name, :requested_role, :permissions, :justification, :credentials)
             RETURNING id'
        );

        $stmt->execute([
            'user_id'        => $userId,
            'user_email'     => $userEmail,
            'user_name'      => $userName,
            'requested_role' => $requestedRole,
            'permissions'    => json_encode($permissions),
            'justification'  => $justification,
            'credentials'    => $credentials,
        ]);

        $id = $stmt->fetchColumn();
        if (!is_string($id) || $id === '') {
            throw new \RuntimeException(
                sprintf(
                    'Failed to create access request: INSERT did not return an ID (user_id=%s, role=%s)',
                    $userId,
                    $requestedRole
                )
            );
        }

        return $id;
    }

    /**
     * Get an access request by ID.
     *
     * @param string $id Request UUID
     * @return array<string, mixed>|null The request data or null if not found
     */
    public function getById(string $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM access_requests WHERE id = :id'
        );

        $stmt->execute(['id' => $id]);
        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch();

        if (!is_array($result)) {
            return null;
        }

        return self::decodePermissions($result);
    }

    /**
     * Check if a user has a pending request for a specific role.
     *
     * @param string $userId Zitadel user ID
     * @param string $role Role to check
     * @return bool True if a pending request exists
     */
    public function hasPendingRequest(string $userId, string $role): bool
    {
        // Defense-in-depth: invalid roles can't have pending requests
        if (!in_array($role, self::VALID_ROLES, true)) {
            return false;
        }

        $stmt = $this->db->prepare(
            "SELECT 1 FROM access_requests
             WHERE zitadel_user_id = :user_id
               AND requested_role = :role
               AND status = 'pending'"
        );

        $stmt->execute([
            'user_id' => $userId,
            'role'    => $role,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Check if a user has any approved role.
     *
     * @param string $userId Zitadel user ID
     * @return bool True if user has at least one approved access request
     */
    public function hasApprovedRole(string $userId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM access_requests
             WHERE zitadel_user_id = :user_id
               AND status = 'approved'
             LIMIT 1"
        );

        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Get all requests for a specific user.
     *
     * @param string $userId Zitadel user ID
     * @return array<int, array<string, mixed>> List of requests
     */
    public function getByUser(string $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM access_requests
             WHERE zitadel_user_id = :user_id
             ORDER BY created_at DESC'
        );

        $stmt->execute(['user_id' => $userId]);

        /** @var array<int, array<string, mixed>> $results */
        $results = $stmt->fetchAll();

        return $this->decodePermissionsList($results);
    }

    /**
     * Get all pending access requests (for global admin).
     *
     * @return array<int, array<string, mixed>> List of pending requests
     */
    public function getPending(): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM access_requests
             WHERE status = :status
             ORDER BY created_at ASC'
        );

        $stmt->execute(['status' => 'pending']);

        /** @var array<int, array<string, mixed>> $results */
        $results = $stmt->fetchAll();

        return $this->decodePermissionsList($results);
    }

    /**
     * Get all access requests with optional status filter.
     *
     * @param string|null $status Filter by status (pending, approved, rejected, revoked)
     * @return array<int, array<string, mixed>> List of requests
     * @throws \InvalidArgumentException If status is not a valid value
     */
    public function getAll(?string $status = null): array
    {
        if ($status !== null) {
            if (!in_array($status, self::VALID_STATUSES, true)) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid status filter: %s. Valid values are: %s', $status, implode(', ', self::VALID_STATUSES))
                );
            }
            $stmt = $this->db->prepare(
                'SELECT * FROM access_requests
                 WHERE status = :status
                 ORDER BY created_at DESC'
            );
            $stmt->execute(['status' => $status]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT * FROM access_requests
                 ORDER BY created_at DESC'
            );
            $stmt->execute();
        }

        /** @var array<int, array<string, mixed>> $results */
        $results = $stmt->fetchAll();

        return $this->decodePermissionsList($results);
    }

    /**
     * Get counts of requests by status.
     *
     * @return array{pending: int, approved: int, rejected: int, revoked: int}
     */
    public function getCounts(): array
    {
        $stmt = $this->db->prepare(
            'SELECT status, COUNT(*) as count
             FROM access_requests
             GROUP BY status'
        );

        $stmt->execute();

        $counts = [
            'pending'  => 0,
            'approved' => 0,
            'rejected' => 0,
            'revoked'  => 0,
        ];

        while ($row = $stmt->fetch()) {
            if (is_array($row) && isset($row['status'], $row['count'])) {
                $statusValue = $row['status'];
                $countValue  = $row['count'];
                if (is_string($statusValue) && ( is_int($countValue) || is_string($countValue) )) {
                    $status = $statusValue;
                    $count  = (int) $countValue;
                    if (isset($counts[$status])) {
                        $counts[$status] = $count;
                    }
                }
            }
        }

        return $counts;
    }

    /**
     * Approve an access request.
     *
     * Note: This only updates the database. The caller is responsible
     * for actually assigning the role in Zitadel and creating OpenFGA
     * tuples for the requested permissions.
     *
     * @param string $id Request UUID
     * @param string $reviewedBy Zitadel user ID of the admin approving
     * @param string|null $notes Optional review notes
     * @return bool True if approved successfully
     */
    public function approve(string $id, string $reviewedBy, ?string $notes = null): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE access_requests
             SET status = 'approved',
                 reviewed_by = :reviewed_by,
                 review_notes = :notes,
                 reviewed_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'pending'"
        );

        $stmt->execute([
            'id'          => $id,
            'reviewed_by' => $reviewedBy,
            'notes'       => $notes,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Reject an access request.
     *
     * @param string $id Request UUID
     * @param string $reviewedBy Zitadel user ID of the admin rejecting
     * @param string|null $notes Reason for rejection
     * @return bool True if rejected successfully
     */
    public function reject(string $id, string $reviewedBy, ?string $notes = null): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE access_requests
             SET status = 'rejected',
                 reviewed_by = :reviewed_by,
                 review_notes = :notes,
                 reviewed_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'pending'"
        );

        $stmt->execute([
            'id'          => $id,
            'reviewed_by' => $reviewedBy,
            'notes'       => $notes,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Resubmit a rejected access request with updated permissions.
     *
     * Resets status to 'pending', clears review fields, and updates
     * the permissions array. The original request ID and justification
     * are preserved to maintain the conversation thread.
     *
     * @param string $id Request UUID
     * @param array<int, array{object_type: string, object_id: string, relation: string}> $permissions Updated permissions
     * @param string|null $justification Updated justification (null = keep original)
     * @return bool True if resubmitted successfully
     */
    public function resubmit(string $id, array $permissions, ?string $justification = null): bool
    {
        $setClauses = [
            "status = 'pending'",
            'permissions = :permissions',
            'reviewed_by = NULL',
            'review_notes = NULL',
            'reviewed_at = NULL',
            'zitadel_sync_status = NULL',
            'zitadel_sync_error = NULL',
            'created_at = CURRENT_TIMESTAMP',
        ];

        $params = [
            'id'          => $id,
            'permissions' => json_encode($permissions),
        ];

        if ($justification !== null) {
            $setClauses[]            = 'justification = :justification';
            $params['justification'] = $justification;
        }

        $sql = 'UPDATE access_requests SET '
            . implode(', ', $setClauses)
            . " WHERE id = :id AND status = 'rejected'";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Revoke a previously approved access request.
     *
     * This marks the request as revoked. The caller is responsible for
     * also revoking the role in Zitadel and deleting the corresponding
     * OpenFGA tuples.
     *
     * @param string $id Request UUID
     * @param string $reviewedBy Zitadel user ID of the admin revoking
     * @param string|null $notes Reason for revocation
     * @return bool True if revoked successfully
     */
    public function revoke(string $id, string $reviewedBy, ?string $notes = null): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE access_requests
             SET status = 'revoked',
                 reviewed_by = :reviewed_by,
                 review_notes = :notes,
                 reviewed_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'approved'"
        );

        $stmt->execute([
            'id'          => $id,
            'reviewed_by' => $reviewedBy,
            'notes'       => $notes,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Cascade-revoke all approved access_requests for a (user, role) pair.
     *
     * Called by RoleCascadeService when a tuple revoke (or direct role revoke)
     * leaves the user with zero remaining tuples in the role's scope. Distinct
     * from revoke() in that it operates by (user, role) rather than by request
     * UUID, since a single role-revoke may affect multiple historical access
     * requests at once. The reviewer is recorded as 'system:cascade' so the
     * audit trail distinguishes cascade revokes from explicit admin revokes.
     *
     * @param string $userId Zitadel user ID
     * @param string $role The role whose access_requests should be marked revoked
     * @param string|null $notes Optional reason; defaults to a system note
     * @return int Number of access_requests rows updated
     */
    public function cascadeRevokeByRole(string $userId, string $role, ?string $notes = null): int
    {
        $stmt = $this->db->prepare(
            "UPDATE access_requests
             SET status = 'revoked',
                 reviewed_by = 'system:cascade',
                 review_notes = COALESCE(:notes, 'Role cascade-revoked: no remaining permissions in scope'),
                 reviewed_at = CURRENT_TIMESTAMP
             WHERE zitadel_user_id = :user_id
               AND requested_role = :role
               AND status = 'approved'"
        );

        $stmt->execute([
            'user_id' => $userId,
            'role'    => $role,
            'notes'   => $notes,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Update the Zitadel sync status for an access request.
     *
     * Used to track whether role assignment/revocation was successfully
     * synced to Zitadel after the database transaction is committed.
     *
     * @param string $id Request UUID
     * @param string $status Sync status: 'pending', 'synced', or 'failed'
     * @param string|null $error Error message if sync failed
     * @throws \InvalidArgumentException If status is not a valid sync status
     */
    public function updateZitadelSyncStatus(string $id, string $status, ?string $error = null): void
    {
        if (!in_array($status, self::VALID_SYNC_STATUSES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid sync status: %s. Valid values are: %s', $status, implode(', ', self::VALID_SYNC_STATUSES))
            );
        }

        $stmt = $this->db->prepare(
            'UPDATE access_requests
             SET zitadel_sync_status = :sync_status,
                 zitadel_sync_error = :error
             WHERE id = :id'
        );

        $stmt->execute([
            'id'          => $id,
            'sync_status' => $status,
            'error'       => $error,
        ]);
    }

    /**
     * Count pending requests (for notification badge).
     *
     * @return int Number of pending requests
     */
    public function countPending(): int
    {
        $stmt = $this->db->query(
            'SELECT COUNT(*) FROM access_requests WHERE status = \'pending\''
        );

        if ($stmt === false) {
            return 0;
        }

        $count = $stmt->fetchColumn();
        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Remove a specific permission tuple from all approved access requests.
     *
     * When an individual tuple is revoked via PermissionAdminHandler,
     * this method keeps the access_requests table in sync by removing
     * the matching permission from the JSONB array.
     *
     * @param string $userId Zitadel user ID (without "user:" prefix)
     * @param string $objectType OpenFGA object type (e.g., "national_calendar")
     * @param string $objectId Resource identifier (e.g., "IT")
     * @param string $relation OpenFGA relation (e.g., "editor")
     * @return int Number of access requests updated
     */
    public function removePermissionTuple(
        string $userId,
        string $objectType,
        string $objectId,
        string $relation
    ): int {
        // Find approved requests for this user that contain the permission
        $stmt = $this->db->prepare(
            "SELECT id, permissions FROM access_requests
             WHERE zitadel_user_id = :user_id
               AND status = 'approved'"
        );
        $stmt->execute(['user_id' => $userId]);

        /** @var array<int, array{id: string, permissions: string}> $rows */
        $rows    = $stmt->fetchAll();
        $updated = 0;

        foreach ($rows as $row) {
            /** @var array<int, array{object_type: string, object_id: string, relation: string}>|null $permissions */
            $permissions = json_decode($row['permissions'], true);
            if (!is_array($permissions)) {
                continue;
            }

            // Filter out the matching permission
            $filtered = array_values(array_filter(
                $permissions,
                function (array $perm) use ($objectType, $objectId, $relation): bool {
                    return !(
                        $perm['object_type'] === $objectType
                        && $perm['object_id'] === $objectId
                        && $perm['relation'] === $relation
                    );
                }
            ));

            // Only update if something was actually removed
            if (count($filtered) < count($permissions)) {
                $update = $this->db->prepare(
                    'UPDATE access_requests
                     SET permissions = :permissions
                     WHERE id = :id'
                );
                $update->execute([
                    'id'          => $row['id'],
                    'permissions' => json_encode($filtered),
                ]);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Decode the permissions JSON column in a single result row.
     *
     * @param array<string, mixed> $row Database row
     * @return array<string, mixed> Row with permissions decoded
     */
    public static function decodePermissions(array $row): array
    {
        if (isset($row['permissions']) && is_string($row['permissions'])) {
            /** @var array<int, array{object_type: string, object_id: string, relation: string}>|null $decoded */
            $decoded            = json_decode($row['permissions'], true);
            $row['permissions'] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }

    /**
     * Decode the permissions JSON column in a list of result rows.
     *
     * @param array<int, array<string, mixed>> $rows Database rows
     * @return array<int, array<string, mixed>> Rows with permissions decoded
     */
    private function decodePermissionsList(array $rows): array
    {
        return array_map([self::class, 'decodePermissions'], $rows);
    }
}
