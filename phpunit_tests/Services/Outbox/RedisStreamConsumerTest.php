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

    public function testClaimStaleSkipsWhenPelIsEmpty(): void
    {
        $redis = $this->createMock(\Redis::class);
        // First xPending call (summary form, no PEL entries).
        $redis->method('xPending')->willReturn(false);
        // Empty xReadGroup → no main-loop work either; just verifies the
        // early-exit from claimStale doesn't crash.
        $redis->method('xReadGroup')->willReturn([]);
        $redis->expects(self::never())->method('xClaim');
        $redis->expects(self::never())->method('xAck');

        $consumer = new RedisStreamConsumer($redis, 'litcal:reconcile-stream', 'reconciler', 'consumer-1');
        $consumer->readOnce(blockMs: 100, process: function (int $rowId): void {
        });
        $this->addToAssertionCount(1);
    }

    public function testClaimStaleXClaimsAndAcksIdleMessages(): void
    {
        $redis = $this->createMock(\Redis::class);
        // Summary form: non-empty so the detail-form call proceeds.
        // Detail form: one stale entry idle 45_000ms > the 30_000 threshold.
        $redis->method('xPending')->willReturnOnConsecutiveCalls(
            [1, '1700000000-0', '1700000000-0', [['consumer-x', '1']]],     // summary form
            [['1700000000-0', 'consumer-x', 45_000, 1]],                    // detail form
        );
        $redis->expects(self::once())
            ->method('xClaim')
            ->willReturn([
                '1700000000-0' => ['row_id' => '99', 'op' => 'write_tuple'],
            ]);
        // No new XREADGROUP messages.
        $redis->method('xReadGroup')->willReturn([]);
        // xAck must fire for the claimed-and-processed message.
        $redis->expects(self::once())
            ->method('xAck')
            ->with('litcal:reconcile-stream', 'reconciler', ['1700000000-0'])
            ->willReturn(1);

        $captured = null;
        $consumer = new RedisStreamConsumer($redis, 'litcal:reconcile-stream', 'reconciler', 'consumer-1');
        $consumer->readOnce(
            blockMs: 100,
            process: function (int $rowId) use (&$captured): void {
                $captured = $rowId;
            },
        );

        self::assertSame(99, $captured);
    }

    public function testClaimStaleSkipsClaimsForFreshEntries(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('xPending')->willReturnOnConsecutiveCalls(
            [1, '1700000000-0', '1700000000-0', [['consumer-x', '1']]],     // summary
            [['1700000000-0', 'consumer-x', 5_000, 1]],                     // 5s idle — fresh
        );
        // 5s < 30s threshold → no XCLAIM.
        $redis->expects(self::never())->method('xClaim');
        $redis->method('xReadGroup')->willReturn([]);
        $redis->expects(self::never())->method('xAck');

        $consumer = new RedisStreamConsumer($redis, 'litcal:reconcile-stream', 'reconciler', 'consumer-1');
        $consumer->readOnce(blockMs: 100, process: function (int $rowId): void {
        });
        $this->addToAssertionCount(1);
    }

    public function testReadOnceAcksBadMessageWithMissingRowIdWithoutInvokingCallback(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('xPending')->willReturn(false);
        $redis->method('xReadGroup')->willReturn([
            'litcal:reconcile-stream' => [
                '1700000000-0' => ['op' => 'write_tuple'], // missing row_id
            ],
        ]);
        // Bad message must still be acked so it doesn't loop forever in the PEL.
        $redis->expects(self::once())
            ->method('xAck')
            ->with('litcal:reconcile-stream', 'reconciler', ['1700000000-0'])
            ->willReturn(1);

        $callbackCalled = false;
        $consumer       = new RedisStreamConsumer($redis, 'litcal:reconcile-stream', 'reconciler', 'consumer-1');
        $consumer->readOnce(
            blockMs: 100,
            process: function (int $rowId) use (&$callbackCalled): void {
                $callbackCalled = true;
            },
        );

        self::assertFalse($callbackCalled, 'callback must NOT fire for malformed message');
    }

    public function testReadOnceLeavesBadProcessThrowInPel(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('xPending')->willReturn(false);
        $redis->method('xReadGroup')->willReturn([
            'litcal:reconcile-stream' => [
                '1700000000-0' => ['row_id' => '7', 'op' => 'write_tuple'],
            ],
        ]);
        // Process throws → no xAck (message stays in PEL for next pass).
        $redis->expects(self::never())->method('xAck');

        $consumer = new RedisStreamConsumer($redis, 'litcal:reconcile-stream', 'reconciler', 'consumer-1');
        $consumer->readOnce(
            blockMs: 100,
            process: function (int $rowId): void {
                throw new \RuntimeException('boom');
            },
        );
        $this->addToAssertionCount(1);
    }
}
