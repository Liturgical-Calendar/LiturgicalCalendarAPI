<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Outbox;

use LiturgicalCalendar\Api\Services\Outbox\OutboxNotifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutboxNotifier::class)]
final class OutboxNotifierTest extends TestCase
{
    public function testNotifyXAddsToStream(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('xAdd')
            ->with(
                self::equalTo('litcal:reconcile-stream'),
                self::equalTo('*'),
                self::callback(static function (array $payload): bool {
                    return ( $payload['row_id'] ?? null ) === '42' && ( $payload['op'] ?? null ) === 'write_tuple';
                }),
            )
            ->willReturn('1234567890-0');

        $notifier = new OutboxNotifier($redis, 'litcal:reconcile-stream');
        $notifier->notify(42, 'write_tuple');
    }

    public function testNotifyOnRedisExceptionDoesNotPropagate(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('xAdd')
            ->willThrowException(new \RedisException('connection refused'));

        $notifier = new OutboxNotifier($redis, 'litcal:reconcile-stream');

        // Must NOT throw — the outbox row is durable in PG; the backstop will pick it up.
        $notifier->notify(42, 'write_tuple');
        $this->addToAssertionCount(1);
    }

    public function testNotifyWithNullRedisIsNoOp(): void
    {
        $notifier = new OutboxNotifier(null, 'litcal:reconcile-stream');
        $notifier->notify(42, 'write_tuple');
        $this->addToAssertionCount(1);
    }
}
