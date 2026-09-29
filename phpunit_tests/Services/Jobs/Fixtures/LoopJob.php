<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Jobs\Fixtures;

use LiturgicalCalendar\Api\Services\Jobs\Job;
use LiturgicalCalendar\Api\Services\Jobs\JobContext;

/**
 * A stream job: loops until asked to stop, or until `$selfStopAfterSeconds` pass. `$onTick`, when set, runs
 * on every pass with the seconds elapsed, so a test can act on the database mid-run.
 */
final class LoopJob implements Job
{
    public static float $selfStopAfterSeconds = 5.0;

    /** @var (\Closure(float): void)|null */
    public static ?\Closure $onTick = null;

    public static bool $stoppedByRequest = false;

    public static float $ranForSeconds = 0.0;

    public static function reset(): void
    {
        self::$selfStopAfterSeconds = 5.0;
        self::$onTick               = null;
        self::$stoppedByRequest     = false;
        self::$ranForSeconds        = 0.0;
    }

    public function run(JobContext $context): void
    {
        $start = microtime(true);
        while (true) {
            $elapsed = microtime(true) - $start;
            if ($context->shouldStop()) {
                self::$stoppedByRequest = true;
                break;
            }
            if ($elapsed >= self::$selfStopAfterSeconds) {
                break;
            }
            if (self::$onTick !== null) {
                ( self::$onTick )($elapsed);
            }
            usleep(50_000);
        }
        self::$ranForSeconds = microtime(true) - $start;
    }
}
