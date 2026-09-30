<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs;

use Psr\Log\LoggerInterface;

/**
 * What a running job may ask of the runner: whether this is a dry run, whether it should stop, where to log,
 * and where to write operator-facing output (STDOUT by default, so a manual `run` shows it and a supervised
 * child's output reaches the unit's journal).
 */
final class JobContext
{
    /** @var \Closure(): bool */
    private readonly \Closure $shouldStop;

    /** @var \Closure(string): void */
    private readonly \Closure $writer;

    /**
     * @param \Closure(): bool            $shouldStop
     * @param (\Closure(string): void)|null $writer
     */
    public function __construct(
        public readonly bool $dryRun,
        \Closure $shouldStop,
        public readonly LoggerInterface $logger,
        ?\Closure $writer = null
    ) {
        $this->shouldStop = $shouldStop;
        $this->writer     = $writer ?? static function (string $line): void {
            echo $line . PHP_EOL;
        };
    }

    public function shouldStop(): bool
    {
        return ( $this->shouldStop )();
    }

    public function say(string $line): void
    {
        ( $this->writer )($line);
    }
}
