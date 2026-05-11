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
                $migrateInput = [
                    'command'          => 'migrations:migrate',
                    '--no-interaction' => true,
                ];
                $to           = $request->getQueryParams()['to'] ?? null;
                if (is_string($to) && $to !== '') {
                    if (!preg_match('/^[A-Za-z0-9_]+$/', $to)) {
                        return new Response(400, ['Content-Type' => 'text/plain'], "Invalid 'to' parameter\n");
                    }
                    $migrateInput['version'] = $to;
                }
                $exitCode = $app->run(new ArrayInput($migrateInput), $output);
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
