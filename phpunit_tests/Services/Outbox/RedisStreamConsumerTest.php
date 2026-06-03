<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Outbox;

use LiturgicalCalendar\Api\Services\Outbox\RedisStreamConsumer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RedisStreamConsumer::class)]
final class RedisStreamConsumerTest extends TestCase
{
    public function testEnsureGroupCreatesGroupIfMissing(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('xGroup')
            ->with('CREATE', 'litcal:reconcile-stream', 'reconciler', '0', true)
            ->willReturn(true);

        $consumer = new RedisStreamConsumer($redis, 'litcal:reconcile-stream', 'reconciler', 'consumer-1');
        $consumer->ensureGroup();
    }

    public function testEnsureGroupIgnoresBusygroup(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('xGroup')
            ->willThrowException(new \RedisException('BUSYGROUP Consumer Group name already exists'));

        $consumer = new RedisStreamConsumer($redis, 'litcal:reconcile-stream', 'reconciler', 'consumer-1');
        $consumer->ensureGroup();
        $this->addToAssertionCount(1);
    }

    public function testEnsureGroupReraisesOtherErrors(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('xGroup')
            ->willThrowException(new \RedisException('WRONGTYPE'));

        $consumer = new RedisStreamConsumer($redis, 'litcal:reconcile-stream', 'reconciler', 'consumer-1');
        $this->expectException(\RedisException::class);
        $this->expectExceptionMessage('WRONGTYPE');
        $consumer->ensureGroup();
    }

    public function testReadOneInvokesCallbackWithRowIdAndAcks(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('xReadGroup')->willReturn([
            'litcal:reconcile-stream' => [
                '1700000000-0' => ['row_id' => '42', 'op' => 'write_tuple'],
            ],
        ]);
        $redis->expects(self::once())
            ->method('xAck')
            ->with('litcal:reconcile-stream', 'reconciler', ['1700000000-0'])
            ->willReturn(1);

        $captured = null;
        $consumer = new RedisStreamConsumer($redis, 'litcal:reconcile-stream', 'reconciler', 'consumer-1');
        $consumer->readOnce(
            blockMs: 5000,
            process: function (int $rowId) use (&$captured): void {
                $captured = $rowId;
            },
        );

        self::assertSame(42, $captured);
    }

    public function testReadOneReturnsWithoutAckOnEmptyRead(): void
    {
        $redis = $this->createMock(\Redis::class);
        // Empty arrays come back from xReadGroup on timeout / no messages.
        $redis->method('xReadGroup')->willReturn([]);
        $redis->expects(self::never())->method('xAck');

        $consumer = new RedisStreamConsumer($redis, 'litcal:reconcile-stream', 'reconciler', 'consumer-1');
        $consumer->readOnce(blockMs: 100, process: function (int $rowId): void {
        });
        $this->addToAssertionCount(1);
    }
}
