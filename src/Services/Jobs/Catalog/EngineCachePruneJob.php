<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs\Catalog;

use LiturgicalCalendar\Api\Services\EngineCache\EngineCache;
use LiturgicalCalendar\Api\Services\EngineCache\EngineCachePruner;
use LiturgicalCalendar\Api\Services\Jobs\DestructiveJob;
use LiturgicalCalendar\Api\Services\Jobs\JobContext;

/**
 * Destructive interval job: deletes engine-cache files older than ENGINE_CACHE_MAX_AGE_DAYS (default 30) and the
 * directories that leaves empty. Every entry is regenerated on demand, so it frees disk at the cost of
 * recomputing a rarely-requested calendar.
 *
 * It deletes files, so it is a {@see DestructiveJob}: `--dry-run` lists what it would free and deletes nothing,
 * and the preflight refuses a cache root that is a symbolic link. With ENGINE_CACHE_MAX_AGE_DAYS=0 it succeeds
 * without touching anything.
 */
final class EngineCachePruneJob implements DestructiveJob
{
    public function __construct(private readonly EngineCachePruner $pruner)
    {
    }

    public function preflight(): ?string
    {
        return $this->pruner->isEnabled() ? $this->pruner->refusalReason() : null;
    }

    /** @throws \RuntimeException When some expired file could not be deleted, so the run is recorded as failed. */
    public function run(JobContext $context): void
    {
        if (!$this->pruner->isEnabled()) {
            $context->say(sprintf('engine-cache-prune disabled (%s=0); nothing deleted', EngineCache::MAX_AGE_ENV));

            return;
        }

        $result  = $this->pruner->prune($context->dryRun, static fn (): bool => $context->shouldStop(), $context->logger);
        $summary = $result->summary($this->pruner->maxAgeDays(), $context->dryRun);
        $context->say($summary);
        $context->logger->info('Engine cache pruned.', [
            'job'          => 'engine-cache-prune',
            'root'         => $this->pruner->root(),
            'max_age_days' => $this->pruner->maxAgeDays(),
            'dry_run'      => $context->dryRun,
        ] + $result->toArray());

        if ($result->failed > 0) {
            throw new \RuntimeException(sprintf(
                '%d expired engine cache entr%s could not be deleted; see the jobs log.',
                $result->failed,
                $result->failed === 1 ? 'y' : 'ies'
            ));
        }
    }
}
