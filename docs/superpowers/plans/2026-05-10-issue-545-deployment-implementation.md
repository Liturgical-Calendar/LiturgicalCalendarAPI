# GHA Plesk Deployment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> `superpowers:subagent-driven-development` (recommended) or
> `superpowers:executing-plans` to implement this plan task-by-task.
> Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:**
Implement the GitHub Actions deployment workflow for issue #545 — manual /
release-triggered deploys to Plesk that build `vendor/` on the runner, rsync
to the chroot, and run Doctrine Migrations through a token-authenticated
`/_ops/migrate` HTTP endpoint.

**Architecture:**
Two new framework components — `DeployTokenMiddleware` (PSR-15, env-gated,
`hash_equals` token check) and `MigrateHandler` (extends `AbstractHandler`,
runs Doctrine Migrations programmatically via Symfony Console). One workflow
file (`.github/workflows/deploy.yaml`) modeled on BibleGet-I-O/endpoint with
LCAPI-specific steps for the migrate POST and `/calendars` health check.
One rsync exclude file. Branch already exists: `feature/545-deploy-workflow`.

**Tech Stack:**
PHP 8.4, PSR-7/15, Doctrine DBAL + Migrations, Symfony Console, Nyholm/PSR-7,
PHPUnit 12, GitHub Actions, rsync over SSH, PostgreSQL.

**Spec:** [`docs/superpowers/specs/2026-05-10-issue-545-deployment-design.md`](../specs/2026-05-10-issue-545-deployment-design.md)

---

## File structure

- **`src/Http/Middleware/DeployTokenMiddleware.php`** (new) — PSR-15
  middleware. Reads `DEPLOY_TOKEN`, fail-closed if empty, env-gates to
  `staging`/`production`, `hash_equals` against `X-Deploy-Token` header.
- **`src/Handlers/Ops/MigrateHandler.php`** (new) — Extends
  `AbstractHandler`. Builds DBAL connection from env, constructs
  `DependencyFactory` with `PhpFile(doctrine-migrations.php)`, runs
  `MigrateCommand`/`StatusCommand` via Symfony Console with output streamed
  to `php://output`.
- **`src/Enum/Route.php`** (modified) — Add `OPS_MIGRATE` and
  `OPS_MIGRATE_STATUS` enum cases.
- **`src/Router.php`** (modified) — New `case '_ops':` branch dispatching to
  `MigrateHandler`, with `DeployTokenMiddleware` piped in for both routes.
- **`.env.example`** (modified) — Add `DEPLOY_TOKEN=` placeholder.
- **`.github/workflows/deploy.yaml`** (new) — The deploy workflow.
- **`.github/deploy/rsync-exclude.txt`** (new) — rsync exclude policy
  (matches spec §7).
- **`phpunit_tests/Http/DeployTokenMiddlewareTest.php`** (new) — Unit tests
  for the middleware.
- **`phpunit_tests/Routes/Ops/MigrateHandlerTest.php`** (new) — Unit tests
  for the handler with sqlite-in-memory DBAL connection.

Each task below produces a self-contained commit. The build sequence prioritises the smallest unit first (the middleware, no DB) so the harder pieces inherit working primitives.

---

## Task 1: Verify branch state and scaffold directories

**Files:**

- Verify: `feature/545-deploy-workflow` is checked out.
- Create: `src/Handlers/Ops/` directory.
- Create: `phpunit_tests/Http/` already exists (verify); `phpunit_tests/Routes/Ops/` to create.
- Create: `.github/deploy/` directory.

- [ ] **Step 1: Verify branch state**

```bash
git status
git log --oneline -5
git branch --show-current
```

