<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Repositories;

use LiturgicalCalendar\Api\Database\Connection;
use PDO;

/**
 * Repository for managing registered applications.
 *
 * Applications are registered by developers who want to use the API.
 * Each application can have multiple API keys.
 */
class ApplicationRepository
{
    private PDO $db;

    /**
     * Status constants for applications.
     */
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REVOKED  = 'revoked';

    /**
     * Valid statuses for applications.
     */
    public const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_REVOKED,
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Valid scopes for application access requests.
     */
    public const VALID_SCOPES = ['read', 'write'];

    /**
     * Create a new application.
     *
     * Applications are created with 'pending' status and must be approved by an admin.
     *
     * @param string $userId Zitadel user ID of the owner
     * @param string $name Application name
     * @param string|null $description Application description
     * @param string|null $website Application website URL
     * @param string $requestedScope Requested access scope ('read' or 'write')
     * @return array<string, mixed> The created application with UUID
     */
    public function create(
        string $userId,
        string $name,
        ?string $description = null,
        ?string $website = null,
        string $requestedScope = 'read'
    ): array {
        if (!in_array($requestedScope, self::VALID_SCOPES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid requested_scope: %s. Valid values are: %s', $requestedScope, implode(', ', self::VALID_SCOPES))
            );
        }

        $stmt = $this->db->prepare(
            'INSERT INTO applications (zitadel_user_id, name, description, website, requested_scope, status)
             VALUES (:user_id, :name, :description, :website, :requested_scope, :status)
             RETURNING id, name, description, website, requested_scope, status, is_active, created_at, updated_at'
        );

        $stmt->execute([
            'user_id'         => $userId,
            'name'            => $name,
            'description'     => $description,
            'website'         => $website,
            'requested_scope' => $requestedScope,
            'status'          => self::STATUS_PENDING,
        ]);

        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch();
        if (!is_array($result)) {
            throw new \RuntimeException('Failed to create application');
        }
        return $result;
    }

