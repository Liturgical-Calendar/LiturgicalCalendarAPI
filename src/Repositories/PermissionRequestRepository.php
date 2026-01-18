<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Repositories;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Enum\CalendarType;
use LiturgicalCalendar\Api\Enum\PermissionLevel;
use PDO;

/**
 * Repository for managing permission request workflow.
 *
 * Handles the lifecycle of permission requests from creation
 * through approval or rejection.
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
     * @param string $userId Zitadel user ID making the request
     * @param string $userEmail User's email (for display in admin UI)
     * @param string $userName User's name (for display in admin UI)
     * @param CalendarType $calendarType Type of calendar
     * @param string $calendarId Calendar identifier
     * @param string|null $justification Reason for requesting access
     * @param string|null $credentials User's credentials/affiliation
     * @return int The ID of the created request
     */
    public function createRequest(
        string $userId,
        string $userEmail,
        string $userName,
        CalendarType $calendarType,
        string $calendarId,
        ?string $justification = null,
        ?string $credentials = null
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO permission_requests
                (zitadel_user_id, user_email, user_name, calendar_type, calendar_id, justification, credentials)
             VALUES (:user_id, :user_email, :user_name, :calendar_type, :calendar_id, :justification, :credentials)
             RETURNING id'
        );

        $stmt->execute([
            'user_id'       => $userId,
            'user_email'    => $userEmail,
            'user_name'     => $userName,
            'calendar_type' => $calendarType->value,
            'calendar_id'   => $calendarId,
            'justification' => $justification,
            'credentials'   => $credentials,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Get a permission request by ID.
     *
     * @param int $id Request ID
     * @return array<string, mixed>|null The request data or null if not found
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM permission_requests WHERE id = :id'
        );

        $stmt->execute(['id' => $id]);
        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch();

        return is_array($result) ? $result : null;
    }

    /**
     * Get all pending permission requests.
     *
     * @return array<int, array<string, mixed>> List of pending requests
     */
    public function getPendingRequests(): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM permission_requests
             WHERE status = 'pending'
             ORDER BY created_at ASC"
        );

        $stmt->execute();

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
            'SELECT * FROM permission_requests
             WHERE zitadel_user_id = :user_id
             ORDER BY created_at DESC'
        );

        $stmt->execute(['user_id' => $userId]);

        /** @var array<int, array<string, mixed>> */
        return $stmt->fetchAll();
    }

    /**
     * Approve a permission request.
     *
     * This updates the request status and grants the permission.
     *
     * @param int $requestId Request ID
     * @param string $reviewedBy Zitadel user ID of the admin approving
     * @param string|null $notes Optional review notes
     * @param PermissionLevel $permissionLevel Permission level to grant (defaults to WRITE)
     * @return bool True if approved successfully
     */
    public function approveRequest(
        int $requestId,
        string $reviewedBy,
        ?string $notes = null,
        PermissionLevel $permissionLevel = PermissionLevel::WRITE
    ): bool {
        $this->db->beginTransaction();

        try {
            // Update request status
            $stmt = $this->db->prepare(
                "UPDATE permission_requests
                 SET status = 'approved',
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

            if ($stmt->rowCount() === 0) {
                $this->db->rollBack();
                return false;
            }

            // Get request details
            $request = $this->getById($requestId);
            if ($request === null) {
                $this->db->rollBack();
                return false;
            }

            // Validate required fields
            $zitadelUserId   = isset($request['zitadel_user_id']) && is_string($request['zitadel_user_id'])
                ? $request['zitadel_user_id']
                : null;
            $calendarTypeStr = isset($request['calendar_type']) && is_string($request['calendar_type'])
                ? $request['calendar_type']
                : null;
            $calendarId      = isset($request['calendar_id']) && is_string($request['calendar_id'])
                ? $request['calendar_id']
                : null;

            if ($zitadelUserId === null || $calendarTypeStr === null || $calendarId === null) {
                $this->db->rollBack();
                return false;
            }

            // Convert string to enum
            $calendarType = CalendarType::tryFrom($calendarTypeStr);
            if ($calendarType === null) {
                $this->db->rollBack();
                return false;
            }

            // Grant the permission
            $permRepo = new CalendarPermissionRepository($this->db);
            $permRepo->grantPermission(
                $zitadelUserId,
                $calendarType,
                $calendarId,
                $permissionLevel,
                $reviewedBy
            );

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Reject a permission request.
     *
     * @param int $requestId Request ID
     * @param string $reviewedBy Zitadel user ID of the admin rejecting
     * @param string|null $notes Reason for rejection
     * @return bool True if rejected successfully
     */
    public function rejectRequest(int $requestId, string $reviewedBy, ?string $notes = null): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE permission_requests
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
     * Check if a user has a pending request for a specific calendar.
     *
     * @param string $userId Zitadel user ID
     * @param CalendarType $calendarType Type of calendar
     * @param string $calendarId Calendar identifier
     * @return bool True if a pending request exists
     */
    public function hasPendingRequest(string $userId, CalendarType $calendarType, string $calendarId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM permission_requests
             WHERE zitadel_user_id = :user_id
               AND calendar_type = :calendar_type
               AND calendar_id = :calendar_id
               AND status = 'pending'"
        );

        $stmt->execute([
            'user_id'       => $userId,
            'calendar_type' => $calendarType->value,
            'calendar_id'   => $calendarId,
        ]);

        return $stmt->fetchColumn() !== false;
    }
}
