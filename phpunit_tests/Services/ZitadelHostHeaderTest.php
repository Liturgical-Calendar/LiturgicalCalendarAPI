<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\ZitadelHostHeader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ZitadelHostHeader::class)]
final class ZitadelHostHeaderTest extends TestCase
{
    public function testHostWithoutPort(): void
    {
        self::assertSame('zitadel.example.com', ZitadelHostHeader::deriveFromIssuer('https://zitadel.example.com'));
    }

    public function testHostWithPort(): void
    {
        self::assertSame('localhost:8080', ZitadelHostHeader::deriveFromIssuer('http://localhost:8080'));
    }

    public function testHostWithPortAndPathAndQuery(): void
    {
        // parse_url should still pick up just host:port even with extra cruft.
        self::assertSame(
            'zitadel.local:8443',
            ZitadelHostHeader::deriveFromIssuer('https://zitadel.local:8443/auth?foo=bar')
        );
    }

    public function testMalformedUrlThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid ZITADEL_ISSUER URL');
        ZitadelHostHeader::deriveFromIssuer('this-is-not-a-url-at-all');
    }

    public function testEmptyStringThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        ZitadelHostHeader::deriveFromIssuer('');
    }
}
