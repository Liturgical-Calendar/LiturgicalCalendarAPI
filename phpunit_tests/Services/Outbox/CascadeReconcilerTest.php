<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Outbox;

use LiturgicalCalendar\Api\Repositories\OutboxRepositoryInterface;
use LiturgicalCalendar\Api\Services\Outbox\CascadeReconciler;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Api\Services\Outbox\OutboxRow;
use LiturgicalCalendar\Api\Services\Outbox\OutboxStatus;
use LiturgicalCalendar\Api\Services\RoleCascadeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CascadeReconciler::class)]
final class CascadeReconcilerTest extends TestCase
{
    /**
     * @param array<string, mixed> $metadata
     */
    private function row(
        int $id = 1,
        OutboxOperation $operation = OutboxOperation::DELETE_TUPLE,
        OutboxStatus $status = OutboxStatus::SUCCEEDED,
        array $metadata = [],
    ): OutboxRow {
        return new OutboxRow(
            id: $id,
            operation: $operation,
            fgaUser: 'user:alice',
            fgaRelation: 'editor',
            fgaObject: 'national_calendar:IT',
            status: $status,
            attempts: 0,
            nextAttemptAt: new \DateTimeImmutable('now', new \DateTimeZone('Europe/Vatican')),
            lastError: null,
            lastErrorCode: null,
            createdAt: new \DateTimeImmutable('now', new \DateTimeZone('Europe/Vatican')),
            completedAt: null,
            metadata: $metadata,
        );
    }

    public function testEvaluateNoOpsWhenRowMissing(): void
    {
        $repo = $this->createStub(OutboxRepositoryInterface::class);
        $repo->method('getById')->willReturn(null);

        $cascade = $this->createMock(RoleCascadeService::class);
        $cascade->expects(self::never())->method('maybeCascadeRoleRevoke');

        ( new CascadeReconciler($repo, $cascade) )->evaluate(99);
    }

    public function testEvaluateNoOpsWhenStatusIsNotSucceeded(): void
    {
        $repo = $this->createStub(OutboxRepositoryInterface::class);
        $repo->method('getById')->willReturn($this->row(
            status: OutboxStatus::RETRYING,
            metadata: ['cascade_kind' => 'access_request_revoke', 'access_request_id' => 'r1', 'cascade_user_id' => 'u1', 'cascade_role' => 'editor'],
        ));

        $cascade = $this->createMock(RoleCascadeService::class);
        $cascade->expects(self::never())->method('maybeCascadeRoleRevoke');

        ( new CascadeReconciler($repo, $cascade) )->evaluate(1);
    }

    public function testEvaluateNoOpsWhenOperationIsNotDeleteTuple(): void
    {
        $repo = $this->createStub(OutboxRepositoryInterface::class);
        $repo->method('getById')->willReturn($this->row(
            operation: OutboxOperation::WRITE_TUPLE,
            metadata: ['cascade_kind' => 'access_request_revoke', 'access_request_id' => 'r1', 'cascade_user_id' => 'u1', 'cascade_role' => 'editor'],
        ));

        $cascade = $this->createMock(RoleCascadeService::class);
        $cascade->expects(self::never())->method('maybeCascadeRoleRevoke');

        ( new CascadeReconciler($repo, $cascade) )->evaluate(1);
    }

    public function testEvaluateNoOpsWhenMetadataHasNoCascadeKind(): void
    {
        $repo = $this->createStub(OutboxRepositoryInterface::class);
        $repo->method('getById')->willReturn($this->row(metadata: ['admin_user' => 'admin:x']));

        $cascade = $this->createMock(RoleCascadeService::class);
        $cascade->expects(self::never())->method('maybeCascadeRoleRevoke');

        ( new CascadeReconciler($repo, $cascade) )->evaluate(1);
    }

    public function testEvaluateNoOpsOnUnknownCascadeKind(): void
    {
        $repo = $this->createStub(OutboxRepositoryInterface::class);
        $repo->method('getById')->willReturn($this->row(metadata: ['cascade_kind' => 'future_kind_v3']));

        $cascade = $this->createMock(RoleCascadeService::class);
        $cascade->expects(self::never())->method('maybeCascadeRoleRevoke');

        ( new CascadeReconciler($repo, $cascade) )->evaluate(1);
    }

    public function testAccessRequestRevokeDefersWhenSiblingsStillPending(): void
    {
        $row  = $this->row(metadata: [
            'cascade_kind'      => 'access_request_revoke',
            'access_request_id' => 'r1',
            'cascade_user_id'   => 'u1',
            'cascade_role'      => 'editor',
        ]);
        $repo = $this->createMock(OutboxRepositoryInterface::class);
        $repo->method('getById')->willReturn($row);
        $repo->expects(self::once())
            ->method('countSiblingNonTerminalDeletes')
            ->with('r1')
            ->willReturn(2);

        $cascade = $this->createMock(RoleCascadeService::class);
        $cascade->expects(self::never())->method('maybeCascadeRoleRevoke');

        ( new CascadeReconciler($repo, $cascade) )->evaluate(1);
    }

    public function testAccessRequestRevokeFiresCascadeWhenAllSiblingsSettled(): void
    {
        $row  = $this->row(metadata: [
            'cascade_kind'      => 'access_request_revoke',
            'access_request_id' => 'r1',
            'cascade_user_id'   => 'u1',
            'cascade_role'      => 'editor',
        ]);
        $repo = $this->createStub(OutboxRepositoryInterface::class);
        $repo->method('getById')->willReturn($row);
        $repo->method('countSiblingNonTerminalDeletes')->willReturn(0);

        $cascade = $this->createMock(RoleCascadeService::class);
        $cascade->expects(self::once())
            ->method('maybeCascadeRoleRevoke')
            ->with('u1', 'editor')
            ->willReturn(true);

        ( new CascadeReconciler($repo, $cascade) )->evaluate(1);
    }

    public function testAccessRequestRevokeNoOpsWhenCascadeFieldsMissing(): void
    {
        // discriminator present, but cascade_user_id/cascade_role absent (defensive)
        $row  = $this->row(metadata: [
            'cascade_kind'      => 'access_request_revoke',
            'access_request_id' => 'r1',
        ]);
        $repo = $this->createStub(OutboxRepositoryInterface::class);
        $repo->method('getById')->willReturn($row);

        $cascade = $this->createMock(RoleCascadeService::class);
        $cascade->expects(self::never())->method('maybeCascadeRoleRevoke');

        ( new CascadeReconciler($repo, $cascade) )->evaluate(1);
    }

    public function testAccessRequestRevokeSwallowsCascadeException(): void
    {
        $row  = $this->row(metadata: [
            'cascade_kind'      => 'access_request_revoke',
            'access_request_id' => 'r1',
            'cascade_user_id'   => 'u1',
            'cascade_role'      => 'editor',
        ]);
        $repo = $this->createStub(OutboxRepositoryInterface::class);
        $repo->method('getById')->willReturn($row);
        $repo->method('countSiblingNonTerminalDeletes')->willReturn(0);

        $cascade = $this->createStub(RoleCascadeService::class);
        $cascade->method('maybeCascadeRoleRevoke')->willThrowException(new \RuntimeException('zitadel down'));

        // Must not propagate.
        ( new CascadeReconciler($repo, $cascade) )->evaluate(1);
        $this->expectNotToPerformAssertions();
    }
}
