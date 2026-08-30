<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublishNotifier;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceDataPublishNotifier::class)]
final class SourceDataPublishNotifierTest extends TestCase
{
    // NO `extension_loaded('redis')` guard on any test in this file, deliberately.
    // `phpunit_tests/bootstrap.php` loads `stubs/Redis.php` whenever the extension is absent, so
    // `\Redis` and `\RedisException` always exist under PHPUnit. A skip guard here would silently
    // skip this class's two most important tests on any machine without ext-redis — which is every
    // developer machine AND CI.

    public function testANullRedisIsAQuietNoOp(): void
    {
        // A self-hoster running cron only has no Redis. This must not throw and must not log.
        $handler  = new TestHandler();
        $notifier = new SourceDataPublishNotifier(null, 'litcal:publish-stream', new Logger('t', [$handler]));

        $notifier->notify('batch-1');

        self::assertSame([], $handler->getRecords());
    }

    public function testItXaddsTheBatchId(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('xAdd')
            ->with('litcal:publish-stream', '*', ['batch_id' => 'batch-1'])
            ->willReturn('1-0');

        ( new SourceDataPublishNotifier($redis, 'litcal:publish-stream') )->notify('batch-1');
    }

    /**
     * The whole contract: the batch is already durable in Postgres and cron is the backstop, so a
     * Redis failure costs latency and never correctness. It must never propagate into the approval
     * the caller has already committed.
     */
    public function testARedisFailureIsLoggedAndSwallowed(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('xAdd')->willThrowException(new \RedisException('connection refused'));

        $handler = new TestHandler();
        ( new SourceDataPublishNotifier($redis, 'litcal:publish-stream', new Logger('t', [$handler])) )
            ->notify('batch-1');

        self::assertTrue($handler->hasWarningThatContains('sourcedata.redis.notify_failed'));
    }
}
