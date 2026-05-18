<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Ops;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\PhpFile;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Doctrine\Migrations\Tools\Console\Command\StatusCommand;
use Doctrine\Migrations\Tools\Console\Command\SyncMetadataCommand;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Runs Doctrine Migrations programmatically via Symfony Console.
 *
 * POST /_ops/migrate           — apply pending migrations.
 * POST /_ops/migrate?to=<v>    — migrate to a specific version (rollback).
 * GET  /_ops/migrate/status    — list applied/pending versions.
 *
 * Authentication is the responsibility of DeployTokenMiddleware piped
 * upstream by the Router. This handler assumes the request has passed
 * the token gate.
 */
final class MigrateHandler extends AbstractHandler
{
    private ?Connection $connection;
    private string $configFile;

    public function __construct(?Connection $connection = null, ?string $configFile = null)
    {
        parent::__construct();
        // Connection is lazy-built in handle() so the router can construct
        // this handler without a live DB env. Middleware (DeployTokenMiddleware)
        // runs first and may reject the request before we ever touch the DB.
        $this->connection = $connection;
        $this->configFile = $configFile ?? dirname(__DIR__, 3) . '/doctrine-migrations.php';
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->validateRequestMethod($request);

        // FPM may otherwise kill long migrations; transactional DDL on
        // PostgreSQL means we shouldn't be killed mid-transaction either.
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        ignore_user_abort(true);

        // For POST: validate the `to` query parameter and the migrations
        // config up-front, before bootstrapping Doctrine. Doctrine's
        // PhpFile loader throws raw TypeErrors for malformed configs and
        // silently treats a missing `migrations_paths` key as zero
        // migrations, so doing this first gives us clear error messages
        // and lets us short-circuit the no-op case without spinning up a
        // DB connection.
        $to             = null;
        $migrationCount = null;
        if ($request->getMethod() === 'POST') {
            $toParam = $request->getQueryParams()['to'] ?? null;
            if (is_string($toParam) && $toParam !== '') {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $toParam)) {
                    return new Response(400, ['Content-Type' => 'text/plain'], "Invalid 'to' parameter\n");
                }
                $to = $toParam;
            }
            $migrationCount = $this->countMigrationFiles();
        }

        if ($this->connection === null) {
            $this->connection = self::buildConnectionFromEnv();
        }

        $factory = DependencyFactory::fromConnection(
            new PhpFile($this->configFile),
            new ExistingConnection($this->connection)
        );

        $app = new Application('LiturgicalCalendar Migrations');
        $app->setAutoExit(false);
        $app->addCommands([
            new SyncMetadataCommand($factory),
            new MigrateCommand($factory),
            new StatusCommand($factory),
        ]);

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            return new Response(500, ['Content-Type' => 'text/plain'], "Failed to open output stream\n");
        }
        $output = new StreamOutput($stream);

        $exitCode = 0;
        if ($request->getMethod() === 'GET') {
            $exitCode = $app->run(new ArrayInput([
                'command'          => 'migrations:status',
                '--no-interaction' => true,
            ]), $output);
        } else {
            // POST: sync metadata first (idempotent, creates tracking table
            // if missing), then migrate.
            $exitCode = $app->run(new ArrayInput([
                'command'          => 'migrations:sync-metadata-storage',
                '--no-interaction' => true,
            ]), $output);
            if ($exitCode === 0) {
                // Guard against the "no registered migrations" case. When the
                // configured migrations_paths contain zero Version*.php files,
                // `migrations:migrate` fails with `version "latest" couldn't
                // be reached` — exit code 1 — which would otherwise turn a
                // healthy deploy red. Treat empty as a successful no-op so
                // the workflow can land code before any migrations are
                // written for it.
                if ($migrationCount === 0) {
                    fwrite($output->getStream(), "  [INFO] No migration files registered; nothing to apply.\n");
                } else {
                    $migrateInput = [
                        'command'          => 'migrations:migrate',
                        '--no-interaction' => true,
                    ];
                    if ($to !== null) {
                        $migrateInput['version'] = $to;
                    }
                    $exitCode = $app->run(new ArrayInput($migrateInput), $output);
                }
            }
        }

        rewind($stream);
        $body = stream_get_contents($stream) ?: '';
        fclose($stream);

        return new Response(
            $exitCode === 0 ? 200 : 500,
            ['Content-Type' => 'text/plain; charset=utf-8'],
            $body
        );
    }

    /**
     * Count Version*.php files across all configured migrations_paths.
     *
     * Used to detect the "no migrations registered" state up-front, since
     * Doctrine's migrate command errors out instead of treating it as a
     * no-op. Reads the migrations config file directly rather than going
     * through Doctrine's internal repository APIs (those vary across
     * minor versions; plain glob is stable).
     *
     * Throws on any config/IO error so a misconfigured deploy fails loudly
     * rather than silently swallowing a path that should have held
     * migrations.
     */
    private function countMigrationFiles(): int
    {
        /** @var mixed $config */
        $config = include $this->configFile;
        if (!is_array($config)) {
            throw new \RuntimeException(sprintf(
                'MigrateHandler: migrations config %s did not return an array.',
                $this->configFile
            ));
        }
        if (!array_key_exists('migrations_paths', $config)) {
            throw new \RuntimeException(sprintf(
                'MigrateHandler: migrations config %s is missing the "migrations_paths" key.',
                $this->configFile
            ));
        }
        $paths = $config['migrations_paths'];
        if (!is_array($paths)) {
            throw new \RuntimeException(sprintf(
                'MigrateHandler: "migrations_paths" in %s must be an array.',
                $this->configFile
            ));
        }

        $count = 0;
        foreach ($paths as $dir) {
            if (!is_string($dir) || !is_dir($dir)) {
                // A configured path that isn't a real directory is a
                // misconfiguration, not "zero migrations". Surface it.
                throw new \RuntimeException(sprintf(
                    'MigrateHandler: migrations_paths entry %s is not an existing directory.',
                    is_string($dir) ? $dir : '(non-string)'
                ));
            }
            $matches = glob($dir . DIRECTORY_SEPARATOR . 'Version*.php');
            if ($matches === false) {
                throw new \RuntimeException(sprintf(
                    'MigrateHandler: glob() failed scanning %s for Version*.php',
                    $dir
                ));
            }
            $count += count($matches);
        }
        return $count;
    }

    private static function buildConnectionFromEnv(): Connection
    {
        if (!\LiturgicalCalendar\Api\Database\Connection::isConfigured()) {
            throw new \RuntimeException(
                'MigrateHandler: database configuration missing. '
                . 'Required environment variables: DB_HOST, DB_NAME, DB_USER, DB_PASSWORD.'
            );
        }

        $host = getenv('DB_HOST') ?: ( $_ENV['DB_HOST'] ?? '' );
        $port = getenv('DB_PORT') ?: ( $_ENV['DB_PORT'] ?? '5432' );
        $name = getenv('DB_NAME') ?: ( $_ENV['DB_NAME'] ?? '' );
        $user = getenv('DB_USER') ?: ( $_ENV['DB_USER'] ?? '' );
        $pass = getenv('DB_PASSWORD');
        if ($pass === false) {
            $pass = $_ENV['DB_PASSWORD'] ?? '';
        }
        return DriverManager::getConnection([
            'driver'   => 'pdo_pgsql',
            'host'     => is_string($host) ? $host : '',
            'port'     => is_numeric($port) ? (int) $port : 5432,
            'dbname'   => is_string($name) ? $name : '',
            'user'     => is_string($user) ? $user : '',
            'password' => is_string($pass) ? $pass : '',
        ]);
    }
}
