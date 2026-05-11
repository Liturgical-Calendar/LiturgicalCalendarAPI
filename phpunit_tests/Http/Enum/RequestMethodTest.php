<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http\Enum;

use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestMethod::class)]
final class RequestMethodTest extends TestCase
{
    public function testCaseValues(): void
    {
        self::assertSame('GET', RequestMethod::GET->value);
        self::assertSame('POST', RequestMethod::POST->value);
        self::assertSame('PUT', RequestMethod::PUT->value);
        self::assertSame('PATCH', RequestMethod::PATCH->value);
        self::assertSame('DELETE', RequestMethod::DELETE->value);
        self::assertSame('OPTIONS', RequestMethod::OPTIONS->value);
        self::assertSame('HEAD', RequestMethod::HEAD->value);
        self::assertSame('CONNECT', RequestMethod::CONNECT->value);
        self::assertSame('TRACE', RequestMethod::TRACE->value);
    }

    public function testIsValid(): void
    {
        self::assertTrue(RequestMethod::isValid('GET'));
        self::assertTrue(RequestMethod::isValid('TRACE'));
        self::assertFalse(RequestMethod::isValid('PATCHY'));
    }

    public function testAreValid(): void
    {
        self::assertTrue(RequestMethod::areValid(['GET', 'POST']));
        self::assertFalse(RequestMethod::areValid(['GET', 'NOT_A_METHOD']));
    }
}
