<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Repositories;

use LiturgicalCalendar\Api\Database\Connection;
use PDO;

/**
 * Repository for audit logging.
 *
 * Records all significant actions for security auditing
 * and compliance purposes.
 */
class AuditLogRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Build WHERE clause and params from filters.
     *
     * @param array<string, mixed> $filters
     * @return array{whereClause: string, params: array<string, mixed>}
     */
    private function buildWhereClause(array $filters): array
    {
        $conditions = [];
        $params     = [];

        if (isset($filters['user_id'])) {
            $conditions[]      = 'zitadel_user_id = :user_id';
            $params['user_id'] = $filters['user_id'];
        }

        if (isset($filters['action'])) {
            $conditions[]     = 'action = :action';
            $params['action'] = $filters['action'];
        }

        if (isset($filters['resource_type'])) {
            $conditions[]            = 'resource_type = :resource_type';
            $params['resource_type'] = $filters['resource_type'];
        }

        if (isset($filters['resource_id'])) {
            $conditions[]          = 'resource_id = :resource_id';
            $params['resource_id'] = $filters['resource_id'];
        }

        if (isset($filters['success'])) {
            $conditions[]      = 'success = :success';
            $params['success'] = $filters['success'] ? 'true' : 'false';
        }

        if (isset($filters['from_date'])) {
            $conditions[]        = 'created_at >= :from_date';
            $params['from_date'] = $filters['from_date'];
        }

        if (isset($filters['to_date'])) {
            $conditions[]      = 'created_at <= :to_date';
            $params['to_date'] = $filters['to_date'];
        }

        if (isset($filters['ip_address'])) {
            $conditions[]         = 'ip_address = :ip_address';
            $params['ip_address'] = $filters['ip_address'];
        }

        $whereClause = !empty($conditions)
            ? 'WHERE ' . implode(' AND ', $conditions)
            : '';

        return ['whereClause' => $whereClause, 'params' => $params];
    }

    /**
     * Log an action.
     *
     * @param string|null $userId Zitadel user ID of the actor (null for anonymous)
     * @param string $action Action performed (e.g., 'login', 'create_application')
     * @param string $resourceType Type of resource (e.g., 'application', 'api_key')
     * @param string|null $resourceId Identifier of the affected resource
     * @param array<string, mixed>|null $details Additional details as JSON
     * @param string|null $ipAddress Client IP address
     * @param string|null $userAgent Client user agent
     * @param bool $success Whether the action was successful
     * @return int The ID of the log entry
     */
    public function log(
        ?string $userId,
        string $action,
        string $resourceType,
        ?string $resourceId = null,
        ?array $details = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        bool $success = true
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO audit_log
                (zitadel_user_id, action, resource_type, resource_id, details, ip_address, user_agent, success)
             VALUES
                (:user_id, :action, :resource_type, :resource_id, :details, :ip_address, :user_agent, :success)
             RETURNING id'
        );

        $stmt->execute([
            'user_id'       => $userId,
            'action'        => $action,
            'resource_type' => $resourceType,
            'resource_id'   => $resourceId,
            'details'       => $details !== null ? ( json_encode($details) ?: null ) : null,
            'ip_address'    => $ipAddress,
            'user_agent'    => $userAgent,
            'success'       => $success ? 'true' : 'false',
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Get audit logs with filtering.
     *
     * @param array<string, mixed> $filters Filtering options
     * @param int $limit Maximum number of records
     * @param int $offset Offset for pagination
     * @return array<int, array<string, mixed>> List of log entries
     */
    public function query(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        ['whereClause' => $whereClause, 'params' => $params] = $this->buildWhereClause($filters);

        $sql = sprintf(
            'SELECT id, zitadel_user_id, action, resource_type, resource_id,
                    details, ip_address, user_agent, success, created_at
             FROM audit_log
             %s
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset',
            $whereClause
        );

        $stmt = $this->db->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        /** @var array<int, array<string, mixed>> $results */
        $results = $stmt->fetchAll();

        // Decode JSON details
        foreach ($results as &$row) {
            if (isset($row['details']) && is_string($row['details'])) {
                $row['details'] = json_decode($row['details'], true);
            }
        }

        return $results;
    }

    /**
     * Get logs for a specific user.
     *
     * @param string $userId Zitadel user ID
     * @param int $limit Maximum number of records
     * @param int $offset Offset for pagination
     * @return array<int, array<string, mixed>> List of log entries
     */
    public function getByUser(string $userId, int $limit = 100, int $offset = 0): array
    {
        return $this->query(['user_id' => $userId], $limit, $offset);
    }

    /**
     * Get logs for a specific resource.
     *
     * @param string $resourceType Resource type
     * @param string $resourceId Resource identifier
     * @param int $limit Maximum number of records
     * @return array<int, array<string, mixed>> List of log entries
     */
    public function getByResource(string $resourceType, string $resourceId, int $limit = 100): array
    {
        return $this->query([
            'resource_type' => $resourceType,
            'resource_id'   => $resourceId,
        ], $limit);
    }

    /**
     * Get failed actions (for security monitoring).
     *
     * @param int $limit Maximum number of records
     * @param string|null $fromDate Start date (ISO 8601 format)
     * @return array<int, array<string, mixed>> List of failed actions
     */
    public function getFailedActions(int $limit = 100, ?string $fromDate = null): array
    {
        $filters = ['success' => false];
        if ($fromDate !== null) {
            $filters['from_date'] = $fromDate;
        }
        return $this->query($filters, $limit);
    }

    /**
     * Get login attempts for a user.
     *
     * @param string $userId Zitadel user ID
     * @param int $limit Maximum number of records
     * @return array<int, array<string, mixed>> List of login attempts
     */
    public function getLoginAttempts(string $userId, int $limit = 50): array
    {
        return $this->query([
            'user_id' => $userId,
            'action'  => 'login',
        ], $limit);
    }

    /**
     * Count logs matching filters.
     *
     * @param array<string, mixed> $filters Filtering options
     * @return int Number of matching records
     */
    public function count(array $filters = []): int
    {
        ['whereClause' => $whereClause, 'params' => $params] = $this->buildWhereClause($filters);

        $sql = sprintf('SELECT COUNT(*) FROM audit_log %s', $whereClause);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Purge old audit logs.
     *
     * @param int $daysToKeep Number of days of logs to retain
     * @return int Number of records deleted
     */
    public function purgeOld(int $daysToKeep = 365): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM audit_log
             WHERE created_at < CURRENT_TIMESTAMP - make_interval(days => :days)'
        );

        $stmt->execute(['days' => $daysToKeep]);

        return $stmt->rowCount();
    }

    /**
     * Log a successful login.
     *
     * @param string $userId Zitadel user ID
     * @param string|null $ipAddress Client IP
     * @param string|null $userAgent Client user agent
     */
    public function logLogin(string $userId, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        $this->log($userId, 'login', 'session', null, null, $ipAddress, $userAgent, true);
    }

    /**
     * Log a failed login attempt.
     *
     * @param string|null $attemptedUserId Attempted user ID (if known)
     * @param string|null $ipAddress Client IP
     * @param string|null $userAgent Client user agent
     * @param array<string, mixed>|null $details Additional details (e.g., reason for failure)
     */
    public function logFailedLogin(
        ?string $attemptedUserId,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?array $details = null
    ): void {
        $this->log($attemptedUserId, 'login', 'session', null, $details, $ipAddress, $userAgent, false);
    }

    /**
     * Log a logout.
     *
     * @param string $userId Zitadel user ID
     * @param string|null $ipAddress Client IP
     */
    public function logLogout(string $userId, ?string $ipAddress = null): void
    {
        $this->log($userId, 'logout', 'session', null, null, $ipAddress, null, true);
    }
}
