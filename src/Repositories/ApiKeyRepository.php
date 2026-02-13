<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Repositories;

use LiturgicalCalendar\Api\Database\Connection;
use PDO;

/**
 * Repository for managing API keys.
 *
 * API keys are generated for applications and used to authenticate
 * API requests. Keys are stored as hashes for security.
 */
class ApiKeyRepository
{
    private PDO $db;

    /**
     * API key prefix for identification.
     */
    private const KEY_PREFIX = 'litcal';

    /**
     * Length of the random portion of the key.
     */
    private const KEY_RANDOM_LENGTH = 32;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Generate a new API key for an application.
     *
     * @param string $applicationId Application UUID
     * @param string|null $name Optional name for the key
     * @param string $scope Permission scope: 'read' or 'write'
     * @param int $rateLimitPerHour Rate limit per hour
     * @param \DateTimeInterface|null $expiresAt Optional expiration date
     * @return array{key: string, record: array<string, mixed>|false} Contains 'key' (plain text, show once) and 'record' (database record)
     */
    public function generate(
        string $applicationId,
        ?string $name = null,
        string $scope = 'read',
        int $rateLimitPerHour = 1000,
        ?\DateTimeInterface $expiresAt = null
    ): array {
        // Generate the key
        $environment = getenv('APP_ENV') === 'production' ? 'live' : 'test';
        $randomPart  = bin2hex(random_bytes(self::KEY_RANDOM_LENGTH / 2));
        $plainKey    = sprintf('%s_%s_%s', self::KEY_PREFIX, $environment, $randomPart);

        // Hash for storage
        $keyHash   = hash('sha256', $plainKey);
        $keyPrefix = substr($plainKey, 0, 20);

        $stmt = $this->db->prepare(
            'INSERT INTO api_keys (application_id, key_hash, key_prefix, name, scope, rate_limit_per_hour, expires_at)
             VALUES (:app_id, :key_hash, :key_prefix, :name, :scope, :rate_limit, :expires_at)
             RETURNING id, key_prefix, name, scope, rate_limit_per_hour, is_active, expires_at, created_at'
        );

        $stmt->execute([
            'app_id'     => $applicationId,
            'key_hash'   => $keyHash,
            'key_prefix' => $keyPrefix,
            'name'       => $name,
            'scope'      => $scope,
            'rate_limit' => $rateLimitPerHour,
            'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
        ]);

        /** @var array<string, mixed>|false $record */
        $record = $stmt->fetch();

        return [
            'key'    => $plainKey,  // Only returned once, never stored
            'record' => $record,
        ];
    }

    /**
     * Validate an API key and return its details.
     *
     * @param string $plainKey The plain text API key
     * @return array<string, mixed>|null Key details with application info, or null if invalid
     */
    public function validate(string $plainKey): ?array
    {
        $keyHash = hash('sha256', $plainKey);

        $stmt = $this->db->prepare(
            'SELECT k.id, k.application_id, k.key_prefix, k.name, k.scope,
                    k.rate_limit_per_hour, k.is_active, k.last_used_at, k.expires_at,
                    a.id AS app_uuid, a.name AS app_name, a.zitadel_user_id,
                    a.is_active AS app_is_active, a.status AS app_status
             FROM api_keys k
             JOIN applications a ON k.application_id = a.id
             WHERE k.key_hash = :key_hash'
        );

        $stmt->execute(['key_hash' => $keyHash]);
        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch();

        if (!is_array($result)) {
            return null;
        }

        // Check if key and application are active, and application is approved
        if (empty($result['is_active']) || empty($result['app_is_active'])) {
            return null;
        }

        // Check application status (only approved applications can use API keys)
        if (!isset($result['app_status']) || $result['app_status'] !== 'approved') {
            return null;
        }

        // Check expiration (using Europe/Vatican timezone for consistency)
        if ($result['expires_at'] !== null && is_string($result['expires_at'])) {
            $tz        = new \DateTimeZone('Europe/Vatican');
            $expiresAt = new \DateTimeImmutable($result['expires_at'], $tz);
            if ($expiresAt < new \DateTimeImmutable('now', $tz)) {
                return null;
            }
        }

        // Update last used timestamp
        if (isset($result['id']) && is_string($result['id'])) {
            $this->updateLastUsed($result['id']);
        }

        return $result;
    }

    /**
     * Update the last_used_at timestamp for a key.
     *
     * @param string $keyId Key UUID
     */
    private function updateLastUsed(string $keyId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE api_keys SET last_used_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $stmt->execute(['id' => $keyId]);
    }

    /**
     * Get all keys for an application.
     *
     * @param string $applicationId Application UUID
     * @return array<int, array<string, mixed>> List of keys (without hashes)
     */
    public function getByApplication(string $applicationId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, key_prefix, name, scope, rate_limit_per_hour, is_active,
                    last_used_at, expires_at, created_at
             FROM api_keys
             WHERE application_id = :app_id
             ORDER BY created_at DESC'
        );

        $stmt->execute(['app_id' => $applicationId]);

