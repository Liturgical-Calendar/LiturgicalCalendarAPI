<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Models\Auth\TestTarget;
use LiturgicalCalendar\Api\Models\Auth\WsCaller;
use LiturgicalCalendar\Api\Services\TestRunPolicy;
use PHPUnit\Framework\TestCase;

final class TestRunPolicyTest extends TestCase
{
    public function testAnonymousCallerMayNotRun(): void
    {
        $this->assertFalse(( new TestRunPolicy() )->mayRun(WsCaller::anonymous()));
    }

    public function testAuthenticatedCallerWithoutQualifyingRoleMayNotRun(): void
    {
        $caller = WsCaller::authenticated('user-1', ['developer', 'calendar_editor']);
        $this->assertFalse(( new TestRunPolicy() )->mayRun($caller));
    }

    public function testTestEditorMayRun(): void
    {
        $caller = WsCaller::authenticated('user-1', ['test_editor']);
        $this->assertTrue(( new TestRunPolicy() )->mayRun($caller));
    }

    public function testAdminMayRun(): void
    {
        $caller = WsCaller::authenticated('user-1', ['admin']);
        $this->assertTrue(( new TestRunPolicy() )->mayRun($caller));
    }

    public function testTargetIsAcceptedAndIgnoredByTheCoarsePolicy(): void
    {
        $policy = new TestRunPolicy();
        $target = new TestTarget('rite', 'roman', null);
        $this->assertTrue($policy->mayRun(WsCaller::authenticated('u', ['admin']), $target));
        $this->assertFalse($policy->mayRun(WsCaller::anonymous(), $target));
    }

    public function testTargetIsReadFromAValidateCalendarMessage(): void
    {
        $message = json_decode('{"action":"validateCalendar","calendar":{"kind":"rite","rite":"roman"}}');
        $target  = TestTarget::fromMessage($message);
        $this->assertNotNull($target);
        $this->assertSame('rite', $target->kind);
        $this->assertSame('roman', $target->rite);
    }

    public function testTargetIsNullWhenTheMessageNamesNone(): void
    {
        $this->assertNull(TestTarget::fromMessage(json_decode('{"action":"runTest"}')));
        $this->assertNull(TestTarget::fromMessage('not an object'));
    }

    public function testRolesAreDeduplicatedAndAnonymousHasNone(): void
    {
        $caller = WsCaller::authenticated('u', ['admin', 'admin']);
        $this->assertSame(['admin'], $caller->roles);
        $this->assertSame([], WsCaller::anonymous()->roles);
        $this->assertNull(WsCaller::anonymous()->sub);
    }
}
