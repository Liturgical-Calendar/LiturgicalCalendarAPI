<?php

/**
 * Repeatedly calls SourceDataChangeRequestRepository::claimNextPublishableBatch() against a
 * real Postgres connection until three consecutive calls return null, printing every claimed
 * batch id to stdout (one per line).
 *
 * Run as its own OS process, two at a time, by
 * {@see \LiturgicalCalendar\Tests\Repositories\SourceDataChangeRequestPublishQueueTest::testTwoRealConcurrentRunnersNeverClaimTheSameBatch()}.
 *
 * This has to be a genuinely separate process rather than a second PDO connection driven from
 * the same PHP script: PHP is single-threaded, so two `claimNextPublishableBatch()` calls made
 * from one process — even against two different connections — execute strictly one after the
 * other and never overlap in time. The race this guards against needs real OS-level
 * concurrency: one runner's SELECT and another runner's COMMIT genuinely interleaved, which
 * only two independent processes hitting Postgres at once can produce.
 *
 * DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASSWORD are read from the process environment,
 * which the parent test populates explicitly via proc_open()'s env argument (rather than this
 * script re-deriving them from .env* itself) so both workers are guaranteed to target the exact
 * same database the parent test set up its fixture batches in.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;

$pdo = new PDO(
    sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        (string) getenv('DB_HOST'),
        (string) ( getenv('DB_PORT') ?: '5432' ),
        (string) getenv('DB_NAME')
    ),
    (string) getenv('DB_USER'),
    (string) getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$repo = new SourceDataChangeRequestRepository($pdo);

$consecutiveMisses = 0;
while ($consecutiveMisses < 3) {
    $batchId = $repo->claimNextPublishableBatch();
    if ($batchId === null) {
        $consecutiveMisses++;
        usleep(1000);
        continue;
    }
    $consecutiveMisses = 0;
    echo $batchId, "\n";
}
