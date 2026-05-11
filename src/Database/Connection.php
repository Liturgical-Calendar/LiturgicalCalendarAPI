<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Database connection singleton for PostgreSQL.
 *
 * Provides a single shared PDO connection for the application.
 * Connection is lazy-initialized on first use.
 */
class Connection
{
    private static ?PDO $instance    = null;
    private static bool $initialized = false;

    /**
     * Private constructor to prevent direct instantiation.
     */
    private function __construct()
    {
    }

    /**
     * Get the shared PDO instance.
     *
     * @throws RuntimeException If database configuration is missing or connection fails
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance    = self::createConnection();
            self::$initialized = true;
        }

        return self::$instance;
    }

    /**
     * Check if a database connection is configured.
     *
     * Returns true if the required environment variables are set in $_ENV
     * (populated by Dotenv at bootstrap, and by PHP from the process env
     * when variables_order includes "E"). Mirrors how JwtServiceFactory
     * and other src/ env readers do it.
     */
    public static function isConfigured(): bool
    {
        $host     = $_ENV['DB_HOST'] ?? null;
        $name     = $_ENV['DB_NAME'] ?? null;
        $user     = $_ENV['DB_USER'] ?? null;
        $password = $_ENV['DB_PASSWORD'] ?? null;

        return is_string($host) && $host !== ''
            && is_string($name) && $name !== ''
            && is_string($user) && $user !== ''
            && $password !== null;
    }

    /**
     * Check if the database connection has been initialized.
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    /**
     * Close the database connection.
     *
     * Useful for testing or when you need to reset the connection.
     */
    public static function close(): void
    {
        self::$instance    = null;
        self::$initialized = false;
    }

    /**
     * Create a new PDO connection.
     *
     * @throws RuntimeException If configuration is missing or connection fails
     */
    private static function createConnection(): PDO
    {
        $host     = $_ENV['DB_HOST'] ?? null;
        $portRaw  = $_ENV['DB_PORT'] ?? '';
        $port     = is_string($portRaw) && $portRaw !== '' ? $portRaw : '5432';
        $name     = $_ENV['DB_NAME'] ?? null;
        $user     = $_ENV['DB_USER'] ?? null;
        $password = $_ENV['DB_PASSWORD'] ?? null;

        if (!is_string($host) || !is_string($name) || !is_string($user) || !is_string($password)) {
            throw new RuntimeException(
                'Database configuration missing. Required environment variables: ' .
                'DB_HOST, DB_NAME, DB_USER, DB_PASSWORD'
            );
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $host,
            $port,
            $name
        );

        try {
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);

            // Set session timezone for consistent date/time operations
            $pdo->exec("SET timezone TO 'Europe/Vatican'");

            return $pdo;
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Failed to connect to database: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Prevent cloning of the instance.
     */
    private function __clone()
    {
    }

    /**
     * Prevent unserialization of the instance.
     *
     * @throws RuntimeException Always
     */
    public function __wakeup(): void
    {
        throw new RuntimeException('Cannot unserialize singleton');
    }
}
