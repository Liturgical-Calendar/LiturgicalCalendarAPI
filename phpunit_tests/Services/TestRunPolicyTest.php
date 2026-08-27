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

    public function testTargetIsNullWhenCalendarIsNotAnObject(): void
    {
        $this->assertNull(TestTarget::fromMessage(json_decode('{"action":"validateCalendar","calendar":"roman"}')));
    }

    public function testTargetIsNullWhenCalendarIsAnObjectNamingNothingUseful(): void
    {
        $this->assertNull(TestTarget::fromMessage(json_decode('{"action":"validateCalendar","calendar":{"year":2024}}')));
    }

    /**
     * The id property is named for the kind it belongs to, so each spelling has to be read.
     */
    public function testTargetReadsTheNationId(): void
    {
        $target = TestTarget::fromMessage(json_decode('{"calendar":{"kind":"nation","nation":"IT"}}'));
        $this->assertNotNull($target);
        $this->assertSame('nation', $target->kind);
        $this->assertSame('IT', $target->calendarId);
        $this->assertNull($target->rite);
    }

    public function testTargetReadsTheDioceseId(): void
    {
        $target = TestTarget::fromMessage(json_decode('{"calendar":{"kind":"diocese","diocese":"milano_it","rite":"ambrosian"}}'));
        $this->assertNotNull($target);
        $this->assertSame('milano_it', $target->calendarId);
        $this->assertSame('ambrosian', $target->rite);
    }

    public function testTargetIgnoresNonStringProperties(): void
    {
        $target = TestTarget::fromMessage(json_decode('{"calendar":{"kind":123,"rite":"roman"}}'));
        $this->assertNotNull($target);
        $this->assertNull($target->kind, 'a non-string kind is absent, not coerced');
        $this->assertSame('roman', $target->rite);
    }

    public function testAnonymousCallerHoldsNoRoleAtAll(): void
    {
        $this->assertFalse(WsCaller::anonymous()->hasAnyRole('admin', 'test_editor'));
        $this->assertTrue(WsCaller::authenticated('u', ['test_editor'])->hasAnyRole('admin', 'test_editor'));
        $this->assertFalse(WsCaller::authenticated('u', ['developer'])->hasAnyRole('admin', 'test_editor'));
    }

    public function testRolesAreDeduplicatedAndAnonymousHasNone(): void
    {
        $caller = WsCaller::authenticated('u', ['admin', 'admin']);
        $this->assertSame(['admin'], $caller->roles);
        $this->assertSame([], WsCaller::anonymous()->roles);
        $this->assertNull(WsCaller::anonymous()->sub);
    }
}
