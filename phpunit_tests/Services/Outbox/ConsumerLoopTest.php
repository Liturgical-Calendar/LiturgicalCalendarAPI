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
use Psr\Log\LoggerInterface;

#[CoversClass(ConsumerLoop::class)]
final class ConsumerLoopTest extends TestCase
{
    /** The job runner's SIGTERM reaches the loop as this callback; the loop must return rather than spin. */
    public function testRunReturnsWhenAskedToStop(): void
    {
        $consumer = $this->createMock(StreamConsumerInterface::class);
        $consumer->expects(self::exactly(2))->method('readOnce');
        $checks = 0;

        ( new ConsumerLoop($consumer, $this->createStub(OutboxProcessorInterface::class), blockMs: 0) )->run(
            static function () use (&$checks): bool {
                return ++$checks > 2;
            }
        );

        self::assertSame(3, $checks);
    }

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
        // The consumer now hands over a raw string; ConsumerLoop is the layer that casts it to int
        // before it reaches the processor.
        $consumer = $this->createStub(StreamConsumerInterface::class);
        $consumer->method('readOnce')->willReturnCallback(
            static function (int $blockMs, callable $process): void {
                $process('42');
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

    /**
     * The `<= 0` guard (and non-numeric rejection) moved here with the cast. The outbox's unit of
     * work is an integer row id, and this is now the only layer that knows that — the stream layer
     * itself no longer validates the shape of the id it hands over.
     *
     * The validation moving here from `RedisStreamConsumer` must carry its `bad_message` log line
     * with it — that class already logs and ACKs its own "no id at all" case, and a non-numeric or
     * non-positive id discarded silently here would be an observability regression on the very
     * same OpenFGA outbox path, invisible until a genuinely malformed stream went quiet with no
     * symptom at all.
     */
    public function testANonNumericOrNonPositiveIdIsNotProcessed(): void
    {
        $consumer = $this->createStub(StreamConsumerInterface::class);
        $consumer->method('readOnce')->willReturnCallback(
            static function (int $blockMs, callable $process): void {
                $process('0');
                $process('-1');
                $process('not-a-number');
                $process('7');
            },
        );

        $processor = $this->createMock(OutboxProcessorInterface::class);
        $processor->expects(self::once())
            ->method('processOne')
            ->with(7)
            ->willReturn(OutboxDisposition::BENIGN_SUCCESS);

        // Captures the id from every call, in call order, so the assertion below can check the
        // complete ORDERED sequence rather than only "each id is one of the expected three" —
        // the latter would also pass a loop that logged '0' three times in a row.
        $loggedIds = [];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(3))
            ->method('warning')
            ->with(
                'outbox.consumer.bad_message',
                self::callback(static function (array $ctx) use (&$loggedIds): bool {
                    $loggedIds[] = $ctx['id'] ?? null;

                    return true;
                }),
            );

        $loop = new ConsumerLoop($consumer, $processor, blockMs: 5000, logger: $logger);
        $loop->tick();

        self::assertSame(['0', '-1', 'not-a-number'], $loggedIds);
    }

    public function testTickInvokesCascadeReconcilerOnBenignSuccess(): void
    {
        $consumer = $this->createStub(StreamConsumerInterface::class);
        $consumer->method('readOnce')->willReturnCallback(
            static function (int $blockMs, callable $process): void {
                $process('7');
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
                $process('7');
                $process('8');
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
                $process('7');
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

    /**
     * #1013: a retry is not re-announced on the stream, so every tick ends with the due-retry pass. It runs after
     * the read, whether or not a message arrived.
     */
    public function testTickRunsTheDueRetryPassAfterTheRead(): void
    {
        $calls    = [];
        $consumer = $this->createStub(StreamConsumerInterface::class);
        $consumer->method('readOnce')->willReturnCallback(
            static function () use (&$calls): void {
                $calls[] = 'read';
            },
        );

        $loop = new ConsumerLoop(
            $consumer,
            $this->createStub(OutboxProcessorInterface::class),
            blockMs: 5000,
            dueRetries: static function () use (&$calls): int {
                $calls[] = 'retries';

                return 0;
            },
        );
        $loop->tick();
        $loop->tick();

        self::assertSame(['read', 'retries', 'read', 'retries'], $calls);
    }

    /**
     * A failing pass is logged and the loop carries on: stream messages must keep flowing, and the backstop still
     * covers any retry this pass could not take.
     */
    public function testTickLogsAndSurvivesAFailingDueRetryPass(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('outbox.consumer.due_retries_failed', ['error' => 'pg gone']);

        $loop = new ConsumerLoop(
            $this->createStub(StreamConsumerInterface::class),
            $this->createStub(OutboxProcessorInterface::class),
            blockMs: 5000,
            logger: $logger,
            dueRetries: static fn (): int => throw new \RuntimeException('pg gone'),
        );
        $loop->tick(); // Must not throw.
    }
}
