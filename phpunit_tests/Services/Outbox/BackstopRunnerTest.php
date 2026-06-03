<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Outbox;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\Outbox\BackstopRunner;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Api\Services\Outbox\OutboxDisposition;
use LiturgicalCalendar\Api\Services\Outbox\OutboxProcessor;
use LiturgicalCalendar\Api\Services\Outbox\OutboxProcessorInterface;
use LiturgicalCalendar\Api\Services\Outbox\OutboxStatus;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(BackstopRunner::class)]
final class BackstopRunnerTest extends RepositoryTestCase
{
    public function testRunOnceProcessesEligibleRowsAndIgnoresGraceWindow(): void
    {
        self::assertNotNull(self::$pdo);
        $repo      = new OutboxRepository(self::$pdo);
        $psr17     = new Psr17Factory();
        $mock      = new MockHandler([
            new Response(200, [], ''),
            new Response(200, [], ''),
        ]);
        $client    = new OpenFgaClient(
            'http://localhost:8083',
            'store-123',
            'model-456',
            new Client(['handler' => HandlerStack::create($mock)]),
            $psr17,
            $psr17,
        );
        $processor = new OutboxProcessor($repo, $client);

        // Two ancient pending rows (eligible for backstop after 0s grace).
        $ids = $repo->insertBatch([
            [
                'operation'       => OutboxOperation::WRITE_TUPLE,
                'fga_user'        => 'user:a',
                'fga_relation'    => 'editor',
                'fga_object'      => 'national_calendar:IT',
                'idempotency_key' => 'k1-' . bin2hex(random_bytes(4)),
                'metadata'        => [],
            ],
            [
                'operation'       => OutboxOperation::WRITE_TUPLE,
                'fga_user'        => 'user:b',
                'fga_relation'    => 'editor',
                'fga_object'      => 'national_calendar:US',
                'idempotency_key' => 'k2-' . bin2hex(random_bytes(4)),
                'metadata'        => [],
            ],
        ]);

        $runner    = new BackstopRunner($repo, $processor, self::$pdo, graceSeconds: 0);
        $processed = $runner->runOnce(limit: 100);

        self::assertSame(2, $processed);
        self::assertSame(OutboxStatus::SUCCEEDED, $repo->getById($ids[0])?->status);
        self::assertSame(OutboxStatus::SUCCEEDED, $repo->getById($ids[1])?->status);
    }

    public function testRunOnceRespectsGraceWindow(): void
    {
        self::assertNotNull(self::$pdo);
        $repo      = new OutboxRepository(self::$pdo);
        $psr17     = new Psr17Factory();
        $mock      = new MockHandler([]);
        $client    = new OpenFgaClient(
            'http://localhost:8083',
            'store-123',
            'model-456',
            new Client(['handler' => HandlerStack::create($mock)]),
            $psr17,
            $psr17,
        );
        $processor = new OutboxProcessor($repo, $client);

        $repo->insertBatch([
            [
                'operation'       => OutboxOperation::WRITE_TUPLE,
                'fga_user'        => 'user:c',
                'fga_relation'    => 'editor',
                'fga_object'      => 'national_calendar:FR',
                'idempotency_key' => 'k3-' . bin2hex(random_bytes(4)),
                'metadata'        => [],
            ]
        ]);

        $runner    = new BackstopRunner($repo, $processor, self::$pdo, graceSeconds: 60);
        $processed = $runner->runOnce(limit: 100);

        self::assertSame(0, $processed, 'row is too fresh — under the 60s grace window');
    }

    public function testRunOnceRollsBackAndRethrowsWhenProcessorThrows(): void
    {
        self::assertNotNull(self::$pdo);
        $repo = new OutboxRepository(self::$pdo);

        $repo->insertBatch([
            [
                'operation'       => OutboxOperation::WRITE_TUPLE,
                'fga_user'        => 'user:x',
                'fga_relation'    => 'editor',
                'fga_object'      => 'national_calendar:ES',
                'idempotency_key' => 'rollback-' . bin2hex(random_bytes(4)),
                'metadata'        => [],
            ],
        ]);

        // Processor stub that always throws — exercises the try/catch in
        // runOnce(): the surrounding tx must be rolled back, and the
        // exception must propagate so the cron job exits non-zero.
        // createStub (not createMock) because we don't assert call counts;
        // PHPUnit 12 emits a notice when createMock has no expectations.
        $processor = $this->createStub(OutboxProcessorInterface::class);
        $processor->method('processOne')->willThrowException(new \RuntimeException('processor blew up'));

        $runner = new BackstopRunner($repo, $processor, self::$pdo, graceSeconds: 0);

        try {
            $runner->runOnce(limit: 100);
            self::fail('Expected the runner to rethrow the processor exception');
        } catch (\RuntimeException $e) {
            self::assertSame('processor blew up', $e->getMessage());
        }

        // After the failed runOnce, the connection must NOT be left in a
        // transaction — that would cascade into TRUNCATE failures in the
        // next test's setUp.
        self::assertFalse(self::$pdo->inTransaction(), 'BackstopRunner must roll back its tx before re-raising');
    }
}
