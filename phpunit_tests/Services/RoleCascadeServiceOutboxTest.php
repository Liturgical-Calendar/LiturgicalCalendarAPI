<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\Exception\OpenFgaApiException;
use LiturgicalCalendar\Api\Services\Exception\TupleNotFoundException;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\Outbox\OutboxNotifier;
use LiturgicalCalendar\Api\Services\RoleCascadeService;
use LiturgicalCalendar\Api\Services\ZitadelService;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Integration tests for RoleCascadeService's outbox-enqueue behaviour on FGA
 * deleteTuple failures. These tests require a live Postgres connection and are
 * automatically skipped when the DB is unavailable (CI supplies it; local devs
 * can do the same — see RepositoryTestCase for the skip logic).
 */
#[CoversClass(RoleCascadeService::class)]
final class RoleCascadeServiceOutboxTest extends RepositoryTestCase
{
    private function service(
        OpenFgaClient $fga,
        AccessRequestRepository $repo,
        OutboxRepository $outboxRepo,
    ): RoleCascadeService {
        // OutboxNotifier with null Redis — no stream publish, but PG insert still happens.
        $notifier = new OutboxNotifier(null, 'litcal:reconcile-stream');

        return new RoleCascadeService(
            $fga,
            $this->createStub(ZitadelService::class),
            $repo,
            null,
            $outboxRepo,
            $notifier,
        );
    }

    /**
     * When deleteTuple throws a transient 503 (OutboxClassifier → RETRY), the
     * service must insert a pending openfga_outbox row and return an empty
     * deleted list (the failing tuple is not counted as deleted).
     */
    public function testCascadeRevokeEnqueuesOutboxRowOnFgaTransientFailure(): void
    {
        $fga = $this->createStub(OpenFgaClient::class);
        // 'test_editor' role: first relation (admin) returns one object, rest empty.
        $fga->method('listObjects')
            ->willReturnCallback(static function (string $user, string $relation): array {
                return $relation === 'admin' ? ['t1'] : [];
            });
        $fga->method('deleteTuple')
            ->willThrowException(new OpenFgaApiException('Service Unavailable', 503));

        $outboxRepo = new OutboxRepository(self::$pdo);
        $repo       = $this->createStub(AccessRequestRepository::class);

        $svc     = $this->service($fga, $repo, $outboxRepo);
        $deleted = $svc->cascadeTupleRevokeForRole('u1', 'test_editor');

        // The failing tuple is not counted as deleted.
        self::assertSame([], $deleted);

        // One pending outbox row must exist for the failed delete.
        $stmt = self::$pdo->query(
            "SELECT operation, fga_user, fga_relation, fga_object, status,
                    metadata->>'role_cascade_user' AS cascade_user,
                    metadata->>'role_cascade_role' AS cascade_role
             FROM openfga_outbox
             WHERE fga_user = 'user:u1'
               AND fga_relation = 'admin'
               AND fga_object = 'test_definition:t1'"
        );
        $rows = $stmt !== false ? $stmt->fetchAll() : [];

        self::assertCount(1, $rows, 'Expected exactly one outbox row for the failed deleteTuple');
        self::assertSame('delete_tuple', $rows[0]['operation']);
        self::assertSame('pending', $rows[0]['status']);
        self::assertSame('u1', $rows[0]['cascade_user']);
        self::assertSame('test_editor', $rows[0]['cascade_role']);
    }

    /**
     * When deleteTuple throws a TupleNotFoundException (BENIGN_SUCCESS), no outbox
     * row must be inserted — the tuple was already gone, cascade is consistent.
     */
    public function testCascadeRevokeSkipsOutboxEnqueueOnBenignFgaFailure(): void
    {
        $fga = $this->createStub(OpenFgaClient::class);
        $fga->method('listObjects')
            ->willReturnCallback(static function (string $user, string $relation): array {
                return $relation === 'admin' ? ['t1'] : [];
            });
        $fga->method('deleteTuple')
            ->willThrowException(new TupleNotFoundException('not found', 404));

        $outboxRepo = new OutboxRepository(self::$pdo);
        $repo       = $this->createStub(AccessRequestRepository::class);

        $svc     = $this->service($fga, $repo, $outboxRepo);
        $deleted = $svc->cascadeTupleRevokeForRole('u1', 'test_editor');

        // Benign failure: tuple already gone, so nothing to record.
        self::assertSame([], $deleted);

        // No outbox row must have been inserted.
        $stmt  = self::$pdo->query('SELECT COUNT(*) FROM openfga_outbox');
        $count = $stmt !== false ? (int) $stmt->fetchColumn() : -1;
        self::assertSame(0, $count, 'BENIGN_SUCCESS must not enqueue any outbox row');
    }
}
