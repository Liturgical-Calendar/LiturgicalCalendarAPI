<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * In-memory PSR-3 logger that records every write for later assertion.
 *
 * `createMock(LoggerInterface::class)` is the right tool when a test expects one
 * specific call; this spy is for the cases where a test needs to assert over the
 * whole set of records emitted during a call — e.g. "exactly one of the four
 * object types failed, and its name appears in the log".
 */
final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $records = [];

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => is_scalar($level) ? (string) $level : gettype($level),
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * Every record captured so far.
     *
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * Records captured at the given PSR-3 log level.
     *
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function recordsAtLevel(string $level): array
    {
        return array_values(array_filter($this->records, static fn(array $r): bool => $r['level'] === $level));
    }
}
