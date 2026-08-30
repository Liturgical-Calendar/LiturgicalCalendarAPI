<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Services\Outbox\StreamConsumerInterface;

/**
 * A stream consumer whose `readOnce()` always throws — stands in for a `\RedisException` from
 * `xReadGroup` (a dropped connection, a Redis restart) reaching {@see \LiturgicalCalendar\Api\Services\SourceData\PublishConsumerLoop::tick()}.
 *
 * `ensureGroup()` can also be made to throw, for the same reason on the other collaborator call
 * that sits outside `PublishConsumerLoop`'s inner try/catch.
 */
final class ThrowingReadStreamConsumer implements StreamConsumerInterface
{
    public int $ensureGroupCalls = 0;

    public int $readOnceCalls = 0;

    public function __construct(
        private readonly \Throwable $toThrow = new \RedisException('connection lost'),
        private readonly bool $throwOnEnsureGroup = false
    ) {
    }

    public function ensureGroup(): void
    {
        $this->ensureGroupCalls++;

        if ($this->throwOnEnsureGroup) {
            throw $this->toThrow;
        }
    }

    public function readOnce(int $blockMs, callable $process): void
    {
        $this->readOnceCalls++;

        throw $this->toThrow;
    }
}
