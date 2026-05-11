<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http\Enum;

use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReturnTypeParam::class)]
final class ReturnTypeParamTest extends TestCase
{
    public function testCaseValues(): void
    {
        self::assertSame('JSON', ReturnTypeParam::JSON->value);
        self::assertSame('YML', ReturnTypeParam::YAML->value);
        self::assertSame('XML', ReturnTypeParam::XML->value);
        self::assertSame('ICS', ReturnTypeParam::ICS->value);
    }

    public function testToResponseContentTypeAndAlias(): void
    {
        self::assertSame(AcceptHeader::JSON, ReturnTypeParam::JSON->toResponseContentType());
        self::assertSame(AcceptHeader::YAML, ReturnTypeParam::YAML->toResponseContentType());
        self::assertSame(AcceptHeader::ICS, ReturnTypeParam::ICS->toResponseContentType());
        self::assertSame(
            ReturnTypeParam::XML->toResponseContentType(),
            ReturnTypeParam::XML->toAcceptMimeType()
        );
    }

    public function testEveryReturnTypeMapsToAnAcceptHeader(): void
    {
        foreach (ReturnTypeParam::cases() as $case) {
            self::assertInstanceOf(AcceptHeader::class, $case->toResponseContentType());
        }
    }
}
