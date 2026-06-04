<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Outbox;

use LiturgicalCalendar\Api\Services\Outbox\CascadeReconcilerInterface;
use LiturgicalCalendar\Api\Services\Outbox\ConsumerLoop;
use LiturgicalCalendar\Api\Services\Outbox\OutboxDisposition;
use LiturgicalCalendar\Api\Services\Outbox\OutboxProcessorInterface;
use LiturgicalCalendar\Api\Services\Outbox\StreamConsumerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsumerLoop::class)]
final class ConsumerLoopTest extends TestCase
{
    public function testTickEnsuresGroupOnceAndDelegatesToConsumer(): void
    {
        $consumer = $this->createMock(StreamConsumerInterface::class);
        $consumer->expects(self::once())->method('ensureGroup');
        $consumer->expects(self::exactly(3))
            ->method('readOnce')
            ->with(5000, self::isCallable());

        // No expectations on processor in this test — use stub to avoid notice.
        $processor = $this->createStub(OutboxProcessorInterface::class);

        $loop = new ConsumerLoop($consumer, $processor, blockMs: 5000);
        $loop->tick();
        $loop->tick();
        $loop->tick();
    }

    public function testTickPassesRowIdToProcessor(): void
    {
        // No expectations on consumer beyond behavior — use stub to avoid notice.
        $consumer = $this->createStub(StreamConsumerInterface::class);
        $consumer->method('readOnce')->willReturnCallback(
            static function (int $blockMs, callable $process): void {
                $process(42);
            },
        );

        $processor = $this->createMock(OutboxProcessorInterface::class);
        $processor->expects(self::once())
            ->method('processOne')
            ->with(42)
            ->willReturn(OutboxDisposition::BENIGN_SUCCESS);

        $loop = new ConsumerLoop($consumer, $processor, blockMs: 5000);
        $loop->tick();
    }

    public function testTickInvokesCascadeReconcilerOnBenignSuccess(): void
    {
        $consumer = $this->createStub(StreamConsumerInterface::class);
        $consumer->method('readOnce')->willReturnCallback(
            static function (int $blockMs, callable $process): void {
                $process(7);
            },
        );

        $processor = $this->createMock(OutboxProcessorInterface::class);
        $processor->method('processOne')->with(7)->willReturn(OutboxDisposition::BENIGN_SUCCESS);

        $reconciler = $this->createMock(CascadeReconcilerInterface::class);
        $reconciler->expects(self::once())->method('evaluate')->with(7);

        $loop = new ConsumerLoop($consumer, $processor, blockMs: 5000, cascade: $reconciler);
        $loop->tick();
    }

    public function testTickDoesNotInvokeReconcilerWhenDispositionIsRetryOrTerminal(): void
    {
        $consumer = $this->createStub(StreamConsumerInterface::class);
        $consumer->method('readOnce')->willReturnCallback(
            static function (int $blockMs, callable $process): void {
                $process(7);
                $process(8);
            },
        );

        $processor = $this->createStub(OutboxProcessorInterface::class);
        $processor->method('processOne')->willReturnOnConsecutiveCalls(
            OutboxDisposition::RETRY,
            OutboxDisposition::TERMINAL,
        );

        $reconciler = $this->createMock(CascadeReconcilerInterface::class);
        $reconciler->expects(self::never())->method('evaluate');

        $loop = new ConsumerLoop($consumer, $processor, blockMs: 5000, cascade: $reconciler);
        $loop->tick();
    }

    public function testTickSwallowsReconcilerThrows(): void
    {
        $consumer = $this->createStub(StreamConsumerInterface::class);
        $consumer->method('readOnce')->willReturnCallback(
            static function (int $blockMs, callable $process): void {
                $process(7);
            },
        );

        $processor = $this->createStub(OutboxProcessorInterface::class);
        $processor->method('processOne')->willReturn(OutboxDisposition::BENIGN_SUCCESS);

        $reconciler = $this->createStub(CascadeReconcilerInterface::class);
        $reconciler->method('evaluate')->willThrowException(new \RuntimeException('cascade fail'));

        $loop = new ConsumerLoop($consumer, $processor, blockMs: 5000, cascade: $reconciler);
        $loop->tick(); // Must not throw.
        $this->addToAssertionCount(1);
    }
}
