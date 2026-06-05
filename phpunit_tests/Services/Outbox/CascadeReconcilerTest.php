<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Outbox;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Repositories\OutboxRepositoryInterface;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\Outbox\CascadeReconciler;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Api\Services\Outbox\OutboxRow;
use LiturgicalCalendar\Api\Services\Outbox\OutboxStatus;
use LiturgicalCalendar\Api\Services\RoleCascadeService;
use LiturgicalCalendar\Api\Services\ZitadelService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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

    public function testEvaluateLogsWarningOnUnknownCascadeKindWhenLoggerInjected(): void
    {
        // Exercises the default-arm body of the match() dispatch inside evaluate().
        // Without a Logger the ?-> nullsafe short-circuits and the warning body
        // is never reached; with one we verify it fires with row_id + cascade_kind.
        $repo = $this->createStub(OutboxRepositoryInterface::class);
        $repo->method('getById')->willReturn($this->row(metadata: ['cascade_kind' => 'future_kind_v3']));

        $cascade = $this->createMock(RoleCascadeService::class);
        $cascade->expects(self::never())->method('maybeCascadeRoleRevoke');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'CascadeReconciler: unknown cascade_kind, ignoring row',
                self::callback(static fn(array $ctx): bool
                    => ( $ctx['row_id'] ?? null ) === 1 && ( $ctx['cascade_kind'] ?? null ) === 'future_kind_v3'),
            );

        ( new CascadeReconciler($repo, $cascade, $logger) )->evaluate(1);
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

    public function testAccessRequestRevokeNoOpsWhenAccessRequestIdIsMissing(): void
    {
        // Exercises the `: null` falsy branch on access_request_id extraction —
        // distinct from CascadeFieldsMissing which only omits cascade_user_id/role.
        $row = $this->row(metadata: [
            'cascade_kind'    => 'access_request_revoke',
            'cascade_user_id' => 'u1',
            'cascade_role'    => 'editor',
            // access_request_id deliberately absent
        ]);
        $repo = $this->createStub(OutboxRepositoryInterface::class);
        $repo->method('getById')->willReturn($row);

        $cascade = $this->createMock(RoleCascadeService::class);
        $cascade->expects(self::never())->method('maybeCascadeRoleRevoke');

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

    public function testPermissionRevokeFiresMaybeCascadePerCandidateRole(): void
    {
        $row  = $this->row(metadata: [
            'cascade_kind'            => 'permission_revoke',
            'cascade_user_id'         => 'u1',
            'cascade_role_candidates' => ['editor', 'viewer'],
        ]);
        $repo = $this->createMock(OutboxRepositoryInterface::class);
        $repo->method('getById')->willReturn($row);
        $repo->expects(self::never())->method('countSiblingNonTerminalDeletes');

        $cascade = $this->createMock(RoleCascadeService::class);
        $matcher = self::exactly(2);
        $cascade->expects($matcher)
            ->method('maybeCascadeRoleRevoke')
            ->willReturnCallback(function (string $userId, string $role) use ($matcher): bool {
                self::assertSame('u1', $userId);
                self::assertSame(['editor', 'viewer'][$matcher->numberOfInvocations() - 1], $role);
                return false;
            });

        ( new CascadeReconciler($repo, $cascade) )->evaluate(1);
    }

    public function testPermissionRevokeNoOpsWhenCandidatesEmpty(): void
    {
        $row  = $this->row(metadata: [
            'cascade_kind'            => 'permission_revoke',
            'cascade_user_id'         => 'u1',
            'cascade_role_candidates' => [],
        ]);
        $repo = $this->createStub(OutboxRepositoryInterface::class);
        $repo->method('getById')->willReturn($row);

        $cascade = $this->createMock(RoleCascadeService::class);
        $cascade->expects(self::never())->method('maybeCascadeRoleRevoke');

        ( new CascadeReconciler($repo, $cascade) )->evaluate(1);
    }

    public function testPermissionRevokeNoOpsWhenCascadeUserIdMissing(): void
    {
        $row  = $this->row(metadata: [
            'cascade_kind'            => 'permission_revoke',
            'cascade_role_candidates' => ['editor'],
        ]);
        $repo = $this->createStub(OutboxRepositoryInterface::class);
        $repo->method('getById')->willReturn($row);

        $cascade = $this->createMock(RoleCascadeService::class);
        $cascade->expects(self::never())->method('maybeCascadeRoleRevoke');

        ( new CascadeReconciler($repo, $cascade) )->evaluate(1);
    }

    public function testPermissionRevokeSkipsNonStringAndEmptyCandidates(): void
    {
        // Exercises the `continue` branch when a candidate fails the
        // is_string / non-empty guard inside the foreach.
        $row  = $this->row(metadata: [
            'cascade_kind'            => 'permission_revoke',
            'cascade_user_id'         => 'u1',
            // Mixed: int (non-string), empty string, valid role. Only 'editor' should reach maybeCascadeRoleRevoke.
            'cascade_role_candidates' => [123, '', 'editor'],
        ]);
        $repo = $this->createStub(OutboxRepositoryInterface::class);
        $repo->method('getById')->willReturn($row);

        $cascade = $this->createMock(RoleCascadeService::class);
        $cascade->expects(self::once())
            ->method('maybeCascadeRoleRevoke')
            ->with('u1', 'editor')
            ->willReturn(false);

        ( new CascadeReconciler($repo, $cascade) )->evaluate(1);
    }

    public function testPermissionRevokeContinuesAfterOneCandidateThrows(): void
    {
        $row  = $this->row(metadata: [
            'cascade_kind'            => 'permission_revoke',
            'cascade_user_id'         => 'u1',
            'cascade_role_candidates' => ['editor', 'viewer'],
        ]);
        $repo = $this->createStub(OutboxRepositoryInterface::class);
        $repo->method('getById')->willReturn($row);

        $cascade = $this->createMock(RoleCascadeService::class);
        $cascade->expects(self::exactly(2))
            ->method('maybeCascadeRoleRevoke')
            ->willReturnOnConsecutiveCalls(
                self::throwException(new \RuntimeException('boom')),
                true,
            );

        ( new CascadeReconciler($repo, $cascade) )->evaluate(1);
    }

    public function testFromEnvReturnsCorrectInstance(): void
    {
        if (!Connection::isConfigured()) {
            $this->markTestSkipped('DB env not set — CascadeReconciler::fromEnv requires DB.');
        }
        if (!OpenFgaClient::isConfigured()) {
            $this->markTestSkipped('OpenFGA env not set — CascadeReconciler::fromEnv requires OpenFGA.');
        }
        if (!ZitadelService::isConfigured()) {
            $this->markTestSkipped('Zitadel env not set — CascadeReconciler::fromEnv requires Zitadel.');
        }
        // Confirms fromEnv() returns a CascadeReconciler instance when its
        // dependencies (DB / Zitadel / FGA) are all reachable. Connection::getInstance()
        // is eager, so this test requires DB env to be set; CI provides it via .env.local.
        $reconciler = CascadeReconciler::fromEnv();
        self::assertInstanceOf(CascadeReconciler::class, $reconciler);
    }
}
