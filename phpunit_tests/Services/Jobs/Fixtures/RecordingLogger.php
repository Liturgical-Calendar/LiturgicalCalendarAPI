<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Jobs\Fixtures;

use Psr\Log\AbstractLogger;

/** Keeps every record, so a test can assert what was logged and how often. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /** @param array<mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    public function count(string $needle): int
    {
        $n = 0;
        foreach ($this->records as $record) {
            if (str_contains($record['message'] . ' ' . json_encode($record['context']), $needle)) {
                ++$n;
            }
        }

        return $n;
    }
}
