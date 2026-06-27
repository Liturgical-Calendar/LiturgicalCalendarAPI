<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Concerns;

use LiturgicalCalendar\Api\Repositories\OutboxBatchInsertInterface;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeServiceInterface;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use LiturgicalCalendar\Tests\Support\EnvIsolationTrait;

final class ResolvesOutboxToolingTest extends AbstractHandlerTestCase
{
    use EnvIsolationTrait;

    /** This concern's lazy-build path constructs a live PDO, so require Postgres. */
    protected static bool $requiresDatabase = true;

    // -------------------------------------------------------------------------
    // setPurgeService / getPurgeService
    // -------------------------------------------------------------------------

    public function testGetPurgeServiceReturnsInjectedService(): void
    {
        $service = $this->createStub(ResourceTuplePurgeServiceInterface::class);

        $host = new ResolvesOutboxToolingHost();
        $host->setPurgeService($service);

        $this->assertSame($service, $host->callGetPurgeService());
    }

    public function testGetPurgeServiceReturnsSameInstanceOnRepeatedCalls(): void
    {
        $service = $this->createStub(ResourceTuplePurgeServiceInterface::class);

        $host = new ResolvesOutboxToolingHost();
        $host->setPurgeService($service);

        $this->assertSame($host->callGetPurgeService(), $host->callGetPurgeService());
    }

    public function testGetPurgeServiceReturnsNullWhenFgaNotConfigured(): void
    {
        $result = $this->withoutEnv(self::OPENFGA_ENV_VARS, function (): ?ResourceTuplePurgeServiceInterface {
            $host = new ResolvesOutboxToolingHost();
            return $host->callGetPurgeService();
        });

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // setOutboxRepository / getOutboxRepository
    // -------------------------------------------------------------------------

    public function testGetOutboxRepositoryReturnsInjectedRepository(): void
    {
        $repo = $this->createStub(OutboxBatchInsertInterface::class);

        $host = new ResolvesOutboxToolingHost();
        $host->setOutboxRepository($repo);

        $this->assertSame($repo, $host->callGetOutboxRepository());
    }

    public function testGetOutboxRepositoryReturnsSameInstanceOnRepeatedCalls(): void
    {
        $repo = $this->createStub(OutboxBatchInsertInterface::class);

        $host = new ResolvesOutboxToolingHost();
        $host->setOutboxRepository($repo);

        $this->assertSame($host->callGetOutboxRepository(), $host->callGetOutboxRepository());
    }

    // -------------------------------------------------------------------------
    // Lazy production build path (no test seam injected)
    // -------------------------------------------------------------------------

    /**
     * Exercises the real lazy-build branch of getPurgeService(): with OpenFGA
     * "configured" and a live Postgres reachable, the trait builds the PDO
     * (Connection::getInstance()), the FGA client (OpenFgaClient::fromEnv() —
     * construction only, no network call), the OutboxRepository/OutboxProcessor,
     * and the ResourceTuplePurgeService. Skipped when Postgres is unavailable.
     */
    public function testLazyBuildsPurgeServiceWhenConfiguredAndDbAvailable(): void
    {
        // Postgres availability (real connectivity, not just env presence) is
        // guaranteed by AbstractHandlerTestCase::$requiresDatabase — setUp skips
        // this test when the connection attempt fails, so Connection::getInstance()
        // below is safe to call.
        //
        // Force OpenFGA "configured" so isConfigured() passes and the lazy
        // build runs; fromEnv() only constructs a client, so fake values work.
        $fake = [
            'OPENFGA_API_URL'  => 'http://localhost:8080',
            'OPENFGA_STORE_ID' => 'store-test',
            'OPENFGA_MODEL_ID' => 'model-test',
        ];
        /** @var array<string, array{0: string|null, 1: string|false}> $saved */
        $saved = [];
        foreach ($fake as $key => $value) {
            $saved[$key] = [array_key_exists($key, $_ENV) ? (string) $_ENV[$key] : null, getenv($key)];
            $_ENV[$key]  = $value;
            putenv("{$key}={$value}");
        }

        try {
            $host    = new ResolvesOutboxToolingHost();
            $service = $host->callGetPurgeService();

            $this->assertInstanceOf(ResourceTuplePurgeServiceInterface::class, $service);
            // Second call returns the cached instance (no rebuild).
            $this->assertSame($service, $host->callGetPurgeService());
            // The repository accessor also resolves to a live concrete repository.
            $this->assertInstanceOf(OutboxBatchInsertInterface::class, $host->callGetOutboxRepository());
        } finally {
            foreach ($saved as $key => [$envValue, $getenvValue]) {
                if ($envValue === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $envValue;
                }
                if ($getenvValue === false) {
                    putenv($key);
                } else {
                    putenv("{$key}={$getenvValue}");
                }
            }
        }
    }
}