Expected: clean working tree, on `feature/545-deploy-workflow`, recent commits include `c1436f57` (drop speculative /public/*.php protection).

- [ ] **Step 2: Create scaffolding directories**

```bash
mkdir -p src/Handlers/Ops phpunit_tests/Routes/Ops .github/deploy
```

- [ ] **Step 3: No commit yet**

These directories carry no files yet; they will be committed alongside their first contents in subsequent tasks.

---

## Task 2: DeployTokenMiddleware — failing test for empty token

**Files:**

- Create: `phpunit_tests/Http/DeployTokenMiddlewareTest.php`
- Test: `phpunit_tests/Http/DeployTokenMiddlewareTest.php::testEmptyDeployTokenReturns503`

- [ ] **Step 1: Write the failing test file**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http;

use LiturgicalCalendar\Api\Http\Middleware\DeployTokenMiddleware;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class DeployTokenMiddlewareTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $envBackup = [];

    private RequestHandlerInterface $okHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envBackup = [
            'DEPLOY_TOKEN_env'    => $_ENV['DEPLOY_TOKEN']    ?? '__UNSET__',
            'DEPLOY_TOKEN_getenv' => getenv('DEPLOY_TOKEN') === false ? '__UNSET__' : (string) getenv('DEPLOY_TOKEN'),
            'APP_ENV_env'         => $_ENV['APP_ENV']         ?? '__UNSET__',
            'APP_ENV_getenv'      => getenv('APP_ENV') === false ? '__UNSET__' : (string) getenv('APP_ENV'),
        ];

        $this->okHandler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'passed-through');
            }
        };
    }

    protected function tearDown(): void
    {
        foreach (['DEPLOY_TOKEN', 'APP_ENV'] as $var) {
            $envBackup    = $this->envBackup["{$var}_env"];
            $getenvBackup = $this->envBackup["{$var}_getenv"];
            if ($envBackup === '__UNSET__') {
                unset($_ENV[$var]);
            } else {
                $_ENV[$var] = $envBackup;
            }
            if ($getenvBackup === '__UNSET__') {
                putenv($var);
            } else {
                putenv("{$var}={$getenvBackup}");
            }
        }
        parent::tearDown();
    }

    public function testEmptyDeployTokenReturns503(): void
    {
        unset($_ENV['DEPLOY_TOKEN']);
        putenv('DEPLOY_TOKEN');
        $_ENV['APP_ENV'] = 'staging';
        putenv('APP_ENV=staging');

        $middleware = new DeployTokenMiddleware();
        $request    = new ServerRequest('POST', '/_ops/migrate', [
            'X-Deploy-Token' => 'doesntmatter',
        ]);

        $response = $middleware->process($request, $this->okHandler);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertNotSame('passed-through', (string) $response->getBody());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit phpunit_tests/Http/DeployTokenMiddlewareTest.php --filter testEmptyDeployTokenReturns503
```

Expected: FAIL with "Class LiturgicalCalendar\Api\Http\Middleware\DeployTokenMiddleware not found".

- [ ] **Step 3: No commit — wait until middleware passes**

---

## Task 3: DeployTokenMiddleware — minimal implementation

**Files:**

- Create: `src/Http/Middleware/DeployTokenMiddleware.php`

- [ ] **Step 1: Write the middleware**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Http\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authenticates deploy-time requests to /_ops/migrate via a long random
 * shared token. Fail-closed: empty DEPLOY_TOKEN, missing/wrong header,
 * or APP_ENV outside {staging, production} all reject. Comparisons use
 * hash_equals to avoid timing-based token discovery.
 */
final class DeployTokenMiddleware implements MiddlewareInterface
{
    private const HEADER          = 'X-Deploy-Token';
    private const ALLOWED_APP_ENV = ['staging', 'production'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $appEnv = getenv('APP_ENV') ?: ( $_ENV['APP_ENV'] ?? '' );
        if (!is_string($appEnv) || !in_array($appEnv, self::ALLOWED_APP_ENV, true)) {
            return new Response(503, ['Content-Type' => 'text/plain'], "Deploy endpoint disabled in this environment\n");
        }

        $expected = getenv('DEPLOY_TOKEN') ?: ( $_ENV['DEPLOY_TOKEN'] ?? '' );
        if (!is_string($expected) || $expected === '') {
            return new Response(503, ['Content-Type' => 'text/plain'], "Deploy endpoint not configured\n");
        }

        $provided = $request->getHeaderLine(self::HEADER);
        if ($provided === '' || !hash_equals($expected, $provided)) {
            return new Response(401, ['Content-Type' => 'text/plain'], "Unauthorized\n");
        }

        return $handler->handle($request);
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

```bash
vendor/bin/phpunit phpunit_tests/Http/DeployTokenMiddlewareTest.php --filter testEmptyDeployTokenReturns503
```

Expected: PASS (1 test, 2 assertions).

- [ ] **Step 3: Commit**

```bash
git add src/Http/Middleware/DeployTokenMiddleware.php phpunit_tests/Http/DeployTokenMiddlewareTest.php
git commit -m "feat(deploy): DeployTokenMiddleware with empty-token 503 case

First slice of the /_ops/migrate auth gate. Empty DEPLOY_TOKEN env var
returns 503 (server misconfigured, not a client error). Subsequent
commits add the env gate, header check, and constant-time comparison."
```

---

## Task 4: DeployTokenMiddleware — env gate, missing header, mismatch, success

**Files:**

- Modify: `phpunit_tests/Http/DeployTokenMiddlewareTest.php` — append five test methods.

- [ ] **Step 1: Append the additional test methods**

Append to the existing class (before the closing `}`):

```php
    public function testAppEnvDevelopmentReturns503(): void
    {
        $_ENV['DEPLOY_TOKEN'] = 'thisistheexpectedtoken';
        putenv('DEPLOY_TOKEN=thisistheexpectedtoken');
        $_ENV['APP_ENV'] = 'development';
        putenv('APP_ENV=development');

        $middleware = new DeployTokenMiddleware();
        $request    = new ServerRequest('POST', '/_ops/migrate', [
            'X-Deploy-Token' => 'thisistheexpectedtoken',
        ]);

        $response = $middleware->process($request, $this->okHandler);

        $this->assertSame(503, $response->getStatusCode());
    }

    public function testAppEnvUnsetReturns503(): void
    {
        $_ENV['DEPLOY_TOKEN'] = 'thisistheexpectedtoken';
        putenv('DEPLOY_TOKEN=thisistheexpectedtoken');
        unset($_ENV['APP_ENV']);
        putenv('APP_ENV');

        $middleware = new DeployTokenMiddleware();
        $request    = new ServerRequest('POST', '/_ops/migrate', [
            'X-Deploy-Token' => 'thisistheexpectedtoken',
        ]);

        $response = $middleware->process($request, $this->okHandler);

        $this->assertSame(503, $response->getStatusCode());
    }

    public function testMissingHeaderReturns401(): void
    {
        $_ENV['DEPLOY_TOKEN'] = 'thisistheexpectedtoken';
        putenv('DEPLOY_TOKEN=thisistheexpectedtoken');
        $_ENV['APP_ENV'] = 'staging';
        putenv('APP_ENV=staging');

        $middleware = new DeployTokenMiddleware();
        $request    = new ServerRequest('POST', '/_ops/migrate'); // no header

        $response = $middleware->process($request, $this->okHandler);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testWrongHeaderReturns401(): void
    {
        $_ENV['DEPLOY_TOKEN'] = 'thisistheexpectedtoken';
        putenv('DEPLOY_TOKEN=thisistheexpectedtoken');
        $_ENV['APP_ENV'] = 'production';
        putenv('APP_ENV=production');

        $middleware = new DeployTokenMiddleware();
        $request    = new ServerRequest('POST', '/_ops/migrate', [
            'X-Deploy-Token' => 'wrongtoken',
        ]);

        $response = $middleware->process($request, $this->okHandler);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testCorrectHeaderPassesThrough(): void
    {
        $_ENV['DEPLOY_TOKEN'] = 'thisistheexpectedtoken';
        putenv('DEPLOY_TOKEN=thisistheexpectedtoken');
        $_ENV['APP_ENV'] = 'staging';
        putenv('APP_ENV=staging');

        $middleware = new DeployTokenMiddleware();
        $request    = new ServerRequest('POST', '/_ops/migrate', [
            'X-Deploy-Token' => 'thisistheexpectedtoken',
        ]);

        $response = $middleware->process($request, $this->okHandler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('passed-through', (string) $response->getBody());
    }

    public function testGetenvFallbackWhenSuperglobalUnset(): void
    {
        // Simulate FPM with restricted variables_order: $_ENV missing the key
        // but getenv() still returns it. Mirrors ApiKeyRateLimitMiddleware fix.
        unset($_ENV['DEPLOY_TOKEN'], $_ENV['APP_ENV']);
        putenv('DEPLOY_TOKEN=thisistheexpectedtoken');
        putenv('APP_ENV=staging');

        $middleware = new DeployTokenMiddleware();
        $request    = new ServerRequest('POST', '/_ops/migrate', [
            'X-Deploy-Token' => 'thisistheexpectedtoken',
        ]);

        $response = $middleware->process($request, $this->okHandler);

        $this->assertSame(200, $response->getStatusCode());
    }
```

- [ ] **Step 2: Run all middleware tests**

```bash
vendor/bin/phpunit phpunit_tests/Http/DeployTokenMiddlewareTest.php
```

Expected: PASS (7 tests, 8 assertions). All cases covered by the implementation written in Task 3 — no implementation changes needed.

- [ ] **Step 3: Run static analysis to confirm no regressions**

```bash
composer analyse
composer lint
```

Expected: PHPStan "[OK] No errors"; phpcs exit 0.

- [ ] **Step 4: Commit**

```bash
git add phpunit_tests/Http/DeployTokenMiddlewareTest.php
git commit -m "test(deploy): cover all DeployTokenMiddleware branches

Adds tests for the APP_ENV gate (development/unset both 503), missing
header (401), wrong header (401), correct header (passes through), and
getenv() fallback when \$_ENV is unset (FPM/container case)."
```

---

## Task 5: MigrateHandler — failing test for unknown action

**Files:**

- Create: `phpunit_tests/Routes/Ops/MigrateHandlerTest.php`
- Test: `phpunit_tests/Routes/Ops/MigrateHandlerTest.php::testStatusActionReturns200OnFreshDb`

- [ ] **Step 1: Pre-flight — check the sqlite extension is available**

```bash
php -m | grep -i pdo_sqlite
```

Expected: `pdo_sqlite` listed. If not, install via `apt install php-sqlite3` (Debian/Ubuntu) or platform equivalent. Tests need an in-memory DB so they don't touch PostgreSQL.

- [ ] **Step 2: Write the failing test file**

```php
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
            'table_storage'    => [
                'table_name' => 'doctrine_migration_versions',
            ],
            'migrations_paths' => [
                'LiturgicalCalendar\\TestMigrations' => $migrationsDir,
            ],
            'all_or_nothing'   => true,
            'transactional'    => true,
        ], true) . ";\n");

        return $configFile;
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

```bash
vendor/bin/phpunit phpunit_tests/Routes/Ops/MigrateHandlerTest.php
```

Expected: FAIL with "Class LiturgicalCalendar\Api\Handlers\Ops\MigrateHandler not found".

- [ ] **Step 4: No commit — wait until handler implementation passes**

---

## Task 6: MigrateHandler — implementation supporting GET status

**Files:**

- Create: `src/Handlers/Ops/MigrateHandler.php`

- [ ] **Step 1: Write the handler**

```php
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
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
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
    private Connection $connection;
    private string $configFile;

    public function __construct(?Connection $connection = null, ?string $configFile = null)
    {
        parent::__construct();
        $this->connection = $connection ?? self::buildConnectionFromEnv();
        $this->configFile = $configFile ?? dirname(__DIR__, 3) . '/doctrine-migrations.php';
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Pre-handler dispatch: enforce method, content-types, etc.
        $preflight = $this->preHandle($request);
        if ($preflight !== null) {
            return $preflight;
        }

        // FPM may otherwise kill long migrations; transactional DDL on
        // PostgreSQL means we shouldn't be killed mid-transaction either.
        @set_time_limit(0);
        ignore_user_abort(true);

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
                $to = $request->getQueryParams()['to'] ?? null;
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

    /**
     * Returns null when the request should proceed, or a populated
     * Response (405) when the method isn't in $allowedRequestMethods.
     */
    private function preHandle(ServerRequestInterface $request): ?ResponseInterface
    {
        $method = $request->getMethod();
        $allowed = array_map(static fn(RequestMethod $m): string => $m->value, $this->allowedRequestMethods ?? []);
        if (!in_array($method, $allowed, true)) {
            return new Response(
                405,
                ['Allow' => implode(', ', $allowed), 'Content-Type' => 'text/plain'],
                "Method Not Allowed\n"
            );
        }
        return null;
    }

    private static function buildConnectionFromEnv(): Connection
    {
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
```

- [ ] **Step 2: Run the GET status test**

```bash
vendor/bin/phpunit phpunit_tests/Routes/Ops/MigrateHandlerTest.php --filter testStatusActionReturns200OnFreshDb
```

Expected: PASS (1 test, 2 assertions).

- [ ] **Step 3: Run static analysis**

```bash
composer analyse
composer lint
```

Expected: PHPStan "[OK] No errors"; phpcs clean. If phpcs flags whitespace, run `composer lint:fix` and re-stage.

- [ ] **Step 4: Commit**

```bash
git add src/Handlers/Ops/MigrateHandler.php phpunit_tests/Routes/Ops/MigrateHandlerTest.php
git commit -m "feat(deploy): MigrateHandler with GET status action

Loads Doctrine Migrations programmatically via Symfony Console; runs
migrations:status against an injected DBAL connection and returns the
plain-text output. POST migrate path follows in the next commit."
```

---

## Task 7: MigrateHandler — POST migrate test + ensure POST works on fresh schema

**Files:**

- Modify: `phpunit_tests/Routes/Ops/MigrateHandlerTest.php` — add three test methods.

- [ ] **Step 1: Append the POST tests**

Append to the existing class (before the closing `}`):

```php
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
        $configFile = $this->writeConfig(['Version20260101000000' => $migrationClass]);

        $handler = new MigrateHandler($this->connection, $configFile);
        $handler->setAllowedRequestMethods([
            \LiturgicalCalendar\Api\Http\Enum\RequestMethod::POST,
        ]);

        $request = new ServerRequest('POST', '/_ops/migrate');

        $response = $handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Version20260101000000', $body);

        // Verify the migration actually ran.
        $tables = $this->connection->createSchemaManager()->listTableNames();
        $this->assertContains('example_target', $tables);
    }

    public function testPostMigrateOnUpToDateDbReturns200(): void
    {
        $configFile = $this->writeConfig([]);

        $handler = new MigrateHandler($this->connection, $configFile);
        $handler->setAllowedRequestMethods([
            \LiturgicalCalendar\Api\Http\Enum\RequestMethod::POST,
        ]);

        $request = new ServerRequest('POST', '/_ops/migrate');

        $response = $handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPostMigrateRejectsMalformedToParam(): void
    {
        $configFile = $this->writeConfig([]);

        $handler = new MigrateHandler($this->connection, $configFile);
        $handler->setAllowedRequestMethods([
            \LiturgicalCalendar\Api\Http\Enum\RequestMethod::POST,
        ]);

        $request = (new ServerRequest('POST', '/_ops/migrate?to=bad/value'))
            ->withQueryParams(['to' => 'bad/value']);

        $response = $handler->handle($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testGetWithDisallowedMethodReturns405(): void
    {
        $configFile = $this->writeConfig([]);

        $handler = new MigrateHandler($this->connection, $configFile);
        $handler->setAllowedRequestMethods([
            \LiturgicalCalendar\Api\Http\Enum\RequestMethod::POST,
        ]);

        $request = new ServerRequest('GET', '/_ops/migrate');

        $response = $handler->handle($request);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertStringContainsString('POST', $response->getHeaderLine('Allow'));
    }
```

- [ ] **Step 2: Run all handler tests**

```bash
vendor/bin/phpunit phpunit_tests/Routes/Ops/MigrateHandlerTest.php
```

Expected: PASS (5 tests). If `testPostMigrateAppliesPendingMigrations` fails
because Doctrine Migrations can't load
`LiturgicalCalendar\TestMigrations\Version20260101000000`, ensure the
migration source is being written to the configured directory and the
namespace in the config matches the namespace in the source — both are
`LiturgicalCalendar\TestMigrations`.

- [ ] **Step 3: Run full test suite to catch regressions**

```bash
composer test:quick
```

Expected: all green except any pre-existing skipped/slow tests.

- [ ] **Step 4: Lint and analyse**

```bash
composer lint && composer analyse
```

Expected: clean.

- [ ] **Step 5: Commit**

```bash
git add phpunit_tests/Routes/Ops/MigrateHandlerTest.php
git commit -m "test(deploy): cover MigrateHandler POST/migrate paths

Tests apply-pending (verifies the schema actually changed), no-op when
already up-to-date (200), malformed ?to= rejection (400), and method
restriction (405 with Allow header)."
```

---

## Task 8: Route enum — add OPS_MIGRATE cases

**Files:**

- Modify: `src/Enum/Route.php` — add two enum cases.

- [ ] **Step 1: Read the current enum**

```bash
sed -n '1,30p' src/Enum/Route.php
```

- [ ] **Step 2: Add the new cases**

Apply this edit to `src/Enum/Route.php`. Replace the `case MISSALS = '/missals';` line with:

```php
    case MISSALS           = '/missals';
    case OPS_MIGRATE       = '/_ops/migrate';
    case OPS_MIGRATE_STATUS = '/_ops/migrate/status';
```

(Match the existing alignment style in the file — the `=` should line up with the surrounding cases. If the existing cases use a different alignment, preserve it.)

- [ ] **Step 3: Lint**

```bash
composer lint
```

Expected: clean. If alignment-sniff complains, run `composer lint:fix`.

- [ ] **Step 4: Commit**

```bash
git add src/Enum/Route.php
git commit -m "feat(deploy): add OPS_MIGRATE route enum cases"
```

---

## Task 9: Router wiring for /_ops/migrate

**Files:**

- Modify: `src/Router.php` — add new `case '_ops':` branch + middleware piping.

- [ ] **Step 1: Add the handler dispatch in the route switch**

Locate the `case 'temporale':` branch in `src/Router.php` (around line 492)
and add a new case immediately after it (still inside the switch, before
the `default:`). Run `grep -n "case 'temporale'" src/Router.php` to
confirm the line number.

```php
            case '_ops':
                if (count($requestPathParts) === 1 && $requestPathParts[0] === 'migrate') {
                    $migrateHandler = new \LiturgicalCalendar\Api\Handlers\Ops\MigrateHandler();
                    $migrateHandler->setAllowedRequestMethods([RequestMethod::POST]);
                    $this->handler = $migrateHandler;
                } elseif (count($requestPathParts) === 2 && $requestPathParts[0] === 'migrate' && $requestPathParts[1] === 'status') {
                    $migrateHandler = new \LiturgicalCalendar\Api\Handlers\Ops\MigrateHandler();
                    $migrateHandler->setAllowedRequestMethods([RequestMethod::GET]);
                    $this->handler = $migrateHandler;
                } else {
                    $this->response = new Response(StatusCode::NOT_FOUND->value, [], null, $this->request->getProtocolVersion(), StatusCode::NOT_FOUND->reason());
                    $this->emitResponse();
                }
                break;
```

- [ ] **Step 2: Add the middleware piping**

Find the
`if (!in_array($route, ['auth', 'admin', 'applications'], true)) {` block
(around line 537). The `_ops` route should NOT receive `ApiKeyMiddleware`
or `ApiKeyRateLimitMiddleware` — its auth is the deploy token, not API
keys. Add `'_ops'` to that exclusion list:

Replace:

```php
        if (!in_array($route, ['auth', 'admin', 'applications'], true)) {
```

with:

```php
        if (!in_array($route, ['auth', 'admin', 'applications', '_ops'], true)) {
```

Same change to the HttpsEnforcementMiddleware inclusion list a few lines below. Find:

```php
        if (in_array($route, ['auth', 'admin', 'applications'], true)) {
            $pipeline->pipe(new HttpsEnforcementMiddleware());
        }
```

and add `'_ops'`:

```php
        if (in_array($route, ['auth', 'admin', 'applications', '_ops'], true)) {
            $pipeline->pipe(new HttpsEnforcementMiddleware());
        }
```

- [ ] **Step 3: Pipe DeployTokenMiddleware on _ops routes**

Add (after the HttpsEnforcement block above):

```php
        // Deploy token authentication for /_ops routes.
        if ($route === '_ops') {
            $pipeline->pipe(new \LiturgicalCalendar\Api\Http\Middleware\DeployTokenMiddleware());
        }
```

- [ ] **Step 4: Confirm imports — add the `use` statements at the top of Router.php if not already present**

Search:

```bash
grep -n "MigrateHandler\|DeployTokenMiddleware" src/Router.php
```

If they're not imported, add to the `use` block near the top:

```php
use LiturgicalCalendar\Api\Handlers\Ops\MigrateHandler;
use LiturgicalCalendar\Api\Http\Middleware\DeployTokenMiddleware;
```

(Then the inline FQNs in steps 1 and 3 can be simplified, but leaving the FQNs works — the engineer can choose.)

- [ ] **Step 5: Lint and analyse**

```bash
composer lint && composer analyse
```

Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Router.php
git commit -m "feat(deploy): route /_ops/migrate through DeployTokenMiddleware

Adds _ops route case dispatching POST /_ops/migrate and GET
/_ops/migrate/status to MigrateHandler. Pipes DeployTokenMiddleware
upstream and excludes _ops from the API key + rate limit middlewares
(deploy auth is the X-Deploy-Token, not an API key)."
```

---

## Task 10: .env.example — document DEPLOY_TOKEN

**Files:**

- Modify: `.env.example`

- [ ] **Step 1: Read the current .env.example to find a good insertion point**

```bash
grep -n "JWT\|ADMIN_PASSWORD" .env.example
```

- [ ] **Step 2: Append a DEPLOY_TOKEN section near the JWT/admin block**

Add this block to `.env.example` (location: alphabetically with other security tokens, or grouped near JWT_SECRET):

```bash
# DEPLOY_TOKEN — long random shared secret used by GitHub Actions to
# authenticate deploy-time requests to POST /_ops/migrate.
# Generate with: openssl rand -hex 32
# Must be set in staging/production .env files; the GitHub Actions secret
# of the same name is sent in the X-Deploy-Token request header.
DEPLOY_TOKEN=
```

- [ ] **Step 3: Lint**

```bash
composer lint:md 2>&1 | tail -3
```

(`.env.example` isn't markdown, but verify the rest of the project still lints.)

- [ ] **Step 4: Commit**

```bash
git add .env.example
git commit -m "docs(env): document DEPLOY_TOKEN for /_ops/migrate"
```

---

## Task 11: rsync exclude file

**Files:**

- Create: `.github/deploy/rsync-exclude.txt`

- [ ] **Step 1: Write the file with the exact content from the spec §7**

```bash
cat > .github/deploy/rsync-exclude.txt <<'EXCLUDE'
# Patterns excluded from `rsync --delete` deploys.
#
# These patterns serve two purposes:
#   1. Files matched here are NOT transferred from the runner.
#   2. Files matched here are NOT removed on the destination by --delete.
#
# Anything that should live only on the server (env files, runtime
# logs, server-managed state) MUST be listed here so it isn't wiped
# on deploy.

# --- VCS / CI / agent metadata ---
.git/
.github/
.gitignore
.gitattributes
.editorconfig
.serena/
.worktrees/
.claude/

# --- IDE / local tooling ---
.vscode/
.idea/
.phpunit.cache/
.phpcs.cache

# --- Tests, dev-only sources ---
phpunit_tests/
coverage/
docs/

# --- Build / dev configuration with no runtime role ---
phpcs.xml
phpcs.xml.dist
phpstan.neon
phpstan.neon.dist
phpunit.xml
phpunit.xml.dist
captainhook.json
redocly.yaml
.markdownlint.yml
docker-compose.yml
docker-compose.yaml
Dockerfile
.dockerignore

# --- Local dev scripts (not used in production) ---
start-server.sh
stop-server.sh
restart-server.sh
server.pid
server.vscode.pid

# --- CLI tooling that cannot run in the SSH chroot anyway ---
# bin/ currently contains only doctrine-migrations, which is invoked
# in-process via the /_ops/migrate endpoint. doctrine-migrations.php
# (Doctrine config at the repo root) IS shipped — it's read by
# MigrateHandler via PhpFile.
bin/

# --- Composer install-time only (never read at runtime) ---
# composer.json IS shipped — Router::findProjectRoot() uses it as a
# project-root marker. composer.lock is only consumed by
# `composer install`, which runs on the runner.
composer.lock

# --- Project meta files ---
CLAUDE.md
README.md

# --- SERVER-MANAGED FILES (never overwrite, never --delete) ---
+ .env.example
.env
.env.*
logs/

# --- RUNTIME CACHE (preserve server-side state) ---
# Excludes contents only; the directories themselves are created on
# the server in a pre-rsync SSH-exec step so PHP can still write to
# them. /cache is the OIDC PSR-6 cache (JWKS + Zitadel roles, used by
# OidcAuthMiddleware). /public/engineCache is the calendar-engine
# memoization cache (per-locale Easter, per-version computed
# calendars; written by EasterHandler and CalendarHandler).
/cache/*
/public/engineCache/*
EXCLUDE
```

- [ ] **Step 2: Verify the file was written correctly**

```bash
wc -l .github/deploy/rsync-exclude.txt
head -3 .github/deploy/rsync-exclude.txt
```

Expected: ~75 lines, starts with the header comment.

- [ ] **Step 3: Commit**

```bash
git add .github/deploy/rsync-exclude.txt
git commit -m "feat(deploy): rsync exclude policy for chrooted Plesk deploy

Server-managed paths (.env*, logs/, cache/, public/engineCache/) and
non-runtime artifacts (bin/, composer.lock, tests, dev tooling) are
kept off the deploy. composer.json IS shipped (Router::findProjectRoot
file_exists marker). doctrine-migrations.php IS shipped (read by
MigrateHandler via PhpFile)."
```

---

## Task 12: Manual local smoke test

**Files:** none — this is a manual verification step before shipping the workflow.

- [ ] **Step 1: Set required env vars locally**

```bash
export DEPLOY_TOKEN="$(openssl rand -hex 32)"
export APP_ENV=staging
```

- [ ] **Step 2: Start the dev server**

```bash
composer start
```

Wait for `localhost:8000` to be ready.

- [ ] **Step 3: Test 401 for missing header**

```bash
curl -sS -o /dev/null -w '%{http_code}\n' -X POST http://localhost:8000/_ops/migrate
```

Expected: `401`.

- [ ] **Step 4: Test 401 for wrong token**

```bash
curl -sS -o /dev/null -w '%{http_code}\n' -X POST -H "X-Deploy-Token: wrongtoken" http://localhost:8000/_ops/migrate
```

Expected: `401`.

- [ ] **Step 5: Test 405 for GET on /_ops/migrate (only POST allowed)**

```bash
curl -sS -o /dev/null -w '%{http_code}\n' -H "X-Deploy-Token: $DEPLOY_TOKEN" http://localhost:8000/_ops/migrate
```

Expected: `405`.

- [ ] **Step 6: Test status endpoint with correct token (requires DB env vars too)**

If `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` are set in `.env.local` and PostgreSQL is reachable:

```bash
curl -sS -H "X-Deploy-Token: $DEPLOY_TOKEN" http://localhost:8000/_ops/migrate/status | head -20
```

Expected: 200 OK with Doctrine status output (current versions,
executed/non-executed counts). If the response is
`Failed to connect to database`, set up local PostgreSQL or skip this
step (CI will exercise it).

- [ ] **Step 7: Test 503 with wrong APP_ENV**

```bash
APP_ENV=development curl -sS -o /dev/null -w '%{http_code}\n' -X POST -H "X-Deploy-Token: $DEPLOY_TOKEN" http://localhost:8000/_ops/migrate
```

(That command ignores the local export because curl doesn't read PHP env.
Instead, restart the server with `APP_ENV=development composer start` and
retry, then restart with `APP_ENV=staging` for subsequent steps.)

Expected: `503`.

- [ ] **Step 8: Stop the server**

```bash
composer stop
```

- [ ] **Step 9: No commit — this task is verification-only**

If any expectation above failed, fix the implementation in Tasks 2–9 before proceeding to the workflow file.

---

## Task 13: deploy.yaml workflow file

**Files:**

- Create: `.github/workflows/deploy.yaml`

- [ ] **Step 1: Write the workflow**

```bash
cat > .github/workflows/deploy.yaml <<'YAML'
name: Deploy

on:
  workflow_dispatch:
    inputs:
      target:
        description: 'Deployment target'
        required: true
        type: choice
        options:
          - staging
          - production
        default: staging
      tag:
        description: 'Release tag (production only, e.g. v5.0 or v5.1.2). Ignored for staging.'
        required: false
        type: string
  release:
    types: [published]

concurrency:
  group: deploy-${{ github.event_name == 'release' && 'production' || inputs.target }}
  cancel-in-progress: false

jobs:
  deploy:
    name: Deploy ${{ github.event_name == 'release' && 'production' || inputs.target }}
    if: github.event_name != 'release' || github.event.release.prerelease == false
    runs-on: ubuntu-latest
    timeout-minutes: 15
    environment: ${{ github.event_name == 'release' && 'production' || inputs.target }}
    permissions:
      contents: read

    steps:
      - name: Resolve ref and deploy path
        id: params
        env:
          EVENT_NAME: ${{ github.event_name }}
          INPUT_TARGET: ${{ inputs.target }}
          INPUT_TAG: ${{ inputs.tag }}
          RELEASE_TAG: ${{ github.event.release.tag_name }}
          APP_DIR: ${{ vars.VPS_APP_DIR }}
        run: |
          set -euo pipefail
          if [ -z "${APP_DIR}" ]; then
            echo "::error::Repository variable VPS_APP_DIR is not set." >&2
            exit 1
          fi
          case "${EVENT_NAME}" in
            release)
              TARGET="production"
              TAG="${RELEASE_TAG}"
              ;;
            workflow_dispatch)
              TARGET="${INPUT_TARGET}"
              TAG="${INPUT_TAG}"
              ;;
            *)
              echo "::error::Unsupported event: ${EVENT_NAME}" >&2
              exit 1
              ;;
          esac
          if [ "${TARGET}" = "production" ]; then
            if [ -z "${TAG}" ]; then
              echo "::error::tag is required for production deploys (e.g. v5.0)." >&2
              exit 1
            fi
            if ! printf '%s' "${TAG}" | grep -qE '^v[0-9]+\.[0-9]+(\.[0-9]+)?(-[A-Za-z0-9.-]+)?$'; then
              echo "::error::tag must match v<MAJOR>.<MINOR>(.<PATCH>)?(-prerelease)? - got: ${TAG}" >&2
              exit 1
            fi
            MAJOR=$(printf '%s' "${TAG}" | sed -E 's/^v([0-9]+)\..*/\1/')
            REF="${TAG}"
            SUBDIR="v${MAJOR}"
          else
            REF="development"
            SUBDIR="dev"
          fi
          DEPLOY_PATH="${APP_DIR%/}/${SUBDIR}"
          {
            echo "ref=${REF}"
            echo "deploy_subdir=${SUBDIR}"
            echo "deploy_path=${DEPLOY_PATH}"
          } >> "${GITHUB_OUTPUT}"
          echo "::notice::Deploying ${TARGET}: ref=${REF} -> ${DEPLOY_PATH}"

      - name: Checkout
        uses: actions/checkout@de0fac2e4500dabe0009e67214ff5f5447ce83dd # v6.0.2
        with:
          ref: ${{ steps.params.outputs.ref }}
          persist-credentials: false

      - name: Verify modern codebase shape
        id: shape
        run: |
          set -euo pipefail
          if [ ! -f composer.json ]; then
            echo "::notice::Tag does not contain composer.json; treating as legacy snapshot and skipping the rest of the deploy."
            echo "skip=true" >> "${GITHUB_OUTPUT}"
          else
            echo "skip=false" >> "${GITHUB_OUTPUT}"
          fi

      - name: Set up PHP
        if: steps.shape.outputs.skip != 'true'
        uses: shivammathur/setup-php@accd6127cb78bee3e8082180cb391013d204ef9f # v2
        with:
          php-version: '8.4'
          extensions: intl, pdo_pgsql, json, simplexml, dom, calendar, zip, yaml, gettext, curl, xml
          tools: composer:v2
          coverage: none

      - name: Install production dependencies
        if: steps.shape.outputs.skip != 'true'
        run: composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist

      - name: Configure SSH
        if: steps.shape.outputs.skip != 'true'
        env:
          SSH_PRIVATE_KEY: ${{ secrets.VPS_SSH_PRIVATE_KEY }}
          SSH_KNOWN_HOSTS: ${{ secrets.VPS_SSH_KNOWN_HOSTS }}
        run: |
          set -euo pipefail
          if [ -z "${SSH_KNOWN_HOSTS}" ]; then
            echo "::error::Secret VPS_SSH_KNOWN_HOSTS is empty. Refusing to deploy without a pinned host key." >&2
            exit 1
          fi
          install -d -m 700 ~/.ssh
          printf '%s\n' "${SSH_PRIVATE_KEY}" > ~/.ssh/deploy_key
          chmod 600 ~/.ssh/deploy_key
          printf '%s\n' "${SSH_KNOWN_HOSTS}" > ~/.ssh/known_hosts
          chmod 644 ~/.ssh/known_hosts

      - name: Sanity-check pinned host key against DNS SSHFP records
        if: steps.shape.outputs.skip != 'true'
        env:
          SSH_KNOWN_HOSTS: ${{ secrets.VPS_SSH_KNOWN_HOSTS }}
          SSHFP_HOST: ${{ secrets.VPS_HOST }}
        run: |
          set -euo pipefail
          if ! command -v dig >/dev/null; then
            echo "::warning::dig not available; skipping SSHFP drift check."
            exit 0
          fi
          PIN_FPS=$(printf '%s\n' "${SSH_KNOWN_HOSTS}" \
            | awk '$2 ~ /^(ssh-|ecdsa-)/' \
            | while read -r _host _alg keyb64 _; do
                printf '%s' "$keyb64" | base64 -d 2>/dev/null | sha256sum | awk '{print toupper($1)}'
              done \
            | sort -u)
          DNS_FPS=$(dig +short SSHFP "${SSHFP_HOST}" \
            | awk '$2 == "2" {for (i=3; i<=NF; i++) printf "%s", $i; print ""}' \
            | tr -d ' ' \
            | tr 'a-f' 'A-F' \
            | sort -u)
          if [ -z "${DNS_FPS}" ]; then
            echo "::warning::No SHA-256 SSHFP records found for ${SSHFP_HOST}; skipping drift check."
            exit 0
          fi
          if [ -z "${PIN_FPS}" ]; then
            echo "::warning::Could not derive any fingerprints from VPS_SSH_KNOWN_HOSTS; skipping drift check."
            exit 0
          fi
          PIN_NOT_IN_DNS=$(comm -23 <(echo "${PIN_FPS}") <(echo "${DNS_FPS}"))
          DNS_NOT_IN_PIN=$(comm -13 <(echo "${PIN_FPS}") <(echo "${DNS_FPS}"))
          if [ -n "${PIN_NOT_IN_DNS}" ]; then
            while IFS= read -r fp; do
              echo "::warning::Pinned key SHA256:${fp} not in DNS SSHFP for ${SSHFP_HOST}."
            done <<< "${PIN_NOT_IN_DNS}"
          fi
          if [ -n "${DNS_NOT_IN_PIN}" ]; then
            while IFS= read -r fp; do
              echo "::warning::DNS SSHFP advertises SHA256:${fp} not present in VPS_SSH_KNOWN_HOSTS."
            done <<< "${DNS_NOT_IN_PIN}"
          fi
          if [ -z "${PIN_NOT_IN_DNS}" ] && [ -z "${DNS_NOT_IN_PIN}" ]; then
            echo "Pin matches DNS SSHFP records."
          fi

      - name: Ensure deploy + cache + logs dirs exist
        if: steps.shape.outputs.skip != 'true'
        env:
          VPS_USER: ${{ secrets.VPS_USERNAME }}
          VPS_HOST: ${{ secrets.VPS_HOST }}
          VPS_PORT: ${{ vars.VPS_SSH_PORT || '22' }}
          DEPLOY_PATH: ${{ steps.params.outputs.deploy_path }}
        run: |
          set -euo pipefail
          remote_cmd=$(printf 'mkdir -p %q %q %q %q' \
            "${DEPLOY_PATH}" \
            "${DEPLOY_PATH}/cache" \
            "${DEPLOY_PATH}/public/engineCache" \
            "${DEPLOY_PATH}/logs")
          ssh -i ~/.ssh/deploy_key -p "${VPS_PORT}" \
            -o IdentitiesOnly=yes \
            -o StrictHostKeyChecking=yes \
            -o UserKnownHostsFile=~/.ssh/known_hosts \
            -o ConnectTimeout=10 \
            -o ServerAliveInterval=15 \
            -o ServerAliveCountMax=2 \
            "${VPS_USER}@${VPS_HOST}" \
            "${remote_cmd}"

      - name: Deploy via rsync
        if: steps.shape.outputs.skip != 'true'
        env:
          VPS_USER: ${{ secrets.VPS_USERNAME }}
          VPS_HOST: ${{ secrets.VPS_HOST }}
          VPS_PORT: ${{ vars.VPS_SSH_PORT || '22' }}
          DEPLOY_PATH: ${{ steps.params.outputs.deploy_path }}
        run: |
          set -euo pipefail
          rsync \
            --archive --no-owner --no-group \
            --compress --human-readable --verbose \
            --delete --protect-args \
            --exclude-from=.github/deploy/rsync-exclude.txt \
            -e "ssh -i ~/.ssh/deploy_key -p ${VPS_PORT} -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=~/.ssh/known_hosts -o ConnectTimeout=10 -o ServerAliveInterval=15 -o ServerAliveCountMax=2" \
            ./ "${VPS_USER}@${VPS_HOST}:${DEPLOY_PATH%/}/"

      - name: Run database migrations
        if: steps.shape.outputs.skip != 'true'
        env:
          DEPLOY_TOKEN: ${{ secrets.DEPLOY_TOKEN }}
          BASE: ${{ vars.VPS_APP_BASE_URL }}
          SUBDIR: ${{ steps.params.outputs.deploy_subdir }}
        run: |
          set -euo pipefail
          if [ -z "${DEPLOY_TOKEN}" ]; then
            echo "::error::DEPLOY_TOKEN secret is empty." >&2
            exit 1
          fi
          if [ -z "${BASE}" ]; then
            echo "::error::Repository variable VPS_APP_BASE_URL is not set." >&2
            exit 1
          fi
          curl -fsS --max-time 600 --retry 2 --retry-delay 5 \
            -X POST \
            -H "X-Deploy-Token: ${DEPLOY_TOKEN}" \
            -H "Accept: text/plain" \
            "${BASE%/}/${SUBDIR}/_ops/migrate"

      - name: Post-deploy health check
        if: steps.shape.outputs.skip != 'true'
        env:
          BASE: ${{ vars.VPS_APP_BASE_URL }}
          SUBDIR: ${{ steps.params.outputs.deploy_subdir }}
        run: |
          set -euo pipefail
          curl -fsS --retry 3 --retry-delay 5 --max-time 10 \
            "${BASE%/}/${SUBDIR}/calendars" -o /dev/null

      - name: Clean up SSH key
        if: always() && steps.shape.outputs.skip != 'true'
        run: rm -f ~/.ssh/deploy_key
YAML
```

- [ ] **Step 2: Validate the YAML syntax**

```bash
python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/deploy.yaml'))" && echo OK
```

Expected: `OK`. If `yaml` is missing, `pip install pyyaml` or use `actionlint` if installed (`actionlint .github/workflows/deploy.yaml`).

- [ ] **Step 3: If actionlint is available, run it**

```bash
command -v actionlint && actionlint .github/workflows/deploy.yaml || echo "actionlint not installed; skipping"
```

Expected: no errors, or "actionlint not installed; skipping".

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/deploy.yaml
git commit -m "feat(deploy): GHA workflow for Plesk deploy + migrate

Manual workflow_dispatch (target=staging|production) and release-published
triggers; no auto-deploy on push. Builds vendor on the runner, rsyncs to
the chrooted Plesk user, runs migrations via authenticated POST
/_ops/migrate, then verifies with GET /calendars. Pinned host keys with
DNS SSHFP drift check; per-lane concurrency.

See docs/superpowers/specs/2026-05-10-issue-545-deployment-design.md."
```

---

## Task 14: Final verification, push, and PR

**Files:** none — final integration step.

- [ ] **Step 1: Run the full test suite**

```bash
composer test:quick
```

Expected: all green.

- [ ] **Step 2: Lint everything**

```bash
composer lint
composer lint:md 2>&1 | tail -3
composer analyse
```

Expected: all clean. PHPStan "[OK] No errors", phpcs exit 0, markdown lint 0 errors.

- [ ] **Step 3: Verify the file inventory**

```bash
git diff --stat origin/development...HEAD
```

Expected: 9 files added/modified. New: `DeployTokenMiddleware.php`,
`MigrateHandler.php`, `DeployTokenMiddlewareTest.php`,
`MigrateHandlerTest.php`, `deploy.yaml`, `rsync-exclude.txt`, the spec,
the plan. Modified: `Router.php`, `Route.php`, `.env.example`.

- [ ] **Step 4: Push the branch**

```bash
git push
```

Expected: pre-push hook runs `composer parallel-lint` and `composer analyse`; both should be clean.

- [ ] **Step 5: Open the PR**

```bash
gh pr create --base development --title "feat(deploy): GHA Plesk deployment + /_ops/migrate endpoint (#545)" --body "$(cat <<'EOF'
## Summary
- Implements issue #545: GitHub Actions workflow that deploys to a chrooted Plesk SSH user
- Adds `POST /_ops/migrate` and `GET /_ops/migrate/status` endpoints, gated by a new `DeployTokenMiddleware` (`X-Deploy-Token` header, `hash_equals`, env-gated to `staging`/`production`)
- `MigrateHandler` runs Doctrine Migrations programmatically via Symfony Console — no PHP CLI needed on the server
- Workflow is manual (workflow_dispatch) plus auto on release publish; no auto-deploy on push to development
- Vendor built on the runner; chrooted user gets no Composer access

## Design
See [`docs/superpowers/specs/2026-05-10-issue-545-deployment-design.md`](docs/superpowers/specs/2026-05-10-issue-545-deployment-design.md) for the full spec, including the rsync exclude policy, hardening details, and rollback strategy.

## Test plan
- [ ] PHPUnit suite green
- [ ] PHPStan level 10 clean
- [ ] phpcs clean
- [ ] markdown lint clean
- [ ] Manual smoke test (Task 12 in the plan): 401/401/405/200/503 paths exercised against `composer start`
- [ ] **Out-of-band**: GHA secrets, repo variables, GitHub Environments, server-side `.env.production` `DEPLOY_TOKEN`, Plesk FPM `request_terminate_timeout >= 600` — see spec §11
- [ ] First `workflow_dispatch` against `target=staging` to validate end-to-end before merging

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 6: Confirm CI is happy**

```bash
gh pr checks
```

Expected: all checks pass within a few minutes (build, phpunit_tests, codesniffer, static_analysis, markdownlint, codecov/patch).

- [ ] **Step 7: Out-of-band setup (user task — not part of CI)**

Per spec §11, the user must perform these steps once before the first workflow run:

1. `openssl rand -hex 32` → set as GHA secret `DEPLOY_TOKEN` and append to server's `.env.production`.
2. `ssh-keygen -t ed25519 -f deploy_key -C "github-actions@lcapi" -N ""`; install pubkey on server, store private key as GHA secret `VPS_SSH_PRIVATE_KEY`.
3. `ssh-keyscan -p $PORT $HOST` → store as GHA secret `VPS_SSH_KNOWN_HOSTS`.
4. Add GHA secrets `VPS_USERNAME`, `VPS_HOST`.
5. Add GHA variables `VPS_APP_DIR`, `VPS_SSH_PORT`, `VPS_APP_BASE_URL`.
6. Create GitHub Environments `staging`, `production` (optional required reviewers on `production`).
7. Plesk → confirm `request_terminate_timeout >= 600`.
8. `workflow_dispatch` with `target=staging`; watch the run.

- [ ] **Step 8: Final note**

Once the staging dispatch is green, this PR is ready for merge. Merging the PR alone does NOT trigger a deploy — the workflow only triggers on `workflow_dispatch` or `release: published`.

---

## Spec coverage check

- **§1 Goals** — Tasks 13 (workflow), 6 (handler), 3 (middleware).
- **§2 Architecture** — Task 13 (workflow assembles the runner→server
  flow); Task 6 (handler runs Doctrine in-process).
- **§3 Triggers and concurrency** — Task 13 (`on:` block, `concurrency:`
  group).
- **§4 Targets and deploy paths** — Task 13 (Resolve ref step).
- **§5 Workflow steps in order** — Task 13 (12 steps).
- **§6 Secrets and variables** — Task 13 (env: blocks); Task 14 step 7
  (out-of-band setup).
- **§7 rsync exclude policy** — Task 11.
- **§8 Deploy endpoint** — Tasks 2–4 (middleware tests + impl), 5–7
  (handler tests + impl), 8–9 (router wiring).
- **§9 Error handling and rollback** — Task 6 (400 on bad `?to=`, 500 on
  migrate failure); Task 13 (`curl -f`, retries).
- **§10 Testing strategy** — Tasks 2–4 (middleware tests), 5–7 (handler
  tests), 12 (manual smoke), 14 step 6 (CI).
- **§11 Out-of-band setup checklist** — Task 14 step 7.
- **§12 Implementation outline** — This entire plan.
- **§13 Open questions** — Documented in spec; no in-plan action required.
