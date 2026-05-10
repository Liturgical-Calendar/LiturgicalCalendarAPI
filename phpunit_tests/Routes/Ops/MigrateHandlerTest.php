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

    /** @var list<string> */
    private array $tempPaths = [];

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

    protected function tearDown(): void
    {
        // Clean up temp files/dirs created by writeConfig(). Files are
        // unlinked first, then directories removed. Paths are confined to
        // sys_get_temp_dir() with a fixed "litcal_test_migrate_" prefix and
        // are appended only by writeConfig() (no user input).
        $tempRoot = realpath(sys_get_temp_dir());
        foreach (array_reverse($this->tempPaths) as $path) {
            $real = realpath($path);
            if (
                $tempRoot === false
                || $real === false
                || !str_starts_with($real, $tempRoot . DIRECTORY_SEPARATOR)
                || !str_contains(basename($real), 'litcal_test_migrate_')
            ) {
                continue;
            }
            if (is_file($real)) {
                // nosemgrep: php.lang.security.unlink-use.unlink-use
                @unlink($real);
            } elseif (is_dir($real)) {
                @rmdir($real);
            }
        }
        $this->tempPaths = [];
        parent::tearDown();
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
        $this->assertStringContainsString(
            'migration',
            strtolower((string) $response->getBody()),
            'Status output should mention migrations'
        );
    }

    public function testPostMigrateAppliesPendingMigrations(): void
    {
        $migrationClass = <<<'PHP'
<?php
declare(strict_types=1);
namespace LiturgicalCalendar\TestMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20260101000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE example_target (id INTEGER PRIMARY KEY)');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE example_target');
    }
}
PHP;
        $configFile     = $this->writeConfig(['Version20260101000000' => $migrationClass]);

        $handler = new MigrateHandler($this->connection, $configFile);
        $handler->setAllowedRequestMethods([
            \LiturgicalCalendar\Api\Http\Enum\RequestMethod::POST,
        ]);

        $request = new ServerRequest('POST', '/_ops/migrate');

        $response = $handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Version20260101000000', $body);

        // Verify the migration actually ran. introspectTables() is the
        // non-deprecated DBAL 4.x replacement for listTables() (which itself
        // was the original swap for the deprecated listTableNames()).
        $tables     = $this->connection->createSchemaManager()->introspectTables();
        $tableNames = array_map(static fn($t): string => $t->getName(), $tables);
        $this->assertContains('example_target', $tableNames);
    }

    public function testPostMigrateOnUpToDateDbReturns200(): void
    {
        // A second POST after all migrations have been applied is a true
        // "already up-to-date" no-op. Doctrine's MigrateCommand returns
        // non-zero on a config with zero registered migrations ("the version
        // 'latest' couldn't be reached"), so we register one migration,
        // apply it, then re-run to exercise the up-to-date path.
        $migrationClass = <<<'PHP'
<?php
declare(strict_types=1);
namespace LiturgicalCalendar\TestMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20260101000001 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE noop_target (id INTEGER PRIMARY KEY)');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE noop_target');
    }
}
PHP;
        $configFile     = $this->writeConfig(['Version20260101000001' => $migrationClass]);

        $handler = new MigrateHandler($this->connection, $configFile);
        $handler->setAllowedRequestMethods([
            \LiturgicalCalendar\Api\Http\Enum\RequestMethod::POST,
        ]);

        $request = new ServerRequest('POST', '/_ops/migrate');

        // First call: applies the pending migration.
        $first = $handler->handle($request);
        $this->assertSame(200, $first->getStatusCode());

        // Second call: nothing to migrate; should still be 200.
        $second = $handler->handle($request);
        $this->assertSame(200, $second->getStatusCode());
        $this->assertStringContainsString(
            'Already at the latest version',
            (string) $second->getBody()
        );
    }

    public function testPostMigrateRejectsMalformedToParam(): void
    {
        $configFile = $this->writeConfig([]);

        $handler = new MigrateHandler($this->connection, $configFile);
        $handler->setAllowedRequestMethods([
            \LiturgicalCalendar\Api\Http\Enum\RequestMethod::POST,
        ]);

        $request = ( new ServerRequest('POST', '/_ops/migrate?to=bad/value') )
            ->withQueryParams(['to' => 'bad/value']);

        $response = $handler->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testGetWithDisallowedMethodThrowsMethodNotAllowed(): void
    {
        $configFile = $this->writeConfig([]);

        $handler = new MigrateHandler($this->connection, $configFile);
        $handler->setAllowedRequestMethods([
            \LiturgicalCalendar\Api\Http\Enum\RequestMethod::POST,
        ]);

        $request = new ServerRequest('GET', '/_ops/migrate');

        $this->expectException(\LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException::class);
        $handler->handle($request);
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
        $this->tempPaths[] = $migrationsDir;
        foreach ($migrations as $name => $source) {
            file_put_contents($migrationsDir . '/' . $name . '.php', $source);
            $this->tempPaths[] = $migrationsDir . '/' . $name . '.php';
        }

        $configFile = sys_get_temp_dir() . '/litcal_test_migrate_cfg_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($configFile, "<?php\nreturn " . var_export([
            'table_storage'    => ['table_name' => 'doctrine_migration_versions'],
            'migrations_paths' => ['LiturgicalCalendar\\TestMigrations' => $migrationsDir],
            'all_or_nothing'   => true,
            'transactional'    => true,
        ], true) . ";\n");
        $this->tempPaths[] = $configFile;

        return $configFile;
    }
}
