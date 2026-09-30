<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs\Catalog;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\Jobs\Job;
use LiturgicalCalendar\Api\Services\Jobs\JobDefinition;
use LiturgicalCalendar\Api\Services\Jobs\JobKind;
use LiturgicalCalendar\Api\Services\Jobs\JobRegistry;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\Outbox\BackstopRunner;
use LiturgicalCalendar\Api\Services\Outbox\CascadeReconciler;
use LiturgicalCalendar\Api\Services\Outbox\ConsumerLoop;
use LiturgicalCalendar\Api\Services\Outbox\OutboxProcessor;
use LiturgicalCalendar\Api\Services\Outbox\RedisStreamConsumer;
use LiturgicalCalendar\Api\Services\Outbox\ResourceTuplePurgeReconciler;
use LiturgicalCalendar\Api\Services\RedisConnection;
use LiturgicalCalendar\Api\Services\ResourceExistenceChecker;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeService;
use LiturgicalCalendar\Api\Services\SourceData\PublishConsumerLoop;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisherFactory;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublishNotifier;
use LiturgicalCalendar\Api\Services\SourceTreeGuard;
use LiturgicalCalendar\Api\Services\WiderRegionMembershipReconciler;
use LiturgicalCalendar\Api\Services\WiderRegionMembershipSeeder;

/**
 * The job runner's registry: every background job this deployment runs (#1008).
 *
 * Mirrors the table in docs/superpowers/specs/2026-09-29-job-runner-design.md §4.3. Every factory is a closure
 * that runs only in the child executing that job, so building this registry — which the supervisor and
 * `/health` both do — touches no database, Redis, OpenFGA or GitHub. Each factory wires its job exactly as the
 * matching `bin/` or `scripts/` entry point does, reading the same environment variables with the same
 * defaults, and every environment read lives here in `src/` so PHPStan covers it.
 */
final class JobCatalog
{
    /**
     * Rows the outbox consumer's due-retry pass takes per tick (#1013). Small, because during an OpenFGA outage each
     * one costs a full request timeout while stream messages wait; the rest are taken on the next ticks.
     */
    public const DUE_RETRIES_PER_TICK = 20;

    public static function default(): JobRegistry
    {
        return new JobRegistry(
            new JobDefinition('outbox-consumer', JobKind::STREAM, OutboxConsumerJob::class, self::outboxConsumer(...)),
            new JobDefinition('publish-consumer', JobKind::STREAM, PublishConsumerJob::class, self::publishConsumer(...)),
            new JobDefinition('outbox-backstop', JobKind::INTERVAL, OutboxBackstopJob::class, self::outboxBackstop(...), 300, 240),
            new JobDefinition('publish-backstop', JobKind::INTERVAL, PublishBackstopJob::class, self::publishBackstop(...), 60, 900),
            new JobDefinition('merge-poll', JobKind::INTERVAL, MergePollJob::class, self::mergePoll(...), 60, 300),
            new JobDefinition('wider-region-membership', JobKind::INTERVAL, WiderRegionMembershipJob::class, self::widerRegionMembership(...), 86400, 900),
            new JobDefinition('resource-tuple-sweep', JobKind::INTERVAL, ResourceTupleSweepJob::class, self::resourceTupleSweep(...), 86400, 1800),
        );
    }

    /**
     * Process-entry wiring for a stream job: needs ext-redis and a live Redis, which the test suite's CI does not
     * have. What it builds is tested directly — the loop in ConsumerLoopTest / PublishConsumerLoopTest, the
     * wrapper in CatalogJobsTest — so this is the same kind of code as a bin/ entry point.
     *
     * @codeCoverageIgnore
     */
    private static function outboxConsumer(): Job
    {
        if (!extension_loaded('redis')) {
            throw new \RuntimeException('ext-redis is required for the outbox consumer but is not installed.');
        }
        $processor = self::outboxProcessor();
        $cascade   = CascadeReconciler::fromEnv();
        $stream    = new RedisStreamConsumer(
            RedisConnection::openOrFail(),
            self::env('REDIS_OUTBOX_STREAM', 'litcal:reconcile-stream'),
            self::env('REDIS_OUTBOX_GROUP', 'reconciler'),
            self::env('REDIS_OUTBOX_CONSUMER_NAME', gethostname() ?: 'consumer')
        );
        $retries   = BackstopRunner::forDueRetries(
            new OutboxRepository(Connection::getInstance()),
            $processor,
            Connection::getInstance(),
            $cascade
        );
        $loop      = new ConsumerLoop(
            $stream,
            $processor,
            blockMs: 5000,
            cascade: $cascade,
            dueRetries: static fn (): int => $retries->runOnce(limit: self::DUE_RETRIES_PER_TICK)
        );

        return new OutboxConsumerJob(static function (callable $shouldStop) use ($loop): void {
            $loop->run($shouldStop);
        });
    }