        /** @var array<int, array<string, mixed>> */
        return $stmt->fetchAll();
    }

    /**
     * Get a key by ID.
     *
     * @param string $id Key UUID
     * @return array<string, mixed>|null Key details or null if not found
     */
    public function getById(string $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT k.id, k.application_id, k.key_prefix, k.name, k.scope,
                    k.rate_limit_per_hour, k.is_active, k.last_used_at, k.expires_at,
                    k.created_at, a.zitadel_user_id
             FROM api_keys k
             JOIN applications a ON k.application_id = a.id
             WHERE k.id = :id'
        );

        $stmt->execute(['id' => $id]);
        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch();

        return is_array($result) ? $result : null;
    }

    /**
     * Revoke (deactivate) an API key.
     *
     * @param string $keyId Key UUID
     * @param string $userId Owner's Zitadel user ID (for authorization)
     * @return bool True if revoked, false if not found/unauthorized
     */
    public function revoke(string $keyId, string $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE api_keys k
             SET is_active = FALSE
             FROM applications a
             WHERE k.id = :key_id
               AND k.application_id = a.id
               AND a.zitadel_user_id = :user_id'
        );

        $stmt->execute(['key_id' => $keyId, 'user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Delete an API key permanently.
     *
     * @param string $keyId Key UUID
     * @param string $userId Owner's Zitadel user ID (for authorization)
     * @param string $appId Application UUID (to prevent cross-application deletion)
     * @return bool True if deleted, false if not found/unauthorized
     */
    public function delete(string $keyId, string $userId, string $appId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM api_keys k
             USING applications a
             WHERE k.id = :key_id
               AND k.application_id = a.id
               AND a.id = :app_id
               AND a.zitadel_user_id = :user_id'
        );

        $stmt->execute(['key_id' => $keyId, 'user_id' => $userId, 'app_id' => $appId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Rotate an API key (revoke old, create new).
     *
     * @param string $keyId Current key UUID
     * @param string $userId Owner's Zitadel user ID (for authorization)
     * @return array{key: string, record: array<string, mixed>|false}|null New key details or null if failed
     */
    public function rotate(string $keyId, string $userId): ?array
    {
        $oldKey = $this->getById($keyId);

        if (!$oldKey || $oldKey['zitadel_user_id'] !== $userId) {
            return null;
        }

        // Validate required fields before starting transaction
        if (!isset($oldKey['application_id']) || !is_string($oldKey['application_id'])) {
            throw new \InvalidArgumentException(
                'Cannot rotate API key: missing or invalid application_id'
            );
        }

        $this->db->beginTransaction();

        try {
            // Revoke old key
            $this->revoke($keyId, $userId);

            // Generate new key with same settings (using Europe/Vatican timezone for consistency)
            $expiresAtValue = $oldKey['expires_at'];
            $expiresAt      = null;
            if (is_string($expiresAtValue)) {
                $tz        = new \DateTimeZone('Europe/Vatican');
                $expiresAt = new \DateTimeImmutable($expiresAtValue, $tz);
            }

            $applicationId = $oldKey['application_id'];
            $oldName       = is_string($oldKey['name']) ? $oldKey['name'] : null;
            $scope         = is_string($oldKey['scope']) ? $oldKey['scope'] : 'read';
            $rateLimit     = is_int($oldKey['rate_limit_per_hour']) ? $oldKey['rate_limit_per_hour'] : 1000;

            $newKey = $this->generate(
                $applicationId,
                $oldName !== null ? $oldName . ' (rotated)' : null,
                $scope,
                $rateLimit,
                $expiresAt
            );

            $this->db->commit();

            return $newKey;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Count active keys for an application.
     *
     * @param string $applicationId Application UUID
     * @return int Number of active keys
     */
    public function countActiveByApplication(string $applicationId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM api_keys
             WHERE application_id = :app_id AND is_active = TRUE'
        );

        $stmt->execute(['app_id' => $applicationId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Count active keys for multiple applications in a single query.
     *
     * Avoids N+1 queries when listing applications with their key counts.
     *
     * @param array<string> $applicationIds List of application UUIDs
     * @return array<string, int> Map of application_id => count
     */
    public function countActiveByApplications(array $applicationIds): array
    {
        if (empty($applicationIds)) {
            return [];
        }

        // Build placeholders for IN clause
        $placeholders = [];
        $params       = [];
        foreach ($applicationIds as $i => $id) {
            $placeholder           = ":app_id_{$i}";
            $placeholders[]        = $placeholder;
            $params["app_id_{$i}"] = $id;
        }

        $inClause = implode(', ', $placeholders);
        $stmt     = $this->db->prepare(
            "SELECT application_id, COUNT(*) as count FROM api_keys
             WHERE application_id IN ({$inClause}) AND is_active = TRUE
             GROUP BY application_id"
        );

        $stmt->execute($params);

        $counts = [];
        // Initialize all IDs with 0 (applications with no keys won't be in result)
        foreach ($applicationIds as $id) {
            $counts[$id] = 0;
        }

        while ($row = $stmt->fetch()) {
            if (is_array($row) && isset($row['application_id'], $row['count'])) {
                $appId = $row['application_id'];
                $count = $row['count'];
                if (is_string($appId) && ( is_int($count) || is_string($count) )) {
                    $counts[$appId] = (int) $count;
                }
            }
        }

        return $counts;
    }

    /**
     * Get usage statistics for a key.
     *
     * @param string $keyId Key UUID
     * @return array<string, mixed> Usage statistics
     */
    public function getUsageStats(string $keyId): array
    {
        $key = $this->getById($keyId);

        if (!$key) {
            return [];
        }

        return [
            'key_id'              => $keyId,
            'key_prefix'          => $key['key_prefix'],
            'last_used_at'        => $key['last_used_at'],
            'is_active'           => $key['is_active'],
            'expires_at'          => $key['expires_at'],
            'rate_limit_per_hour' => $key['rate_limit_per_hour'],
        ];
    }
}
