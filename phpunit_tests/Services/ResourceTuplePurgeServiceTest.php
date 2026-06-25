<?php

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Repositories\OutboxBatchInsertInterface;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\Outbox\OutboxDisposition;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Api\Services\Outbox\OutboxProcessorInterface;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeService;
use PDO;
use PHPUnit\Framework\TestCase;

class ResourceTuplePurgeServiceTest extends TestCase
{
    public function testPurgesOperationalTuplesAndRetainsAdmin(): void
    {
        $client = $this->createStub(OpenFgaClient::class);
        $client->method('readTuples')->willReturn([
            'tuples'                  => [
                ['user' => 'user:a', 'relation' => 'editor', 'object' => 'national_calendar:IT'],
                ['user' => 'user:b', 'relation' => 'viewer', 'object' => 'national_calendar:IT'],
                ['user' => 'user:c', 'relation' => 'admin',  'object' => 'national_calendar:IT'],
            ],
            'next_continuation_token' => '',
        ]);

        $repo = $this->createMock(OutboxBatchInsertInterface::class);
        $repo->expects($this->once())
            ->method('insertBatch')
            ->with($this->callback(function (array $rows): bool {
                // exactly the two operational tuples, never admin
                $relations = array_column($rows, 'fga_relation');
                sort($relations);
                return $relations === ['editor', 'viewer']
                    && array_unique(array_column($rows, 'operation'), SORT_REGULAR) === [OutboxOperation::DELETE_TUPLE];
            }))
            ->willReturn([10, 11]);

        $processor = $this->createMock(OutboxProcessorInterface::class);
        $processor->expects($this->exactly(2))->method('processSync')
            ->willReturn(OutboxDisposition::BENIGN_SUCCESS);

        $pdo = $this->createStub(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        $service = new ResourceTuplePurgeService($client, $repo, $processor, $pdo);
        $count   = $service->purgeForObject('national_calendar:IT');

        $this->assertSame(2, $count);
    }

    public function testNoOperationalTuplesIsNoOp(): void
    {
        $client = $this->createStub(OpenFgaClient::class);
        $client->method('readTuples')->willReturn([
            'tuples'                  => [['user' => 'user:c', 'relation' => 'admin', 'object' => 'national_calendar:IT']],
            'next_continuation_token' => '',
        ]);
        $repo = $this->createMock(OutboxBatchInsertInterface::class);
        $repo->expects($this->never())->method('insertBatch');
        $processor = $this->createStub(OutboxProcessorInterface::class);
        $pdo       = $this->createStub(PDO::class);

        $service = new ResourceTuplePurgeService($client, $repo, $processor, $pdo);
        $this->assertSame(0, $service->purgeForObject('national_calendar:IT'));
    }
}
