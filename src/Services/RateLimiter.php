<?php

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Simple file-based rate limiter for brute-force protection
 *
 * Tracks failed attempts by identifier (typically IP address) and blocks
 * further attempts after exceeding the configured threshold within the
 * time window.
 *
 * @package LiturgicalCalendar\Api\Services
 */
class RateLimiter
{
    private string $storagePath;
    private int $maxAttempts;
    private int $windowSeconds;
    private LoggerInterface $logger;

    /**
     * Create a new rate limiter instance
     *
     * @param int $maxAttempts Maximum attempts allowed within the window (default: 5)
     * @param int $windowSeconds Time window in seconds (default: 900 = 15 minutes)
     * @param string|null $storagePath Path to store rate limit data (default: system temp dir)
     */
    public function __construct(
        int $maxAttempts = 5,
        int $windowSeconds = 900,
        ?string $storagePath = null
    ) {
        if ($maxAttempts <= 0 || $windowSeconds <= 0) {
            throw new \InvalidArgumentException('maxAttempts and windowSeconds must be positive.');
        }
        $this->maxAttempts   = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
        $this->storagePath   = $storagePath ?? sys_get_temp_dir();
        $this->logger        = LoggerFactory::create('rate-limiter', null, 30, false, true, false);

        // Ensure the rate limit directory exists
        $rateLimitDir = $this->getRateLimitDir();
        if (!is_dir($rateLimitDir) && !@mkdir($rateLimitDir, 0750, true) && !is_dir($rateLimitDir)) {
            throw new \RuntimeException(sprintf('Failed to create rate limit directory "%s".', $rateLimitDir));
        }
    }

