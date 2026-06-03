<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Outbox;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\Outbox\OutboxDisposition;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Api\Services\Outbox\OutboxProcessor;
use LiturgicalCalendar\Api\Services\Outbox\OutboxStatus;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(OutboxProcessor::class)]
final class OutboxProcessorTest extends RepositoryTestCase
{
    private OutboxRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        // parent::setUp() calls markTestSkipped() when $pdo is null,
        // so reaching this line guarantees a live connection.
        self::assertNotNull(self::$pdo, 'PDO connection must be available after parent::setUp()');
        $this->repo = new OutboxRepository(self::$pdo);
    }

    private function makeClient(MockHandler $mock): OpenFgaClient
    {
        $psr17 = new Psr17Factory();
        return new OpenFgaClient(
            'http://localhost:8083',
            'store-123',
            'model-456',
            new Client(['handler' => HandlerStack::create($mock)]),
            $psr17,
            $psr17,
        );
    }

    /**
     * @return list<int>
     */
    private function seedOneWrite(): array
    {
        return $this->repo->insertBatch([
            [
                'operation'       => OutboxOperation::WRITE_TUPLE,
                'fga_user'        => 'user:alice',
                'fga_relation'    => 'editor',
                'fga_object'      => 'national_calendar:IT',
                'idempotency_key' => 'k-' . bin2hex(random_bytes(4)),
                'metadata'        => ['access_request_id' => 'r1'],
            ],
        ]);
    }

    public function testProcessOneSuccessMarksSucceeded(): void
    {
        [$id] = $this->seedOneWrite();
        $mock = new MockHandler([new Response(200, [], '')]);
        $proc = new OutboxProcessor($this->repo, $this->makeClient($mock));

        $disp = $proc->processOne($id);

        self::assertSame(OutboxDisposition::BENIGN_SUCCESS, $disp);
        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(OutboxStatus::SUCCEEDED, $row->status);
    }

    public function testProcessOneTransientSchedulesRetryWithCorrectBackoff(): void
    {
        [$id] = $this->seedOneWrite();
        $mock = new MockHandler([
            new Response(503, [], (string) json_encode(['code' => 'temporarily_unavailable', 'message' => 'try again'])),
        ]);
        $proc = new OutboxProcessor($this->repo, $this->makeClient($mock));

        $proc->processOne($id);

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(OutboxStatus::RETRYING, $row->status);
        self::assertSame(1, $row->attempts);
        $delta = $row->nextAttemptAt->getTimestamp() - ( new \DateTimeImmutable() )->getTimestamp();
        self::assertGreaterThanOrEqual(0, $delta);
        self::assertLessThanOrEqual(2, $delta, 'attempts=1 should schedule ~1s ahead');
    }

    public function testProcessOneValidationErrorMarksTerminalOnFirstAttempt(): void
    {
        [$id] = $this->seedOneWrite();
        $mock = new MockHandler([
            new Response(400, [], (string) json_encode(['code' => 'validation_error', 'message' => 'bad type'])),
        ]);
        $proc = new OutboxProcessor($this->repo, $this->makeClient($mock));

        $proc->processOne($id);

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(OutboxStatus::FAILED_TERMINAL, $row->status);
        self::assertSame('validation_error', $row->lastErrorCode);
    }

    public function test10thAttemptOnTransientMarksTerminal(): void
    {
        [$id] = $this->seedOneWrite();
        // Pre-set the row to attempts=9 and retrying so this call is the 10th.
        $this->repo->markRetryable(
            $id,
            attempts: 9,
            nextAttemptAt: new \DateTimeImmutable('-1 second'),
            lastError: 'prior transient',
            lastErrorCode: null,
        );
        $mock = new MockHandler([new Response(503, [], '')]);
        $proc = new OutboxProcessor($this->repo, $this->makeClient($mock));

        $proc->processOne($id);

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(
            OutboxStatus::FAILED_TERMINAL,
            $row->status,
            '10th attempt on transient must transition to failed_terminal',
        );
    }

    public function testCustomMaxAttemptsConfigurable(): void
    {
        [$id] = $this->seedOneWrite();
        // Pre-set the row to attempts=2 so this call is the 3rd attempt.
        $this->repo->markRetryable(
            $id,
            attempts: 2,
            nextAttemptAt: new \DateTimeImmutable('-1 second'),
            lastError: 'prior transient',
            lastErrorCode: null,
        );
        $mock = new MockHandler([new Response(503, [], '')]);
        // Processor with maxAttempts=3: the 3rd attempt (attempts=2 → newAttempts=3 >= 3) must go terminal.
        $proc = new OutboxProcessor($this->repo, $this->makeClient($mock), maxAttempts: 3);

        $proc->processOne($id);

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(
            OutboxStatus::FAILED_TERMINAL,
            $row->status,
            'custom maxAttempts=3 must cause failed_terminal on the 3rd attempt',
        );
    }

    public function testProcessOneBenignAlreadyExistsCountsAsSuccess(): void
    {
        [$id] = $this->seedOneWrite();
        $mock = new MockHandler([
            new Response(400, [], (string) json_encode([
                'code'    => 'cannot_allow_duplicate_tuple',
                'message' => 'tuple already exists',
            ])),
        ]);
        $proc = new OutboxProcessor($this->repo, $this->makeClient($mock));

        $proc->processOne($id);

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(OutboxStatus::SUCCEEDED, $row->status);
    }

    public function testProcessOneOnTerminalRowIsNoOp(): void
    {
        [$id] = $this->seedOneWrite();
        $this->repo->markSucceeded($id);
        $mock = new MockHandler([]); // No OpenFGA call should be made.
        $proc = new OutboxProcessor($this->repo, $this->makeClient($mock));

        // Should not throw despite MockHandler being empty.
        $proc->processOne($id);

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(OutboxStatus::SUCCEEDED, $row->status);
    }
}
