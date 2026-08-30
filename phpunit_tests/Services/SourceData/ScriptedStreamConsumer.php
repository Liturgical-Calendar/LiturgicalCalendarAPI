<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Services\Outbox\StreamConsumerInterface;

/**
 * A stream consumer that replays a fixed script of messages, one `readOnce()` at a time. An
 * empty array for a tick means "blocked and nothing arrived" — the idle tick.
 *
 * This is our own test-only implementation of {@see StreamConsumerInterface} (an interface, not
 * a `final` class), so doubling it here is ordinary and does not run into the problem described
 * in {@see PublishConsumerLoopTest}'s own docblock, which is about `PublishRunner` and
 * `MergePollRunner` — concrete `final` classes used directly by `PublishConsumerLoop`.
 */
final class ScriptedStreamConsumer implements StreamConsumerInterface
{
    public int $ensureGroupCalls = 0;

    /** @param list<list<string>> $script */
    public function __construct(private array $script)
    {
    }

    public function ensureGroup(): void
    {
        $this->ensureGroupCalls++;
    }

    public function readOnce(int $blockMs, callable $process): void
    {
        foreach (array_shift($this->script) ?? [] as $id) {
            $process($id);
        }
    }
}
