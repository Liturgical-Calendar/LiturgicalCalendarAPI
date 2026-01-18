<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Repositories;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Enum\CalendarType;
use LiturgicalCalendar\Api\Enum\PermissionLevel;
use PDO;

/**
 * Repository for managing calendar-specific permissions.
 *
 * Permissions stored here are in addition to Zitadel roles.
 * A user with the 'calendar_editor' role in Zitadel still needs
 * specific calendar permissions to edit a particular calendar.
 */
class CalendarPermissionRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Check if a user has a specific permission for a calendar.
     *
     * @param string $userId Zitadel user ID (sub claim)
     * @param CalendarType $calendarType Type of calendar
     * @param string $calendarId Calendar identifier: 'USA', 'BOSTON', 'Americas', etc.
     * @param PermissionLevel $permission Permission level
     */
    public function hasPermission(
        string $userId,
        CalendarType $calendarType,
        string $calendarId,
        PermissionLevel $permission
    ): bool {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM user_calendar_permissions
             WHERE zitadel_user_id = :user_id
               AND calendar_type = :calendar_type
               AND calendar_id = :calendar_id
               AND permission = :permission'
        );

        $stmt->execute([
            'user_id'       => $userId,
            'calendar_type' => $calendarType->value,
            'calendar_id'   => $calendarId,
            'permission'    => $permission->value,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Get all permissions for a user.
     *
     * @param string $userId Zitadel user ID
     * @return array<int, array{calendar_type: string, calendar_id: string, permission: string, granted_at: string}>
     */
    public function getPermissionsForUser(string $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT calendar_type, calendar_id, permission, granted_at
             FROM user_calendar_permissions
             WHERE zitadel_user_id = :user_id
             ORDER BY calendar_type, calendar_id'
        );

        $stmt->execute(['user_id' => $userId]);

        /** @var array<int, array{calendar_type: string, calendar_id: string, permission: string, granted_at: string}> */
        return $stmt->fetchAll();
    }

    /**
     * Get all users with permissions for a specific calendar.
     *
     * @param CalendarType $calendarType Type of calendar
     * @param string $calendarId Calendar identifier
     * @return array<int, array{zitadel_user_id: string, permission: string, granted_at: string, granted_by: string|null}>
     */
    public function getUsersForCalendar(CalendarType $calendarType, string $calendarId): array
    {
        $stmt = $this->db->prepare(
            'SELECT zitadel_user_id, permission, granted_at, granted_by
             FROM user_calendar_permissions
             WHERE calendar_type = :calendar_type
               AND calendar_id = :calendar_id
             ORDER BY granted_at DESC'
        );

        $stmt->execute([
            'calendar_type' => $calendarType->value,
            'calendar_id'   => $calendarId,
        ]);

        /** @var array<int, array{zitadel_user_id: string, permission: string, granted_at: string, granted_by: string|null}> */
        return $stmt->fetchAll();
    }

    /**
     * Grant a permission to a user for a specific calendar.
     *
     * @param string $userId Zitadel user ID to grant permission to
     * @param CalendarType $calendarType Type of calendar
     * @param string $calendarId Calendar identifier
     * @param PermissionLevel $permission Permission level
     * @param string|null $grantedBy Zitadel user ID of the admin granting the permission
     * @return bool True if permission was granted, false if already exists
     */
    public function grantPermission(
        string $userId,
        CalendarType $calendarType,
        string $calendarId,
        PermissionLevel $permission,
        ?string $grantedBy = null
    ): bool {
        $stmt = $this->db->prepare(
            'INSERT INTO user_calendar_permissions
                (zitadel_user_id, calendar_type, calendar_id, permission, granted_by)
             VALUES (:user_id, :calendar_type, :calendar_id, :permission, :granted_by)
             ON CONFLICT (zitadel_user_id, calendar_type, calendar_id, permission) DO NOTHING'
        );

        $stmt->execute([
            'user_id'       => $userId,
            'calendar_type' => $calendarType->value,
            'calendar_id'   => $calendarId,
            'permission'    => $permission->value,
            'granted_by'    => $grantedBy,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Revoke a permission from a user for a specific calendar.
     *
     * @param string $userId Zitadel user ID
     * @param CalendarType $calendarType Type of calendar
     * @param string $calendarId Calendar identifier
     * @param PermissionLevel $permission Permission level
     * @return bool True if permission was revoked, false if it didn't exist
     */
    public function revokePermission(
        string $userId,
        CalendarType $calendarType,
        string $calendarId,
        PermissionLevel $permission
    ): bool {
        $stmt = $this->db->prepare(
            'DELETE FROM user_calendar_permissions
             WHERE zitadel_user_id = :user_id
               AND calendar_type = :calendar_type
               AND calendar_id = :calendar_id
               AND permission = :permission'
        );

        $stmt->execute([
            'user_id'       => $userId,
            'calendar_type' => $calendarType->value,
            'calendar_id'   => $calendarId,
            'permission'    => $permission->value,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Revoke all permissions for a user.
     *
     * @param string $userId Zitadel user ID
     * @return int Number of permissions revoked
     */
    public function revokeAllPermissionsForUser(string $userId): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM user_calendar_permissions
             WHERE zitadel_user_id = :user_id'
        );

        $stmt->execute(['user_id' => $userId]);

        return $stmt->rowCount();
    }
}
