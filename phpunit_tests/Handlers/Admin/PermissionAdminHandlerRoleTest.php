<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Admin;

use LiturgicalCalendar\Api\Handlers\Admin\PermissionAdminHandler;
use LiturgicalCalendar\Api\Services\ZitadelService;
use LiturgicalCalendar\Tests\Support\EnvIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Database-free coverage of the role assignment that follows a direct grant.
 * The handler-level (HTTP) cases live in PermissionAdminHandlerTest.
 */
#[CoversClass(PermissionAdminHandler::class)]
final class PermissionAdminHandlerRoleTest extends TestCase
{
    use EnvIsolationTrait;

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function assign(?ZitadelService $zitadel, string $objectType, string $relation = 'editor'): array
    {
        $handler = new PermissionAdminHandler(null, $zitadel);
        $method  = new \ReflectionMethod($handler, 'assignMatchingRole');
        /** @var array{0: string|null, 1: string|null} */
        return $method->invoke($handler, 'user:user-alice', $objectType, $relation);
    }

    public function testCalendarTypeAssignsCalendarEditor(): void
    {
        $z = $this->createMock(ZitadelService::class);
        $z->method('getUserRoles')->willReturn([]);
        $z->expects(self::once())->method('ensureRole')->with('user-alice', 'calendar_editor')->willReturn(true);
        self::assertSame(['calendar_editor', null], $this->assign($z, 'diocesan_calendar'));
    }

    public function testViewerGrantAssignsNoRoleAndMakesNoCall(): void
    {
        $z = $this->createMock(ZitadelService::class);
        $z->expects(self::never())->method('getUserRoles');
        $z->expects(self::never())->method('ensureRole');
        self::assertSame([null, null], $this->assign($z, 'diocesan_calendar', 'viewer'));
        self::assertSame([null, null], $this->assign($z, 'rite_calendar_test', 'viewer'));
    }

    public function testAdminRelationAssignsRole(): void
    {
        $z = $this->createMock(ZitadelService::class);
        $z->method('getUserRoles')->willReturn([]);
        $z->expects(self::once())->method('ensureRole')->with('user-alice', 'calendar_editor')->willReturn(true);
        self::assertSame(['calendar_editor', null], $this->assign($z, 'national_calendar', 'admin'));
    }

    public function testTestTypeAssignsTestEditor(): void
    {
        $z = $this->createMock(ZitadelService::class);
        $z->method('getUserRoles')->willReturn([]);
        $z->expects(self::once())->method('ensureRole')->with('user-alice', 'test_editor')->willReturn(true);
        self::assertSame(['test_editor', null], $this->assign($z, 'rite_calendar_test'));
    }

    public function testDeveloperCoversEverythingWithoutACall(): void
    {
        $z = $this->createMock(ZitadelService::class);
        $z->method('getUserRoles')->willReturn(['developer']);
        $z->expects(self::never())->method('ensureRole');
        self::assertSame(['developer', null], $this->assign($z, 'national_calendar_test'));
    }

    public function testCalendarEditorDoesNotCoverATestType(): void
    {
        $z = $this->createMock(ZitadelService::class);
        $z->method('getUserRoles')->willReturn(['calendar_editor']);
        $z->expects(self::once())->method('ensureRole')->with('user-alice', 'test_editor')->willReturn(true);
        self::assertSame(['test_editor', null], $this->assign($z, 'national_calendar_test'));
    }

    public function testGlobalAdminCoversEveryRoleWithoutACall(): void
    {
        $z = $this->createMock(ZitadelService::class);
        $z->method('getUserRoles')->willReturn(['admin']);
        $z->expects(self::never())->method('ensureRole');
        self::assertSame(['admin', null], $this->assign($z, 'rite_calendar_test'));
    }

    /**
     * The real getUserRoles() returns [] when its read fails (it never throws),
     * so a failed read looks like "no roles" and ensureRole() makes the decision.
     */
    public function testEmptyRoleReadFallsThroughToEnsureRole(): void
    {
        $z = $this->createMock(ZitadelService::class);
        $z->method('getUserRoles')->willReturn([]);
        $z->expects(self::once())->method('ensureRole')->with('user-alice', 'calendar_editor')->willReturn(false);
        [$assigned, $error] = $this->assign($z, 'national_calendar');
        self::assertNull($assigned);
        self::assertIsString($error);
    }

    public function testEnsureRoleFailureIsReportedNotThrown(): void
    {
        $z = $this->createStub(ZitadelService::class);
        $z->method('getUserRoles')->willReturn([]);
        $z->method('ensureRole')->willReturn(false);
        [$assigned, $error] = $this->assign($z, 'national_calendar');
        self::assertNull($assigned);
        self::assertIsString($error);
    }

    public function testEnsureRoleExceptionIsReportedNotThrown(): void
    {
        $z = $this->createStub(ZitadelService::class);
        $z->method('getUserRoles')->willReturn([]);
        $z->method('ensureRole')->willThrowException(new \RuntimeException('down'));
        self::assertSame([null, 'down'], $this->assign($z, 'national_calendar'));
    }

    public function testNothingAttemptedWhenZitadelIsNotConfigured(): void
    {
        $result = $this->withoutEnv(
            ['ZITADEL_ISSUER', 'ZITADEL_PROJECT_ID', 'ZITADEL_MACHINE_TOKEN', 'ZITADEL_INTERNAL_URL'],
            fn() => $this->assign(null, 'national_calendar')
        );
        self::assertSame([null, null], $result);
    }
}