    /**
     * Process-entry wiring for a stream job: needs ext-redis and a live Redis, which the test suite's CI does not
     * have. What it builds is tested directly — the loop in ConsumerLoopTest / PublishConsumerLoopTest, the
     * wrapper in CatalogJobsTest — so this is the same kind of code as a bin/ entry point.
     *
     * @codeCoverageIgnore
     */
    private static function publishConsumer(): Job
    {
        if (!extension_loaded('redis')) {
            throw new \RuntimeException('ext-redis is required for the publish consumer but is not installed.');
        }
        // The child is dedicated to this job, so the restrictive umask protecting the GitHub App token cache
        // simply stays in effect for its lifetime — the same reasoning as bin/publish-sourcedata-consumer.
        umask(0o077);
        $factory = new SourceDataPublisherFactory();
        $logger  = $factory->logger('publish-consumer');
        $stream  = new RedisStreamConsumer(
            RedisConnection::openOrFail($logger),
            self::env('REDIS_SOURCEDATA_PUBLISH_STREAM', 'litcal:sourcedata-publish-stream'),
            self::env('REDIS_SOURCEDATA_PUBLISH_GROUP', 'sourcedata-publisher'),
            self::env('REDIS_SOURCEDATA_PUBLISH_CONSUMER', gethostname() ?: 'consumer'),
            $logger,
            // Not the class default ('row_id', the outbox's field): see bin/publish-sourcedata-consumer.
            SourceDataPublishNotifier::BATCH_ID_FIELD
        );
        $loop = new PublishConsumerLoop($stream, $factory->publishRunner($logger), blockMs: 5000, logger: $logger);

        return new PublishConsumerJob(static function (callable $shouldStop) use ($loop): void {
            $loop->run($shouldStop);
        });
    }

    private static function outboxBackstop(): Job
    {
        $runner = new BackstopRunner(
            new OutboxRepository(Connection::getInstance()),
            self::outboxProcessor(),
            Connection::getInstance(),
            graceSeconds: self::envInt('OUTBOX_BACKSTOP_GRACE_SECONDS', 60),
            cascade: CascadeReconciler::fromEnv()
        );

        return new OutboxBackstopJob(static fn (): int => $runner->runOnce(limit: 200));
    }

    private static function publishBackstop(): Job
    {
        umask(0o077);
        $factory = new SourceDataPublisherFactory();
        $runner  = $factory->publishRunner($factory->logger('publish-backstop'));

        return new PublishBackstopJob(static fn () => $runner->runOnce(10));
    }

    private static function mergePoll(): Job
    {
        umask(0o077);
        $factory = new SourceDataPublisherFactory();
        $runner  = $factory->mergePollRunner($factory->logger('merge-poll'));

        return new MergePollJob(static fn () => $runner->runOnce());
    }

    private static function widerRegionMembership(): Job
    {
        self::requireOpenFga();
        $reconciler = new WiderRegionMembershipReconciler(OpenFgaClient::fromEnv());

        return new WiderRegionMembershipJob(
            new SourceTreeGuard(),
            static fn (bool $apply): array => ( new WiderRegionMembershipSeeder() )->reconcile(
                $reconciler,
                JsonData::NATIONAL_CALENDARS_FOLDER->path(),
                $apply
            )
        );
    }

    private static function resourceTupleSweep(): Job
    {
        self::requireOpenFga();
        $client     = OpenFgaClient::fromEnv();
        $pdo        = Connection::getInstance();
        $repo       = new OutboxRepository($pdo);
        $guard      = new SourceTreeGuard();
        $reconciler = new ResourceTuplePurgeReconciler(
            $client,
            new ResourceExistenceChecker(),
            new ResourceTuplePurgeService($client, $repo, new OutboxProcessor($repo, $client), $pdo),
            $guard
        );

        return new ResourceTupleSweepJob($guard, static fn (bool $apply): array => $reconciler->sweep($apply));
    }

    private static function outboxProcessor(): OutboxProcessor
    {
        return new OutboxProcessor(
            new OutboxRepository(Connection::getInstance()),
            OpenFgaClient::fromEnv(),
            self::envInt('OUTBOX_MAX_ATTEMPTS', 10)
        );
    }

    private static function requireOpenFga(): void
    {
        if (!OpenFgaClient::isConfigured()) {
            throw new \RuntimeException('OpenFGA is not configured. Set OPENFGA_API_URL, OPENFGA_STORE_ID, and OPENFGA_MODEL_ID.');
        }
    }

    /** A blank value counts as unset, as in bin/publish-sourcedata-consumer. */
    private static function env(string $name, string $default): string
    {
        $value = SourceDataPublisherFactory::envString($name);

        return $value === '' ? $default : $value;
    }

    private static function envInt(string $name, int $default): int
    {
        $value = SourceDataPublisherFactory::envString($name);

        return is_numeric($value) ? (int) $value : $default;
    }
}
