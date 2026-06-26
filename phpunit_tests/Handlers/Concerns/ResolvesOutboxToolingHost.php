<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Concerns;

use LiturgicalCalendar\Api\Handlers\Concerns\ResolvesOutboxTooling;
use LiturgicalCalendar\Api\Repositories\OutboxBatchInsertInterface;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeServiceInterface;

/**
 * Concrete host class so we can exercise the trait's protected methods
 * via public proxy methods, without needing a real HTTP handler.
 */
class ResolvesOutboxToolingHost
{
    use ResolvesOutboxTooling;

    public function callGetPurgeService(): ?ResourceTuplePurgeServiceInterface
    {
        return $this->getPurgeService();
    }

    public function callGetOutboxRepository(): OutboxBatchInsertInterface
    {
        return $this->getOutboxRepository();
    }
}
