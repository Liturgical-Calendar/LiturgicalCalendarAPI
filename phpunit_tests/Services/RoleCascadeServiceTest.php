<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\RoleCascadeService;
use LiturgicalCalendar\Api\Services\ZitadelService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoleCascadeService::class)]
final class RoleCascadeServiceTest extends TestCase
{
    private function service(
        OpenFgaClient $fga,
        ZitadelService $zitadel,
        AccessRequestRepository $repo
    ): RoleCascadeService {
        return new RoleCascadeService($fga, $zitadel, $repo, null);
    }

    public function testUserHasAnyTupleInRoleScopeFalseForUnknownRole(): void
    {
        $svc = $this->service(
            $this->createStub(OpenFgaClient::class),
            $this->createStub(ZitadelService::class),
            $this->createStub(AccessRequestRepository::class)
        );

        // 'galactic_overlord' isn't in ROLE_OBJECT_TYPES; method short-circuits.
        self::assertFalse($svc->userHasAnyTupleInRoleScope('u1', 'galactic_overlord'));
    }

    public function testUserHasAnyTupleInRoleScopeTrueOnFirstHit(): void
    {
        $fga = $this->createMock(OpenFgaClient::class);
        // 'test_editor' role: types=[test_definition], relations=[admin,viewer,editor,deleter].
        // First listObjects call returns non-empty → method returns true.
        $fga->expects(self::once())
            ->method('listObjects')
            ->with('user:u1', 'admin', 'test_definition')
            ->willReturn(['some-id']);

        $svc = $this->service(
            $fga,
            $this->createStub(ZitadelService::class),
            $this->createStub(AccessRequestRepository::class)
        );

        self::assertTrue($svc->userHasAnyTupleInRoleScope('u1', 'test_editor'));
    }

    public function testUserHasAnyTupleInRoleScopeFalseWhenAllEmpty(): void
    {
        $fga = $this->createStub(OpenFgaClient::class);
        $fga->method('listObjects')->willReturn([]);

        $svc = $this->service(
            $fga,
            $this->createStub(ZitadelService::class),
            $this->createStub(AccessRequestRepository::class)
        );

        self::assertFalse($svc->userHasAnyTupleInRoleScope('u1', 'test_editor'));
    }

    public function testMaybeCascadeRoleRevokeReturnsFalseWhenTuplesRemain(): void
    {
        $fga = $this->createStub(OpenFgaClient::class);
        $fga->method('listObjects')->willReturn(['still-here']);

        $zitadel = $this->createMock(ZitadelService::class);
        $zitadel->expects(self::never())->method('revokeUserRole');

        $repo = $this->createMock(AccessRequestRepository::class);
        $repo->expects(self::never())->method('cascadeRevokeByRole');

        $svc = $this->service($fga, $zitadel, $repo);
        self::assertFalse($svc->maybeCascadeRoleRevoke('u1', 'test_editor'));
    }

    public function testMaybeCascadeRoleRevokeFlipsWhenTuplesAreEmpty(): void
    {
        $fga = $this->createStub(OpenFgaClient::class);
        $fga->method('listObjects')->willReturn([]);

        $zitadel = $this->createMock(ZitadelService::class);
        $zitadel->expects(self::once())
            ->method('revokeUserRole')
            ->with('u1', 'developer')
            ->willReturn(true);

        $repo = $this->createMock(AccessRequestRepository::class);
        $repo->expects(self::once())
            ->method('cascadeRevokeByRole')
            ->with('u1', 'developer')
            ->willReturn(1);

        $svc = $this->service($fga, $zitadel, $repo);
        self::assertTrue($svc->maybeCascadeRoleRevoke('u1', 'developer'));
    }

    public function testMaybeCascadeRoleRevokeStillUpdatesDbWhenZitadelThrows(): void
    {
        // Zitadel failure must not block the DB cascade — that's the audit-state
        // consistency guarantee this service exists to enforce.
        $fga = $this->createStub(OpenFgaClient::class);
        $fga->method('listObjects')->willReturn([]);

        $zitadel = $this->createStub(ZitadelService::class);
        $zitadel->method('revokeUserRole')
            ->willThrowException(new \RuntimeException('Zitadel is down'));

        $repo = $this->createMock(AccessRequestRepository::class);
        $repo->expects(self::once())
            ->method('cascadeRevokeByRole')
            ->with('u1', 'developer')
            ->willReturn(1);

        $svc = $this->service($fga, $zitadel, $repo);
        self::assertTrue($svc->maybeCascadeRoleRevoke('u1', 'developer'));
    }

    public function testCascadeTupleRevokeForRoleReturnsEmptyForUnknownRole(): void
    {
        $svc = $this->service(
            $this->createStub(OpenFgaClient::class),
            $this->createStub(ZitadelService::class),
            $this->createStub(AccessRequestRepository::class)
        );

        self::assertSame([], $svc->cascadeTupleRevokeForRole('u1', 'galactic_overlord'));
    }

    public function testCascadeTupleRevokeForRoleDeletesTuplesAndCascadesDb(): void
    {
        $fga = $this->createMock(OpenFgaClient::class);
        // 'test_editor' role only has 'test_definition' as a valid type.
        // First relation (admin) returns one object id; the rest return empty.
        $listCalls = 0;
        $fga->method('listObjects')
            ->willReturnCallback(function (string $user, string $relation) use (&$listCalls): array {
                $listCalls++;
                return $relation === 'admin' ? ['t1'] : [];
            });
        $fga->expects(self::once())
            ->method('deleteTuple')
            ->with('user:u1', 'admin', 'test_definition:t1');

        $repo = $this->createMock(AccessRequestRepository::class);
        $repo->expects(self::once())
            ->method('cascadeRevokeByRole')
            ->with('u1', 'test_editor');

        $svc     = $this->service($fga, $this->createStub(ZitadelService::class), $repo);
        $deleted = $svc->cascadeTupleRevokeForRole('u1', 'test_editor');

        self::assertCount(1, $deleted);
        self::assertSame('user:u1', $deleted[0]['user']);
        self::assertSame('admin', $deleted[0]['relation']);
        self::assertSame('test_definition:t1', $deleted[0]['object']);
        self::assertGreaterThanOrEqual(4, $listCalls, 'listObjects should be probed for every (type, relation) pair');
    }

    public function testCascadeTupleRevokeForRoleSwallowsDeleteFailures(): void
    {
        $fga = $this->createStub(OpenFgaClient::class);
        $fga->method('listObjects')->willReturn(['t1']);
        $fga->method('deleteTuple')
            ->willThrowException(new \RuntimeException('FGA is down'));

        $repo = $this->createMock(AccessRequestRepository::class);
        $repo->expects(self::once())->method('cascadeRevokeByRole');

        $svc     = $this->service($fga, $this->createStub(ZitadelService::class), $repo);
        $deleted = $svc->cascadeTupleRevokeForRole('u1', 'test_editor');

        // No tuples recorded as deleted because every deleteTuple() threw.
        self::assertSame([], $deleted);
    }
}
