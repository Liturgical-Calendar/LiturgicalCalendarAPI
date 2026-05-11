<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http\Enum;

use LiturgicalCalendar\Api\Http\Enum\RequestContentType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestContentType::class)]
final class RequestContentTypeTest extends TestCase
{
    public function testCaseValues(): void
    {
        self::assertSame('application/json', RequestContentType::JSON->value);
        self::assertSame('application/yaml', RequestContentType::YAML->value);
        self::assertSame('application/xml', RequestContentType::XML->value);
        self::assertSame('application/x-www-form-urlencoded', RequestContentType::FORMDATA->value);
        self::assertSame('multipart/form-data', RequestContentType::MULTIPART->value);
    }

    public function testIsValid(): void
    {
        self::assertTrue(RequestContentType::isValid('application/json'));
        self::assertFalse(RequestContentType::isValid('not/real'));
    }
}
