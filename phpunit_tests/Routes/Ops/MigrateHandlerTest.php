<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\Ops;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use LiturgicalCalendar\Api\Handlers\Ops\MigrateHandler;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class MigrateHandlerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped(
                'pdo_sqlite extension required for MigrateHandlerTest. '
                . 'On Debian/Ubuntu: sudo apt-get install -y php8.4-sqlite3 '
                . '&& sudo phpenmod -v 8.4 -s cli pdo_sqlite sqlite3.'
            );
        }

        // Each test gets a fresh in-memory sqlite DB. The handler config
        // (doctrine-migrations.php) declares migrations_paths pointing at
        // src/Migrations; for the unit test we provide a synthetic config
        // file pointing at a temp dir we control, via the test seam below.
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
    }

    public function testStatusActionReturns200OnFreshDb(): void
    {
        $configFile = $this->writeConfig([]);
        $handler    = new MigrateHandler($this->connection, $configFile);
        $handler->setAllowedRequestMethods([
            \LiturgicalCalendar\Api\Http\Enum\RequestMethod::GET,
        ]);

        $request = new ServerRequest('GET', '/_ops/migrate/status');

        $response = $handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/plain', $response->getHeaderLine('Content-Type'));
    }

    /**
     * Writes a temporary doctrine-migrations.php file that points at a
     * temp directory containing the supplied migration class names.
     *
     * @param array<string,string> $migrations Map of class basename => PHP source.
     */
    private function writeConfig(array $migrations): string
    {
        $migrationsDir = sys_get_temp_dir() . '/litcal_test_migrate_' . bin2hex(random_bytes(6));
        mkdir($migrationsDir, 0700, true);
        foreach ($migrations as $name => $source) {
            file_put_contents($migrationsDir . '/' . $name . '.php', $source);
        }

        $configFile = sys_get_temp_dir() . '/litcal_test_migrate_cfg_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($configFile, "<?php\nreturn " . var_export([
            'table_storage'    => ['table_name' => 'doctrine_migration_versions'],
            'migrations_paths' => ['LiturgicalCalendar\\TestMigrations' => $migrationsDir],
            'all_or_nothing'   => true,
            'transactional'    => true,
        ], true) . ";\n");

        return $configFile;
    }
}
