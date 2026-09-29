<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Jobs\Catalog;

use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\Jobs\Catalog\JobCatalog;
use LiturgicalCalendar\Api\Services\Jobs\Catalog\MergePollJob;
use LiturgicalCalendar\Api\Services\Jobs\Catalog\OutboxBackstopJob;
use LiturgicalCalendar\Api\Services\Jobs\Catalog\PublishBackstopJob;
use LiturgicalCalendar\Api\Services\Jobs\Catalog\ResourceTupleSweepJob;
use LiturgicalCalendar\Api\Services\Jobs\Catalog\WiderRegionMembershipJob;
use LiturgicalCalendar\Api\Services\Jobs\JobContext;
use LiturgicalCalendar\Tests\Support\EnvIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Every factory in the catalog, run for real. Whether a job can actually be built depends on the host (Redis,
 * OpenFGA, GitHub App credentials), so the assertion is the contract that holds everywhere: a factory either
 * builds its declared class or fails with a configuration error — never a PHP Error, which would mean a
 * wiring bug — and it leaves the process umask as it found it once the test restores it.
 */
#[CoversClass(JobCatalog::class)]
final class JobCatalogFactoriesTest extends TestCase
{
    use EnvIsolationTrait;

    private int $umask;

    protected function setUp(): void
    {
        Router::getApiPaths();
        $this->umask = umask();
    }

    protected function tearDown(): void
    {
        umask($this->umask);
    }

    /** @return array<string, array{string}> */
    public static function jobNames(): array
    {
        $names = [];
        foreach (JobCatalog::default()->names() as $name) {
            $names[$name] = [$name];
        }

        return $names;
    }

    #[DataProvider('jobNames')]
    public function testEachFactoryBuildsItsJobOrFailsWithAConfigurationError(string $name): void
    {
        $definition = JobCatalog::default()->get($name);
        self::assertNotNull($definition);

        try {
            $job = $definition->make();
            self::assertInstanceOf($definition->class, $job);
        } catch (\Error $e) {
            self::fail("{$name}'s factory hit a PHP Error, which is a wiring bug: " . $e::class . ': ' . $e->getMessage());
        } catch (\Throwable $e) {
            self::assertNotSame('', $e->getMessage(), "{$name}'s configuration error must say what is wrong");
        }
    }

    /** The destructive jobs refuse to build without OpenFGA, rather than failing later mid-run. */
    public function testTheDestructiveJobsNeedOpenFga(): void
    {
        $this->withoutEnv(['OPENFGA_API_URL', 'OPENFGA_STORE_ID', 'OPENFGA_MODEL_ID'], function (): void {
            foreach (['wider-region-membership', 'resource-tuple-sweep'] as $name) {
                try {
                    JobCatalog::default()->get($name)?->make();
                    self::fail("{$name} built without OpenFGA");
                } catch (\RuntimeException $e) {
                    self::assertStringContainsString('OpenFGA is not configured', $e->getMessage());
                }
            }
        });
    }

    /** The consumers and the outbox pieces read their settings from the environment, with the scripts' defaults. */
    public function testTheOutboxBackstopBuildsWithTheDatabaseAndOpenFga(): void
    {
        $definition = JobCatalog::default()->get('outbox-backstop');
        self::assertNotNull($definition);
        try {
            $job = $definition->make();
        } catch (\Error $e) {
            self::fail('wiring bug: ' . $e->getMessage());
        } catch (\Throwable $e) {
            self::markTestSkipped('outbox-backstop is not configurable on this host: ' . $e->getMessage());
        }
        self::assertSame($definition->class, $job::class);
    }

    /**
     * Placeholder OpenFGA and GitHub settings: none of these factories contacts either service while building,
     * so this exercises their wiring on any host, CI included, without a network call.
     *
     * @return array<string, string>
     */
    private function placeholderServices(): array
    {
        $key = sys_get_temp_dir() . '/jcf_' . uniqid() . '.pem';
        file_put_contents($key, 'not a real key');

        return [
            'OPENFGA_API_URL'             => 'http://127.0.0.1:1',
            'OPENFGA_STORE_ID'            => 'placeholder-store',
            'OPENFGA_MODEL_ID'            => 'placeholder-model',
            'GITHUB_APP_ID'               => '1',
            'GITHUB_APP_INSTALLATION_ID'  => '2',
            'GITHUB_APP_PRIVATE_KEY_PATH' => $key,
            'GITHUB_REPOSITORY'           => 'example/repo',
        ];
    }

    /**
     * @param array<string, string> $vars
     * @param callable(): void      $fn
     */
    private function withEnv(array $vars, callable $fn): void
    {
        $saved = [];
        foreach ($vars as $name => $value) {
            $saved[$name] = [$_ENV[$name] ?? null, getenv($name)];
            $_ENV[$name]  = $value;
            putenv("{$name}={$value}");
        }
        try {
            $fn();
        } finally {
            foreach ($saved as $name => [$env, $process]) {
                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }
                putenv($process === false ? $name : "{$name}={$process}");
            }
        }
    }

    public function testTheIntervalFactoriesBuildTheirJobsWhenTheServicesAreConfigured(): void
    {
        $services = $this->placeholderServices();
        try {
            $this->withEnv($services, function (): void {
                $expected = [
                    'publish-backstop'        => PublishBackstopJob::class,
                    'merge-poll'              => MergePollJob::class,
                    'wider-region-membership' => WiderRegionMembershipJob::class,
                    'resource-tuple-sweep'    => ResourceTupleSweepJob::class,
                ];
                foreach ($expected as $name => $class) {
                    self::assertInstanceOf($class, JobCatalog::default()->get($name)?->make(), $name);
                }
                try {
                    self::assertInstanceOf(OutboxBackstopJob::class, JobCatalog::default()->get('outbox-backstop')?->make());
                } catch (\Error $e) {
                    self::fail('outbox-backstop wiring bug: ' . $e->getMessage());
                } catch (\Throwable) {
                    // Its cascade reconciler also needs the identity provider's settings; not a wiring question.
                }
            });
        } finally {
            @unlink($services['GITHUB_APP_PRIVATE_KEY_PATH']);
        }
    }

    /**
     * The destructive jobs' own work, run against an empty data tree: each must reach its guard — inside the
     * seeder and the sweep — and refuse there, before OpenFGA is ever contacted.
     */
    public function testTheDestructiveJobsRefuseAnEmptyTreeInsideTheirOwnWork(): void
    {
        $services = $this->placeholderServices();
        $empty    = sys_get_temp_dir() . '/jcf_root_' . uniqid() . '/';
        mkdir($empty);
        $root = Router::$apiFilePath;
        try {
            $this->withEnv($services, function () use ($empty): void {
                foreach (['wider-region-membership', 'resource-tuple-sweep'] as $name) {
                    $job                 = JobCatalog::default()->get($name)?->make();
                    Router::$apiFilePath = $empty;
                    try {
                        $job?->run(new JobContext(true, static fn (): bool => false, new NullLogger(), static function (string $line): void {
                        }));
                        self::fail("{$name} ran against an empty tree");
                    } catch (\RuntimeException $e) {
                        self::assertStringContainsString('No national calendar files found', $e->getMessage(), $name);
                    }
                }
            });
        } finally {
            Router::$apiFilePath = $root;
            @rmdir($empty);
            @unlink($services['GITHUB_APP_PRIVATE_KEY_PATH']);
        }
    }
}
