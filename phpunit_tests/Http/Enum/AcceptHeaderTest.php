<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http\Enum;

use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AcceptHeader::class)]
final class AcceptHeaderTest extends TestCase
{
    public function testCommonMimeTypeValues(): void
    {
        self::assertSame('application/json', AcceptHeader::JSON->value);
        self::assertSame('application/yaml', AcceptHeader::YAML->value);
        self::assertSame('application/xml', AcceptHeader::XML->value);
        self::assertSame('text/calendar', AcceptHeader::ICS->value);
        self::assertSame('application/octet-stream', AcceptHeader::ATTACHMENT->value);
    }

    public function testToReturnTypeParamRoundTrip(): void
    {
        // Every AcceptHeader case must map to a ReturnTypeParam whose
        // toResponseContentType maps back to the original AcceptHeader.
        foreach (AcceptHeader::cases() as $case) {
            $rt = $case->toReturnTypeParam();
            self::assertInstanceOf(ReturnTypeParam::class, $rt);
            self::assertSame($case, $rt->toResponseContentType());
        }
    }

    public function testIsValid(): void
    {
        self::assertTrue(AcceptHeader::isValid('application/json'));
        self::assertTrue(AcceptHeader::isValid('text/calendar'));
        self::assertFalse(AcceptHeader::isValid('application/x-not-real'));
    }
}