    /**
     * Get the directory for rate limit files
     *
     * @return string
     */
    private function getRateLimitDir(): string
    {
        return rtrim($this->storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'litcal_rate_limits';
    }

    /**
     * Get the file path for a specific identifier
     *
     * @param string $identifier The identifier (e.g., IP address)
     * @return string
     */
    private function getFilePath(string $identifier): string
    {
        // Use hash to avoid filesystem issues with special characters in identifiers
        $hash = hash('sha256', $identifier);
        return $this->getRateLimitDir() . DIRECTORY_SEPARATOR . $hash . '.json';
    }

    /**
     * Get the lock file path for a specific identifier
     *
     * @param string $identifier The identifier (e.g., IP address)
     * @return string
     */
    private function getLockFilePath(string $identifier): string
    {
        $hash = hash('sha256', $identifier);
        return $this->getRateLimitDir() . DIRECTORY_SEPARATOR . $hash . '.lock';
    }

    /**
     * Check if an identifier is currently rate limited
     *
     * @param string $identifier The identifier to check (e.g., IP address)
     * @return bool True if rate limited (should block), false if allowed
     */
    public function isRateLimited(string $identifier): bool
    {
        $data = $this->loadData($identifier);

        if ($data === null) {
            return false;
        }

        // Clean up old attempts outside the window
        $cutoff           = time() - $this->windowSeconds;
        $data['attempts'] = array_filter($data['attempts'], fn($timestamp) => $timestamp > $cutoff);

        // Check if attempts exceed the limit
        return count($data['attempts']) >= $this->maxAttempts;
    }

    /**
     * Record a request for an identifier.
     *
     * General-purpose alias for recordFailedAttempt() suitable for API key
     * rate limiting where every request counts, not just failures.
     *
     * @param string $identifier The identifier (e.g., API key ID, IP address)
     * @return void
     */
    public function recordRequest(string $identifier): void
    {
        $this->recordFailedAttempt($identifier);
    }

    /**
     * Atomically record a request and return the new count within the window.
     *
     * Uses file locking to prevent TOCTOU race conditions between checking
     * the count and recording the request. When a cap is provided, the request
     * is recorded only when the current count is at or below the cap (allowing
     * one overflow so callers can detect the breach), then stops recording to
     * prevent unbounded file growth.
     *
     * @param string $identifier The identifier (e.g., API key ID, IP address)
     * @param int|null $cap Maximum allowed count; records up to cap+1 (one overflow) so callers
     *                      can detect the limit was exceeded, then stops recording to prevent unbounded growth
     * @return int The count of attempts within the window after recording
     */
    public function recordRequestAndGetCount(string $identifier, ?int $cap = null): int
    {
        $lockFile   = $this->getLockFilePath($identifier);
        $lockHandle = @fopen($lockFile, 'c');

        if ($lockHandle === false) {
            $this->logger->error('Failed to open lock file, falling back to non-atomic operation', [
                'identifier_hash' => hash('sha256', $identifier),
            ]);
            return $this->recordFailedAttemptUnsafe($identifier, $cap);
        }

        try {
            if (flock($lockHandle, LOCK_EX)) {
                try {
                    $data   = $this->loadData($identifier) ?? ['attempts' => []];
                    $cutoff = time() - $this->windowSeconds;
                    /** @var int[] $attempts */
                    $attempts = array_values(array_filter($data['attempts'], fn($timestamp) => $timestamp > $cutoff));

                    // Record up to cap+1: allow one overflow so the caller sees count > cap
                    // and can trigger a 429, then stop recording to prevent unbounded growth
                    if ($cap === null || count($attempts) <= $cap) {
                        $attempts[] = time();
                        $this->saveData($identifier, ['attempts' => $attempts]);
                    }
                    return count($attempts);
                } finally {
                    flock($lockHandle, LOCK_UN);
                }
            } else {
                $this->logger->error('Failed to acquire lock, falling back to non-atomic operation', [
                    'identifier_hash' => hash('sha256', $identifier),
                ]);
                return $this->recordFailedAttemptUnsafe($identifier, $cap);
            }
        } finally {
            fclose($lockHandle);
        }
    }

    /**
     * Get the UNIX timestamp when the current rate limit window resets.
     *
     * Returns the time when the oldest attempt in the window will expire,
     * or the current time if there are no recorded attempts.
     *
     * @param string $identifier The identifier (e.g., API key ID, IP address)
     * @return int UNIX timestamp of window reset
     */
    public function getWindowResetTime(string $identifier): int
    {
        $data = $this->loadData($identifier);
        if ($data === null || empty($data['attempts'])) {
            return time();
        }

        $cutoff = time() - $this->windowSeconds;
        /** @var int[] $attempts */
        $attempts = array_filter($data['attempts'], fn($timestamp) => $timestamp > $cutoff);
        if (empty($attempts)) {
            return time();
        }

        // The window resets when the oldest attempt expires
        return min($attempts) + $this->windowSeconds;
    }

    /**
     * Record a failed attempt for an identifier
     *
     * Uses file locking to prevent race conditions when multiple processes
     * attempt to record failed attempts for the same identifier concurrently.
     *
     * @param string $identifier The identifier (e.g., IP address)
     * @return void
     */
    public function recordFailedAttempt(string $identifier): void
    {
        $lockFile   = $this->getLockFilePath($identifier);
        $lockHandle = @fopen($lockFile, 'c');

        if ($lockHandle === false) {
            // If we can't create a lock file, fall back to non-atomic operation
            // TODO: Consider logging this fallback for observability
            $this->recordFailedAttemptUnsafe($identifier);
            return;
        }

        try {
            // Acquire exclusive lock (blocking)
            if (flock($lockHandle, LOCK_EX)) {
                try {
                    $this->recordFailedAttemptUnsafe($identifier);
                } finally {
                    flock($lockHandle, LOCK_UN);
                }
            } else {
                // If locking fails, fall back to non-atomic operation
                // TODO: Consider logging this fallback for observability
                $this->recordFailedAttemptUnsafe($identifier);
            }
        } finally {
            fclose($lockHandle);
        }
    }

    /**
     * Record a failed attempt without locking (internal use).
     *
     * Returns the count of attempts within the window after recording,
     * so callers in the fallback path can use the in-memory count
     * without a separate file read. Mirrors the cap behavior of
     * recordRequestAndGetCount (allows one overflow, then stops).
     *
     * @param string $identifier The identifier (e.g., IP address)
     * @param int|null $cap Maximum allowed count (allows one overflow, then stops recording)
     * @return int The count of attempts within the window after recording
     */
    private function recordFailedAttemptUnsafe(string $identifier, ?int $cap = null): int
    {
        $data = $this->loadData($identifier) ?? ['attempts' => []];

        // Clean up old attempts outside the window
        $cutoff           = time() - $this->windowSeconds;
        $data['attempts'] = array_values(array_filter($data['attempts'], fn($timestamp) => $timestamp > $cutoff));

        // Record up to cap+1 (same overflow behavior as the locked path)
        if ($cap === null || count($data['attempts']) <= $cap) {
            $data['attempts'][] = time();
        }

        $this->saveData($identifier, $data);
        return count($data['attempts']);
    }

    /**
     * Clear rate limit data for an identifier (e.g., after successful login)
     *
     * @param string $identifier The identifier (e.g., IP address)
     * @return void
     */
    public function clearAttempts(string $identifier): void
    {
        $filePath = $this->getFilePath($identifier);
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
        $lockFile = $this->getLockFilePath($identifier);
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }
    }

    /**
     * Get the number of seconds until the rate limit resets
     *
     * @param string $identifier The identifier (e.g., IP address)
     * @return int Seconds until reset, 0 if not rate limited
     */
    public function getRetryAfter(string $identifier): int
    {
        $data = $this->loadData($identifier);

        if ($data === null || empty($data['attempts'])) {
            return 0;
        }

        // Clean up old attempts
        $cutoff   = time() - $this->windowSeconds;
        $attempts = array_filter($data['attempts'], fn($timestamp) => $timestamp > $cutoff);

        if (count($attempts) < $this->maxAttempts) {
            return 0;
        }

        // Find the oldest attempt within the window
        // Note: $attempts cannot be empty here since count($attempts) >= $this->maxAttempts >= 1
        /** @var non-empty-array<int> $attempts */
        $oldestAttempt = min($attempts);

        // Calculate when it will expire
        $expiresAt = $oldestAttempt + $this->windowSeconds;

        return max(0, $expiresAt - time());
    }

