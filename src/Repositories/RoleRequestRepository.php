<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Repositories;

use LiturgicalCalendar\Api\Database\Connection;
use PDO;

/**
 * Repository for managing role request workflow.
 *
 * Handles the lifecycle of role requests from creation
 * through approval or rejection. When approved, roles
 * are assigned in Zitadel via the Management API.
 */
class RoleRequestRepository
{
    private PDO $db;

    /**
     * Valid roles that can be requested.
     */
    public const VALID_ROLES = ['developer', 'calendar_editor', 'test_editor'];

    /**
     * Valid statuses for role requests.
     */
    public const VALID_STATUSES = ['pending', 'approved', 'rejected', 'revoked'];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Create a new role request.
     *
     * @param string $userId Zitadel user ID making the request
     * @param string $userEmail User's email (for display in admin UI)
     * @param string $userName User's name (for display in admin UI)
     * @param string $requestedRole Role being requested
     * @param string|null $justification Reason for requesting the role
     * @return string The UUID of the created request
     * @throws \InvalidArgumentException If the requested role is invalid
     * @throws \RuntimeException If the insert fails and no ID is returned
     */
    public function createRequest(
        string $userId,
        string $userEmail,
        string $userName,
        string $requestedRole,
        ?string $justification = null
    ): string {
        if (!in_array($requestedRole, self::VALID_ROLES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid role: %s. Valid roles are: %s', $requestedRole, implode(', ', self::VALID_ROLES))
            );
        }

        $stmt = $this->db->prepare(
            'INSERT INTO role_requests
                (zitadel_user_id, user_email, user_name, requested_role, justification)
             VALUES (:user_id, :user_email, :user_name, :requested_role, :justification)
             RETURNING id'
        );

        $stmt->execute([
            'user_id'        => $userId,
            'user_email'     => $userEmail,
            'user_name'      => $userName,
            'requested_role' => $requestedRole,
            'justification'  => $justification,
        ]);

        $id = $stmt->fetchColumn();
        if (!is_string($id) || $id === '') {
            throw new \RuntimeException(
                sprintf(
                    'Failed to create role request: INSERT did not return an ID (user_id=%s, role=%s)',
                    $userId,
                    $requestedRole
                )
            );
        }
        return $id;
    }

    /**
     * Get a role request by ID.
     *
     * @param string $id Request UUID
     * @return array<string, mixed>|null The request data or null if not found
     */
    public function getById(string $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM role_requests WHERE id = :id'
        );

        $stmt->execute(['id' => $id]);
        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch();

