<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Support;

/**
 * Starts PHP subprocesses that talk to the same test database as the calling test.
 *
 * For a class extending {@see \LiturgicalCalendar\Tests\Repositories\RepositoryTestCase}: `dbEnv()` forwards
 * the DB_* values that class resolved, so a child connects to exactly the database the test is asserting on.
 * Every process in a batch is started before any pipe is read — proc_open() does not block — which is what
 * makes them genuinely concurrent.
 */
trait LiveDbSubprocessTrait
{
    /** @return array<string, string> */
    protected function dbEnv(): array
    {
        return [
            'DB_HOST'     => (string) self::env('DB_HOST'),
            'DB_PORT'     => (string) ( self::env('DB_PORT') ?? '5432' ),
            'DB_NAME'     => (string) self::env('DB_NAME'),
            'DB_USER'     => (string) self::env('DB_USER'),
            'DB_PASSWORD' => (string) self::env('DB_PASSWORD'),
            'PATH'        => (string) getenv('PATH'),
        ];
    }

    /**
     * PHP source that requires the autoloader and opens `$pdo` from the forwarded DB_* environment.
     */
    protected function pdoBootstrap(): string
    {
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);

        return <<<PHP
            require {$autoload};
            \$pdo = new PDO(
                sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT') ?: '5432', getenv('DB_NAME')),
                getenv('DB_USER'),
                getenv('DB_PASSWORD'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            PHP;
    }

    /**
     * Runs every command concurrently and returns each one's [stdout, stderr, exit code], in order.
     *
     * @param list<list<string>> $commands
     * @return list<array{string, string, int}>
     */
    protected function runConcurrently(array $commands): array
    {
        $running = [];
        foreach ($commands as $i => $command) {
            $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $this->dbEnv());
            self::assertIsResource($proc, "could not start process {$i}");
            $running[] = [$proc, $pipes];
        }

        $results = [];
        foreach ($running as [$proc, $pipes]) {
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $results[] = [$out, $err, proc_close($proc)];
        }

        return $results;
    }
}
