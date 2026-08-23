<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Concerns;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Repositories\OutboxBatchInsertInterface;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\Outbox\OutboxProcessor;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeService;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeServiceInterface;

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
 *
 * The `$outboxRepository` field and related seam methods use
 * {@see OutboxBatchInsertInterface} (rather than the final concrete
 * {@see OutboxRepository}) so that unit tests can inject a mock without
 * requiring a live database connection.  When {@see OutboxProcessor} needs
 * to be instantiated (production only), a concrete {@see OutboxRepository}
 * is created directly from the PDO singleton inside {@see getPurgeService()}.
 */
trait ResolvesOutboxTooling
{
    /**
     * The OpenFGA client accessor Task 9's create-sync path and
     * {@see getPurgeService()} both reach for. Composing the shared trait
     * rather than re-declaring the accessor means this path now memoizes the
     * client — and its keep-alive connection — instead of rebuilding it on
     * every call.
     */
    use ResolvesFgaClient;

    private ?ResourceTuplePurgeServiceInterface $purgeService = null;
    private ?OutboxBatchInsertInterface $outboxRepository     = null;
    private ?\PDO $pdo                                        = null;

    // -------------------------------------------------------------------------
    // Test seams
    // -------------------------------------------------------------------------

    public function setPurgeService(ResourceTuplePurgeServiceInterface $s): void
    {
        $this->purgeService = $s;
    }

    /**
     * Inject a repository for testing — accepts the interface so tests can
     * pass a mock without needing a live database.
     */
    public function setOutboxRepository(OutboxBatchInsertInterface $r): void
    {
        $this->outboxRepository = $r;
    }

    // -------------------------------------------------------------------------
    // Lazy accessors
    // -------------------------------------------------------------------------

    /**
     * Returns null when OpenFGA is not configured so callers can skip silently.
     */
    protected function getPurgeService(): ?ResourceTuplePurgeServiceInterface
    {
        if ($this->purgeService !== null) {
            return $this->purgeService;
        }
        if (!OpenFgaClient::isConfigured()) {
            return null;
        }
        $pdo    = $this->getOutboxPdo();
        $client = $this->getFgaClient();
        $repo   = $this->getOutboxRepository();
        // OutboxProcessor requires the concrete OutboxRepository (it calls
        // getById / markSucceeded etc.); construct one from the PDO singleton
        // here rather than relying on $repo, which may be a test double.
        $fullRepo           = new OutboxRepository($pdo);
        $processor          = new OutboxProcessor($fullRepo, $client);
        $this->purgeService = new ResourceTuplePurgeService($client, $repo, $processor, $pdo);
        return $this->purgeService;
    }

    /**
     * Returns the shared batch-insert accessor, creating a live
     * {@see OutboxRepository} on first call.
     *
     * Task 9's create-sync path calls this for {@see OutboxRepository::insertBatch()}.
     * When a test seam has been injected via {@see setOutboxRepository()},
     * that object is returned instead (enabling mock-based assertions without
     * a real database).
     */
    protected function getOutboxRepository(): OutboxBatchInsertInterface
    {
        if ($this->outboxRepository !== null) {
            return $this->outboxRepository;
        }
        $this->outboxRepository = new OutboxRepository($this->getOutboxPdo());
        return $this->outboxRepository;
    }

    /**
     * Returns the live PDO connection from the project's singleton, cached locally.
     * Task 9's create-sync path reuses this directly.
     */
    protected function getOutboxPdo(): \PDO
    {
        if ($this->pdo === null) {
            $this->pdo = Connection::getInstance();
        }
        return $this->pdo;
    }
}
