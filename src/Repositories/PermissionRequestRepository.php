<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Repositories;

use LiturgicalCalendar\Api\Database\Connection;
use PDO;

/**
 * Repository for managing permission requests (approval workflow).
 *
 * Users request access to specific calendars/resources. Admins review
 * and approve or reject. On approval, an OpenFGA tuple is created
 * via the PermissionAdminHandler.
 */
class PermissionRequestRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Create a new permission request.
     *
     * @param string $userId Zitadel user ID
     * @param string $userEmail User email
     * @param string|null $userName User display name
     * @param string $objectType OpenFGA object type (e.g., "national_calendar")
     * @param string $objectId Resource ID (e.g., "IT")
     * @param string $relation OpenFGA relation (e.g., "editor")
     * @param string|null $justification Why the user needs access
     * @param string|null $credentials Supporting credentials
     * @return string The UUID of the created request
     */
    public function create(
        string $userId,
        string $userEmail,
        ?string $userName,
        string $objectType,
        string $objectId,
        string $relation,
        ?string $justification = null,
        ?string $credentials = null
    ): string {
        $stmt = $this->db->prepare(
            'INSERT INTO permission_requests
                (zitadel_user_id, user_email, user_name, object_type, object_id, relation, justification, credentials)
             VALUES (:user_id, :user_email, :user_name, :object_type, :object_id, :relation, :justification, :credentials)
             RETURNING id'
        );

        $stmt->execute([
            'user_id'       => $userId,
            'user_email'    => $userEmail,
            'user_name'     => $userName,
            'object_type'   => $objectType,
            'object_id'     => $objectId,
            'relation'      => $relation,
            'justification' => $justification,
            'credentials'   => $credentials,
        ]);

        $id = $stmt->fetchColumn();
        if (!is_string($id)) {
            throw new \RuntimeException('Failed to create permission request');
        }

        return $id;
    }

    /**
     * Get all pending permission requests.
     *
     * @return array<int, array<string, string|null>>
     */
    public function getPending(): array
    {
        $stmt = $this->db->query(
            'SELECT * FROM permission_requests
             WHERE status = \'pending\'
             ORDER BY created_at ASC'
        );

        if ($stmt === false) {
            return [];
        }

        /** @var array<int, array<string, string|null>> */
        return $stmt->fetchAll();
    }

    /**
     * Get all requests for a specific user.
     *
     * @param string $userId Zitadel user ID
     * @return array<int, array<string, string|null>>
     */
    public function getByUser(string $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM permission_requests
             WHERE zitadel_user_id = :user_id
             ORDER BY created_at DESC'
        );

        $stmt->execute(['user_id' => $userId]);

        /** @var array<int, array<string, string|null>> */
        return $stmt->fetchAll();
    }

    /**
     * Get a single request by ID.
     *
     * @param string $requestId UUID of the request
     * @return array<string, string|null>|null The request or null if not found
     */
    public function getById(string $requestId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM permission_requests WHERE id = :id'
        );

        $stmt->execute(['id' => $requestId]);
        $result = $stmt->fetch();

        if ($result === false) {
            return null;
        }

        /** @var array<string, string|null> */
        return $result;
    }

    /**
     * Approve a permission request.
     *
     * @param string $requestId UUID of the request
     * @param string $reviewedBy Admin's Zitadel user ID
     * @param string|null $notes Review notes
     * @return bool True if updated
     */
    public function approve(string $requestId, string $reviewedBy, ?string $notes = null): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE permission_requests
             SET status = \'approved\', reviewed_by = :reviewed_by,
                 review_notes = :notes, reviewed_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = \'pending\''
        );

        $stmt->execute([
            'id'          => $requestId,
            'reviewed_by' => $reviewedBy,
            'notes'       => $notes,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Reject a permission request.
     *
     * @param string $requestId UUID of the request
     * @param string $reviewedBy Admin's Zitadel user ID
     * @param string|null $notes Rejection reason
     * @return bool True if updated
     */
    public function reject(string $requestId, string $reviewedBy, ?string $notes = null): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE permission_requests
             SET status = \'rejected\', reviewed_by = :reviewed_by,
                 review_notes = :notes, reviewed_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = \'pending\''
        );

        $stmt->execute([
            'id'          => $requestId,
            'reviewed_by' => $reviewedBy,
            'notes'       => $notes,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Count pending requests (for notification badge).
     *
     * @return int Number of pending requests
     */
    public function countPending(): int
    {
        $stmt = $this->db->query(
            'SELECT COUNT(*) FROM permission_requests WHERE status = \'pending\''
        );

        if ($stmt === false) {
            return 0;
        }

        $count = $stmt->fetchColumn();
        return is_numeric($count) ? (int) $count : 0;
    }
}
