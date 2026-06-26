<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Concerns;

use LiturgicalCalendar\Api\Repositories\OutboxBatchInsertInterface;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeServiceInterface;
use LiturgicalCalendar\Tests\Support\EnvIsolationTrait;
use PHPUnit\Framework\TestCase;

final class ResolvesOutboxToolingTest extends TestCase
{
    use EnvIsolationTrait;

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
}
