<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use LiturgicalCalendar\Api\Repositories\AuditLogRepository;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AuditLogRepository::class)]
final class AuditLogRepositoryTest extends RepositoryTestCase
{
    private AuditLogRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new AuditLogRepository(self::$pdo);
    }

    public function testLogPersistsAllColumnsAndReturnsId(): void
    {
        $id = $this->repo->log(
            'user-1',
            'create_application',
            'application',
            'app-abc',
            ['name' => 'X'],
            '203.0.113.7',
            'curl/7.0',
            true
        );

        // log() returns the UUID of the inserted row.
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id);

        $rows = $this->repo->query();
        self::assertCount(1, $rows);
        self::assertSame($id, $rows[0]['id']);
        self::assertSame('user-1', $rows[0]['zitadel_user_id']);
        self::assertSame('create_application', $rows[0]['action']);
        self::assertSame('application', $rows[0]['resource_type']);
        self::assertSame('app-abc', $rows[0]['resource_id']);
        self::assertSame('203.0.113.7', $rows[0]['ip_address']);
        self::assertSame('curl/7.0', $rows[0]['user_agent']);
        self::assertTrue((bool) $rows[0]['success']);
        // details JSONB is decoded back to an array on read.
        self::assertSame(['name' => 'X'], $rows[0]['details']);
    }

    public function testLogPermitsNullUserForAnonymousActions(): void
    {
        $this->repo->log(null, 'rate_limit_breach', 'session');

        $rows = $this->repo->query();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['zitadel_user_id']);
    }

    public function testQueryFiltersByMultipleColumns(): void
    {
        $this->repo->log('user-1', 'login', 'session', null, null, '10.0.0.1');
        $this->repo->log('user-1', 'login', 'session', null, null, '10.0.0.2', null, false);
        $this->repo->log('user-2', 'login', 'session');
        $this->repo->log('user-1', 'logout', 'session');

        self::assertCount(2, $this->repo->query(['user_id' => 'user-1', 'action' => 'login']));
        self::assertCount(1, $this->repo->query(['action' => 'login', 'success' => false]));
        self::assertCount(1, $this->repo->query(['ip_address' => '10.0.0.1']));
    }

    public function testQueryRespectsLimitAndOffset(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->repo->log('user-x', 'login', 'session');
            usleep(1500);
        }

        $page1 = $this->repo->query([], 2, 0);
        $page2 = $this->repo->query([], 2, 2);

        self::assertCount(2, $page1);
        self::assertCount(2, $page2);
        self::assertNotEquals($page1[0]['id'], $page2[0]['id']);
    }

    public function testQueryFiltersByDateRange(): void
    {
        $this->repo->log('user-x', 'login', 'session');

        // Far past → far future should include everything.
        $all = $this->repo->query([
            'from_date' => '1900-01-01 00:00:00',
            'to_date'   => '2999-12-31 23:59:59',
        ]);
        self::assertCount(1, $all);

        // Future-only range should exclude everything.
        $none = $this->repo->query(['from_date' => '2999-01-01 00:00:00']);
        self::assertCount(0, $none);
    }

    public function testGetByUserGetByResourceAndLoginAttempts(): void
    {
        $this->repo->log('user-a', 'login', 'session');
        $this->repo->log('user-b', 'login', 'session');
        $this->repo->log('user-a', 'create_application', 'application', 'app-1');

        self::assertCount(2, $this->repo->getByUser('user-a'));
        self::assertCount(1, $this->repo->getByResource('application', 'app-1'));
        self::assertCount(1, $this->repo->getLoginAttempts('user-a'));
    }

    public function testGetFailedActionsRequiresSuccessFalse(): void
    {
        $this->repo->log('user-1', 'login', 'session', null, null, null, null, true);
        $this->repo->log('user-1', 'login', 'session', null, null, null, null, false);
        $this->repo->log('user-1', 'login', 'session', null, null, null, null, false);

        $failed = $this->repo->getFailedActions();

        self::assertCount(2, $failed);
        foreach ($failed as $row) {
            self::assertFalse((bool) $row['success']);
        }
    }

    public function testCountAppliesSameFilterAsQuery(): void
    {
        $this->repo->log('user-1', 'login', 'session');
        $this->repo->log('user-1', 'login', 'session');
        $this->repo->log('user-2', 'login', 'session');

        self::assertSame(3, $this->repo->count());
        self::assertSame(2, $this->repo->count(['user_id' => 'user-1']));
    }

    public function testPurgeOldDeletesRowsBeyondRetention(): void
    {
        $this->repo->log('user-1', 'login', 'session');
        // Force one row's created_at into the distant past, beyond the 365-day window.
        self::$pdo->exec(
            "UPDATE audit_log SET created_at = CURRENT_TIMESTAMP - INTERVAL '400 days'"
        );

        $deleted = $this->repo->purgeOld(365);

        self::assertSame(1, $deleted);
        self::assertSame(0, $this->repo->count());
    }

    public function testHelperLoginLogoutAndFailedLoginShortcuts(): void
    {
        $this->repo->logLogin('user-1', '198.51.100.1', 'Mozilla');
        $this->repo->logLogout('user-1', '198.51.100.1');
        $this->repo->logFailedLogin('user-1', '198.51.100.2', 'curl', ['reason' => 'bad_pw']);

        $rows = $this->repo->query([], 100);

        self::assertCount(3, $rows);
        $byAction = [];
        foreach ($rows as $row) {
            $byAction[$row['action']][] = $row;
        }
        self::assertCount(2, $byAction['login']);
        self::assertCount(1, $byAction['logout']);

        $failedLogin = array_values(array_filter(
            $byAction['login'],
            static fn (array $r): bool => !(bool) $r['success']
        ));
        self::assertCount(1, $failedLogin);
        self::assertSame(['reason' => 'bad_pw'], $failedLogin[0]['details']);
    }
}
