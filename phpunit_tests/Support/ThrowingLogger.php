<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 logger whose every write throws.
 *
 * Stands in for a genuinely broken logging backend: `LoggerFactory::create()`
 * throws `\RuntimeException` when the logs directory cannot be created, and
 * Monolog's stream handlers throw `\UnexpectedValueException` when the target
 * stream cannot be opened (unwritable directory, full disk). Both are reachable
 * in production.
 *
 * The failure type matters. `\RuntimeException` is the very type the fail-closed
 * `catch` blocks in {@see \LiturgicalCalendar\Api\Services\ResourceAdminService}
 * are catching, so a logger that throws it from *inside* that catch would escape
 * the recovery path entirely unless the logging call is itself guarded.
 */
final class ThrowingLogger extends AbstractLogger
{
    public function __construct(private readonly \Throwable $toThrow = new \RuntimeException('logs directory is not writable'))
    {
    }

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        throw $this->toThrow;
    }
}
