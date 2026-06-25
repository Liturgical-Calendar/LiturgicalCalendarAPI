<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Concerns;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\Outbox\OutboxProcessor;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeService;

/**
 * Lazy accessors for outbox/purge tooling, shared by write handlers.
 *
 * Handlers that perform resource mutations (delete, create) `use` this trait
 * to get a consistent, lazily-built {@see ResourceTuplePurgeService} and the
 * lower-level building blocks ({@see OutboxRepository}, PDO, FGA client) that
 * Task 9's create-sync path also needs.
 *
 * Every accessor honours a test-seam override (set*() methods) so unit tests
 * can inject mocks without touching environment variables.
 */
trait ResolvesOutboxTooling
{
    private ?ResourceTuplePurgeService $purgeService = null;
    private ?OutboxRepository $outboxRepository      = null;

    // -------------------------------------------------------------------------
    // Test seams
    // -------------------------------------------------------------------------

    public function setPurgeService(ResourceTuplePurgeService $s): void
    {
        $this->purgeService = $s;
    }

    public function setOutboxRepository(OutboxRepository $r): void
    {
        $this->outboxRepository = $r;
    }

    // -------------------------------------------------------------------------
    // Lazy accessors
    // -------------------------------------------------------------------------

    /**
     * Returns null when OpenFGA is not configured so callers can skip silently.
     */
    protected function getPurgeService(): ?ResourceTuplePurgeService
    {
        if ($this->purgeService !== null) {
            return $this->purgeService;
        }
        if (!OpenFgaClient::isConfigured()) {
            return null;
        }
        $pdo                = $this->getOutboxPdo();
        $client             = $this->getFgaClient();
        $repo               = $this->getOutboxRepository();
        $processor          = new OutboxProcessor($repo, $client);
        $this->purgeService = new ResourceTuplePurgeService($client, $repo, $processor, $pdo);
        return $this->purgeService;
    }

    /**
     * Returns the shared OutboxRepository, creating it on first call.
     * Task 9's create-sync path reuses this directly.
     */
    protected function getOutboxRepository(): OutboxRepository
    {
        if ($this->outboxRepository !== null) {
            return $this->outboxRepository;
        }
        $this->outboxRepository = new OutboxRepository($this->getOutboxPdo());
        return $this->outboxRepository;
    }

    /**
     * Returns the live PDO connection from the project's singleton.
     * Task 9's create-sync path reuses this directly.
     */
    protected function getOutboxPdo(): \PDO
    {
        return Connection::getInstance();
    }

    /**
     * Returns a live OpenFGA client built from environment variables.
     * Task 9's create-sync path reuses this directly.
     */
    protected function getFgaClient(): OpenFgaClient
    {
        return OpenFgaClient::fromEnv();
    }
}