        return is_array($result) ? $result : null;
    }

    /**
     * Get a role request by ID with optional status filter.
     *
     * @param string $id Request UUID
     * @param string|null $status Optional status filter (pending, approved, rejected, revoked)
     * @return array<string, mixed>|null The request data or null if not found/status mismatch
     * @throws \InvalidArgumentException If status is not a valid value
     */
    public function getByIdWithStatus(string $id, ?string $status = null): ?array
    {
        if ($status !== null && !in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid status filter: %s. Valid values are: %s', $status, implode(', ', self::VALID_STATUSES))
            );
        }

        if ($status !== null) {
            $stmt = $this->db->prepare(
                'SELECT * FROM role_requests WHERE id = :id AND status = :status'
            );
            $stmt->execute(['id' => $id, 'status' => $status]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT * FROM role_requests WHERE id = :id'
            );
            $stmt->execute(['id' => $id]);
        }

        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch();

        return is_array($result) ? $result : null;
    }

    /**
     * Get all pending role requests.
     *
     * @return array<int, array<string, mixed>> List of pending requests
     */
    public function getPendingRequests(): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM role_requests
             WHERE status = :status
             ORDER BY created_at ASC'
        );

        $stmt->execute(['status' => 'pending']);

        /** @var array<int, array<string, mixed>> */
        return $stmt->fetchAll();
    }

    /**
     * Get all requests for a specific user.
     *
     * @param string $userId Zitadel user ID
     * @return array<int, array<string, mixed>> List of requests
     */
    public function getRequestsForUser(string $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM role_requests
             WHERE zitadel_user_id = :user_id
             ORDER BY created_at DESC'
        );

        $stmt->execute(['user_id' => $userId]);

        /** @var array<int, array<string, mixed>> */
        return $stmt->fetchAll();
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
            "SELECT 1 FROM role_requests
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
     * @return bool True if user has at least one approved role request
     */
    public function hasApprovedRole(string $userId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM role_requests
             WHERE zitadel_user_id = :user_id
               AND status = 'approved'
             LIMIT 1"
        );

        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Approve a role request.
     *
     * Note: This only updates the database. The caller is responsible
     * for actually assigning the role in Zitadel via the Management API.
     *
     * @param string $requestId Request UUID
     * @param string $reviewedBy Zitadel user ID of the admin approving
     * @param string|null $notes Optional review notes
     * @return array<string, mixed>|null The approved request data, or null if not found/already processed
     */
    public function approveRequest(string $requestId, string $reviewedBy, ?string $notes = null): ?array
    {
        $stmt = $this->db->prepare(
            "UPDATE role_requests
             SET status = 'approved',
                 reviewed_by = :reviewed_by,
                 review_notes = :notes,
                 reviewed_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'pending'
             RETURNING *"
        );

        $stmt->execute([
            'id'          => $requestId,
            'reviewed_by' => $reviewedBy,
            'notes'       => $notes,
        ]);

        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch();

        return is_array($result) ? $result : null;
    }

    /**
     * Reject a role request.
     *
     * @param string $requestId Request UUID
     * @param string $reviewedBy Zitadel user ID of the admin rejecting
     * @param string|null $notes Reason for rejection
     * @return bool True if rejected successfully
     */
    public function rejectRequest(string $requestId, string $reviewedBy, ?string $notes = null): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE role_requests
             SET status = 'rejected',
                 reviewed_by = :reviewed_by,
                 review_notes = :notes,
                 reviewed_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'pending'"
        );

        $stmt->execute([
            'id'          => $requestId,
            'reviewed_by' => $reviewedBy,
            'notes'       => $notes,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Revoke a previously approved role request.
     *
     * @param string $requestId Request UUID
     * @param string $reviewedBy Zitadel user ID of the admin revoking
     * @param string|null $notes Reason for revocation
     * @return bool True if revoked successfully
     */
    public function revokeRequest(string $requestId, string $reviewedBy, ?string $notes = null): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE role_requests
             SET status = 'revoked',
                 reviewed_by = :reviewed_by,
                 review_notes = :notes,
                 reviewed_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'approved'"
        );

        $stmt->execute([
            'id'          => $requestId,
            'reviewed_by' => $reviewedBy,
            'notes'       => $notes,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get all role requests with optional status filter.
     *
     * @param string|null $status Filter by status (pending, approved, rejected, revoked)
     * @return array<int, array<string, mixed>> List of requests
     * @throws \InvalidArgumentException If status is not a valid value
     */
    public function getAllRequests(?string $status = null): array
    {
        if ($status !== null) {
            if (!in_array($status, self::VALID_STATUSES, true)) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid status filter: %s. Valid values are: %s', $status, implode(', ', self::VALID_STATUSES))
                );
            }
            $stmt = $this->db->prepare(
                'SELECT * FROM role_requests
                 WHERE status = :status
                 ORDER BY created_at DESC'
            );
            $stmt->execute(['status' => $status]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT * FROM role_requests
                 ORDER BY created_at DESC'
            );
            $stmt->execute();
        }

        /** @var array<int, array<string, mixed>> */
        return $stmt->fetchAll();
    }

    /**
     * Get counts of requests by status.
     *
     * @return array{pending: int, approved: int, rejected: int, revoked: int}
     */
    public function getRequestCounts(): array
    {
        $stmt = $this->db->prepare(
            'SELECT status, COUNT(*) as count
             FROM role_requests
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
     * Update the Zitadel sync status for a role request.
     *
     * Used to track whether role assignment/revocation was successfully
     * synced to Zitadel after the database transaction is committed.
     *
     * @param string $requestId Request UUID
     * @param string $syncStatus Sync status: 'pending', 'synced', or 'failed'
     * @param string|null $errorMessage Error message if sync failed
     * @return bool True if updated successfully
     */
    public function updateZitadelSyncStatus(
        string $requestId,
        string $syncStatus,
        ?string $errorMessage = null
    ): bool {
        $validSyncStatuses = ['pending', 'synced', 'failed'];
        if (!in_array($syncStatus, $validSyncStatuses, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid sync status: %s. Valid values are: %s', $syncStatus, implode(', ', $validSyncStatuses))
            );
        }

        $stmt = $this->db->prepare(
            'UPDATE role_requests
             SET zitadel_sync_status = :sync_status,
                 zitadel_sync_error = :error_message
             WHERE id = :id'
        );

        $stmt->execute([
            'id'            => $requestId,
            'sync_status'   => $syncStatus,
            'error_message' => $errorMessage,
        ]);

        return $stmt->rowCount() > 0;
    }
}