    /**
     * Get the number of recorded attempts within the current window.
     *
     * @param string $identifier The identifier (e.g., API key ID, IP address)
     * @return int Number of attempts in the current window
     */
    public function getAttemptCount(string $identifier): int
    {
        $data = $this->loadData($identifier);

        if ($data === null) {
            return 0;
        }

        $cutoff = time() - $this->windowSeconds;
        return count(array_filter($data['attempts'], fn($timestamp) => $timestamp > $cutoff));
    }

    /**
     * Get the number of remaining attempts
     *
     * @param string $identifier The identifier (e.g., IP address)
     * @return int Number of remaining attempts
     */
    public function getRemainingAttempts(string $identifier): int
    {
        $data = $this->loadData($identifier);

        if ($data === null) {
            return $this->maxAttempts;
        }

        // Clean up old attempts
        $cutoff   = time() - $this->windowSeconds;
        $attempts = array_filter($data['attempts'], fn($timestamp) => $timestamp > $cutoff);

        return max(0, $this->maxAttempts - count($attempts));
    }

    /**
     * Load rate limit data for an identifier
     *
     * @param string $identifier The identifier
     * @return array{attempts: int[]}|null The data or null if not found
     */
    private function loadData(string $identifier): ?array
    {
        $filePath = $this->getFilePath($identifier);

        if (!file_exists($filePath)) {
            return null;
        }

        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);
        if (!is_array($data) || !isset($data['attempts']) || !is_array($data['attempts'])) {
            return null;
        }

        // Ensure attempts are integers, filtering out non-numeric values
        $attempts = [];
        foreach ($data['attempts'] as $val) {
            if (is_numeric($val)) {
                $attempts[] = (int) $val;
            }
        }

        return ['attempts' => $attempts];
    }

    /**
     * Save rate limit data for an identifier
     *
     * @param string $identifier The identifier
     * @param array{attempts: int[]} $data The data to save
     * @return void
     */
    private function saveData(string $identifier, array $data): void
    {
        $filePath = $this->getFilePath($identifier);

        // Re-index array to ensure JSON array format
        $data['attempts'] = array_values($data['attempts']);

        file_put_contents($filePath, json_encode($data), LOCK_EX);
    }

    /**
     * Clean up stale rate limit files
     *
     * This method can be called periodically to remove old rate limit files
     * that are no longer needed.
     *
     * @return int Number of files cleaned up
     */
    public function cleanup(): int
    {
        $dir = $this->getRateLimitDir();
        if (!is_dir($dir)) {
            return 0;
        }

        $cleaned = 0;
        $cutoff  = time() - $this->windowSeconds;

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json');
        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            // Check file modification time first as a quick filter
            $mtime = filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                // File is old enough that all attempts must have expired
                unlink($file);
                $lockFile = str_replace('.json', '.lock', $file);
                if (file_exists($lockFile)) {
                    @unlink($lockFile);
                }
                $cleaned++;
                continue;
            }

            // For newer files, check the actual data
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            $data = json_decode($contents, true);
            if (!is_array($data) || !isset($data['attempts']) || !is_array($data['attempts'])) {
                // Invalid data, remove the file and its lock file
                unlink($file);
                $lockFile = str_replace('.json', '.lock', $file);
                if (file_exists($lockFile)) {
                    @unlink($lockFile);
                }
                $cleaned++;
                continue;
            }

            // Filter attempts to only keep those within the window
            $validAttempts = array_filter($data['attempts'], fn($timestamp) => $timestamp > $cutoff);

            if (empty($validAttempts)) {
                // No valid attempts, remove the file and its lock file
                unlink($file);
                $lockFile = str_replace('.json', '.lock', $file);
                if (file_exists($lockFile)) {
                    @unlink($lockFile);
                }
                $cleaned++;
            }
        }

        // Clean up orphaned lock files (lock files without corresponding data files)
        $lockFiles = glob($dir . DIRECTORY_SEPARATOR . '*.lock');
        if ($lockFiles !== false) {
            foreach ($lockFiles as $lockFile) {
                $dataFile = str_replace('.lock', '.json', $lockFile);
                if (!file_exists($dataFile)) {
                    @unlink($lockFile);
                }
            }
        }

        return $cleaned;
    }

    /**
     * Get the maximum attempts configuration
     *
     * @return int
     */
    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * Get the window duration configuration
     *
     * @return int Window duration in seconds
     */
    public function getWindowSeconds(): int
    {
        return $this->windowSeconds;
    }
}
