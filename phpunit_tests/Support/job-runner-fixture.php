<?php

/**
 * Runs one fixture job through the real JobRunner, as `bin/litcal-jobs run <job>` would, against the database
 * named by the DB_* environment. Used by SupervisorProcessTest as the child command; not a test itself.
 *
 * Usage: php job-runner-fixture.php <hang|quick|loop>
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use LiturgicalCalendar\Api\Repositories\JobScheduleRepository;
use LiturgicalCalendar\Api\Services\Jobs\Job;
use LiturgicalCalendar\Api\Services\Jobs\JobDefinition;
use LiturgicalCalendar\Api\Services\Jobs\JobKind;
use LiturgicalCalendar\Api\Services\Jobs\JobRegistry;
use LiturgicalCalendar\Api\Services\Jobs\JobRunner;
use LiturgicalCalendar\Tests\Services\Jobs\Fixtures\HangJob;
use LiturgicalCalendar\Tests\Services\Jobs\Fixtures\LoopJob;
use LiturgicalCalendar\Tests\Services\Jobs\Fixtures\OkJob;
use Psr\Log\NullLogger;

$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT') ?: '5432', getenv('DB_NAME')),
    (string) getenv('DB_USER'),
    (string) getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

LoopJob::$selfStopAfterSeconds = 60.0;

$registry = new JobRegistry(
    new JobDefinition('hang', JobKind::INTERVAL, HangJob::class, static fn (): Job => new HangJob(), 60, 1),
    new JobDefinition('quick', JobKind::INTERVAL, OkJob::class, static fn (): Job => new OkJob(), 60, 5),
    new JobDefinition('loop', JobKind::STREAM, LoopJob::class, static fn (): Job => new LoopJob()),
);

exit(( new JobRunner($registry, new JobScheduleRepository($pdo), new NullLogger(), JobRunner::ownerId()) )->run($argv[1] ?? ''));