    /**
     * Get an application by UUID.
     *
     * @param string $uuid Application UUID
     * @return array<string, mixed>|null The application or null if not found
     */
    public function getByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, zitadel_user_id, name, description, website, requested_scope, status,
                    reviewed_by, review_notes, reviewed_at, is_active, created_at, updated_at
             FROM applications
             WHERE id = :uuid'
        );

        $stmt->execute(['uuid' => $uuid]);
        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch();

        return is_array($result) ? $result : null;
    }

    /**
     * Get an application by ID (UUID).
     *
     * Alias for getByUuid() to provide a consistent interface.
     *
     * @param string $id Application ID (UUID)
     * @return array<string, mixed>|null The application or null if not found
     */
    public function getById(string $id): ?array
    {
        return $this->getByUuid($id);
    }

    /**
     * Get all applications for a user.
     *
     * @param string $userId Zitadel user ID
     * @return array<int, array<string, mixed>> List of applications
     */
    public function getByUser(string $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, description, website, requested_scope, status,
                    reviewed_by, review_notes, reviewed_at, is_active, created_at, updated_at
             FROM applications
             WHERE zitadel_user_id = :user_id
             ORDER BY created_at DESC'
        );

        $stmt->execute(['user_id' => $userId]);

        /** @var array<int, array<string, mixed>> */
        return $stmt->fetchAll();
    }

    /**
     * Update an application.
     *
     * @param string $uuid Application UUID (id)
     * @param string $userId Owner's Zitadel user ID (for authorization)
     * @param array<string, mixed> $data Fields to update (name, description, website)
     * @return array<string, mixed>|null Updated application or null if not found/unauthorized
     */
    public function update(string $uuid, string $userId, array $data): ?array
    {
        $allowedFields = ['name', 'description', 'website'];
        $updates       = [];
        $params        = ['id' => $uuid, 'user_id' => $userId];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[]      = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($updates)) {
            // Verify ownership before returning record to prevent data leakage
            if (!$this->isOwner($uuid, $userId)) {
                return null;
            }
            return $this->getByUuid($uuid);
        }

        $updates[] = 'updated_at = CURRENT_TIMESTAMP';

        $sql = sprintf(
            'UPDATE applications SET %s WHERE id = :id AND zitadel_user_id = :user_id
             RETURNING id, name, description, website, requested_scope, status, reviewed_by, review_notes, reviewed_at, is_active, created_at, updated_at',
            implode(', ', $updates)
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch();

        return is_array($result) ? $result : null;
    }

    /**
     * Deactivate an application.
     *
     * This soft-deletes the application by setting is_active to false.
     * All associated API keys will also be invalidated.
     *
     * @param string $uuid Application UUID (id)
     * @param string $userId Owner's Zitadel user ID (for authorization)
     * @return bool True if deactivated, false if not found/unauthorized
     */
    public function deactivate(string $uuid, string $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE applications
             SET is_active = FALSE, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND zitadel_user_id = :user_id'
        );

        $stmt->execute(['id' => $uuid, 'user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Reactivate an application.
     *
     * @param string $uuid Application UUID (id)
     * @param string $userId Owner's Zitadel user ID (for authorization)
     * @return bool True if reactivated, false if not found/unauthorized
     */
    public function reactivate(string $uuid, string $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE applications
             SET is_active = TRUE, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND zitadel_user_id = :user_id'
        );

        $stmt->execute(['id' => $uuid, 'user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Delete an application permanently.
     *
     * This will cascade delete all associated API keys.
     *
     * @param string $uuid Application UUID (id)
     * @param string $userId Owner's Zitadel user ID (for authorization)
     * @return bool True if deleted, false if not found/unauthorized
     */
    public function delete(string $uuid, string $userId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM applications
             WHERE id = :id AND zitadel_user_id = :user_id'
        );

        $stmt->execute(['id' => $uuid, 'user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Check if a user owns an application.
     *
     * @param string $uuid Application UUID (id)
     * @param string $userId Zitadel user ID
     * @return bool True if the user owns the application
     */
    public function isOwner(string $uuid, string $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM applications
             WHERE id = :id AND zitadel_user_id = :user_id'
        );

        $stmt->execute(['id' => $uuid, 'user_id' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Count applications for a user.
     *
     * @param string $userId Zitadel user ID
     * @return int Number of applications
     */
    public function countByUser(string $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM applications WHERE zitadel_user_id = :user_id'
        );

        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    // ========================================
    // Admin Approval Methods
    // ========================================

    /**
     * Get all pending applications awaiting review.
     *
     * @return array<int, array<string, mixed>> List of pending applications
     */
    public function getPendingApplications(): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.id, a.zitadel_user_id, a.name, a.description, a.website, a.requested_scope,
                    a.status, a.is_active, a.created_at, a.updated_at
             FROM applications a
             WHERE a.status = :status
             ORDER BY a.created_at ASC'
        );

        $stmt->execute(['status' => self::STATUS_PENDING]);

        /** @var array<int, array<string, mixed>> */
        return $stmt->fetchAll();
    }

    /**
     * Get all applications with optional status filter.
     *
     * @param string|null $status Filter by status (pending, approved, rejected, revoked)
     * @return array<int, array<string, mixed>> List of applications
     * @throws \InvalidArgumentException If status is not a valid value
     */
    public function getAllApplications(?string $status = null): array
    {
        if ($status !== null) {
            if (!in_array($status, self::VALID_STATUSES, true)) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid status filter: %s. Valid values are: %s', $status, implode(', ', self::VALID_STATUSES))
                );
            }
            $stmt = $this->db->prepare(
                'SELECT a.id, a.zitadel_user_id, a.name, a.description, a.website, a.requested_scope,
                        a.status, a.reviewed_by, a.review_notes, a.reviewed_at,
                        a.is_active, a.created_at, a.updated_at
                 FROM applications a
                 WHERE a.status = :status
                 ORDER BY a.created_at DESC'
            );
            $stmt->execute(['status' => $status]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT a.id, a.zitadel_user_id, a.name, a.description, a.website, a.requested_scope,
                        a.status, a.reviewed_by, a.review_notes, a.reviewed_at,
                        a.is_active, a.created_at, a.updated_at
                 FROM applications a
                 ORDER BY a.created_at DESC'
            );
            $stmt->execute();
        }

        /** @var array<int, array<string, mixed>> */
        return $stmt->fetchAll();
    }

    /**
     * Count pending applications.
     *
     * @return int Number of pending applications
     */
    public function countPendingApplications(): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM applications WHERE status = :status'
        );

        $stmt->execute(['status' => self::STATUS_PENDING]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Approve an application.
     *
     * @param string $uuid Application UUID
     * @param string $adminId Admin's Zitadel user ID
     * @param string|null $notes Optional approval notes
     * @return array<string, mixed>|null Updated application or null if not found
     */
    public function approveApplication(string $uuid, string $adminId, ?string $notes = null): ?array
    {
        $stmt = $this->db->prepare(
            'UPDATE applications
             SET status = :status,
                 reviewed_by = :admin_id,
                 review_notes = :notes,
                 reviewed_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :uuid AND (status = :pending OR status = :rejected)
             RETURNING id, name, description, website, requested_scope, status, reviewed_by, review_notes, reviewed_at, is_active, created_at, updated_at'
        );

        $stmt->execute([
            'uuid'     => $uuid,
            'status'   => self::STATUS_APPROVED,
            'admin_id' => $adminId,
            'notes'    => $notes,
            'pending'  => self::STATUS_PENDING,
            'rejected' => self::STATUS_REJECTED,
        ]);

        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch();

        return is_array($result) ? $result : null;
    }

    /**
     * Reject an application.
     *
     * @param string $uuid Application UUID
     * @param string $adminId Admin's Zitadel user ID
     * @param string|null $notes Rejection reason/notes
     * @return array<string, mixed>|null Updated application or null if not found
     */
    public function rejectApplication(string $uuid, string $adminId, ?string $notes = null): ?array
    {
        $stmt = $this->db->prepare(
            'UPDATE applications
             SET status = :status,
                 reviewed_by = :admin_id,
                 review_notes = :notes,
                 reviewed_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :uuid AND status = :pending
             RETURNING id, name, description, website, requested_scope, status, reviewed_by, review_notes, reviewed_at, is_active, created_at, updated_at'
        );

        $stmt->execute([
            'uuid'     => $uuid,
            'status'   => self::STATUS_REJECTED,
            'admin_id' => $adminId,
            'notes'    => $notes,
            'pending'  => self::STATUS_PENDING,
        ]);

        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch();

        return is_array($result) ? $result : null;
    }

    /**
     * Revoke a previously approved application.
     *
     * @param string $uuid Application UUID
     * @param string $adminId Admin's Zitadel user ID
     * @param string|null $notes Revocation reason/notes
     * @return array<string, mixed>|null Updated application or null if not found
     */
    public function revokeApplication(string $uuid, string $adminId, ?string $notes = null): ?array
    {
        $stmt = $this->db->prepare(
            'UPDATE applications
             SET status = :status,
                 reviewed_by = :admin_id,
                 review_notes = :notes,
                 reviewed_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :uuid AND status = :approved
             RETURNING id, name, description, website, requested_scope, status, reviewed_by, review_notes, reviewed_at, is_active, created_at, updated_at'
        );

        $stmt->execute([
            'uuid'     => $uuid,
            'status'   => self::STATUS_REVOKED,
            'admin_id' => $adminId,
            'notes'    => $notes,
            'approved' => self::STATUS_APPROVED,
        ]);

        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch();

        return is_array($result) ? $result : null;
    }

    /**
     * Resubmit a rejected application for review.
     *
     * Resets the status to 'pending' so the developer can request re-review.
     *
     * @param string $uuid Application UUID
     * @param string $userId Owner's Zitadel user ID (for authorization)
     * @return array<string, mixed>|null Updated application or null if not found/unauthorized
     */
    public function resubmitApplication(string $uuid, string $userId): ?array
    {
        $stmt = $this->db->prepare(
            'UPDATE applications
             SET status = :new_status,
                 review_notes = NULL,
                 reviewed_by = NULL,
                 reviewed_at = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :uuid AND zitadel_user_id = :user_id AND status = :rejected
             RETURNING id, name, description, website, requested_scope, status, reviewed_by, review_notes, reviewed_at, is_active, created_at, updated_at'
        );

        $stmt->execute([
            'uuid'       => $uuid,
            'user_id'    => $userId,
            'new_status' => self::STATUS_PENDING,
            'rejected'   => self::STATUS_REJECTED,
        ]);

        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch();

        return is_array($result) ? $result : null;
    }

    /**
     * Check if an application is approved.
     *
     * @param string $uuid Application UUID
     * @return bool True if the application is approved and active
     */
    public function isApproved(string $uuid): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM applications
             WHERE id = :uuid AND status = :status AND is_active = TRUE'
        );

        $stmt->execute(['uuid' => $uuid, 'status' => self::STATUS_APPROVED]);

        return $stmt->fetchColumn() !== false;
    }
}
