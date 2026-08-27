<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\ZitadelRoles;
use PHPUnit\Framework\TestCase;

final class ZitadelRolesTest extends TestCase
{
    public function testReadsRoleNamesFromTheProjectRolesClaim(): void
    {
        $payload = json_decode('{"' . ZitadelRoles::CLAIM . '":{"admin":{"org_id":"1"},"test_editor":{"org_id":"1"}}}');
        $this->assertInstanceOf(\stdClass::class, $payload);
        $this->assertSame(['admin', 'test_editor'], ZitadelRoles::fromPayload($payload));
    }

    public function testReturnsEmptyWhenTheClaimIsAbsent(): void
    {
        $payload = json_decode('{"sub":"u"}');
        $this->assertInstanceOf(\stdClass::class, $payload);
        $this->assertSame([], ZitadelRoles::fromPayload($payload));
    }

    public function testReturnsEmptyWhenTheClaimIsNotAnObject(): void
    {
        $payload = json_decode('{"' . ZitadelRoles::CLAIM . '":"admin"}');
        $this->assertInstanceOf(\stdClass::class, $payload);
        $this->assertSame([], ZitadelRoles::fromPayload($payload));
    }

    public function testReturnsEmptyWhenTheClaimIsAnEmptyObject(): void
    {
        $payload = json_decode('{"' . ZitadelRoles::CLAIM . '":{}}');
        $this->assertInstanceOf(\stdClass::class, $payload);
        $this->assertSame([], ZitadelRoles::fromPayload($payload));
    }
}
