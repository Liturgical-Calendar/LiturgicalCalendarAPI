<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http\Enum;

use LiturgicalCalendar\Api\Http\Enum\ContentEncoding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentEncoding::class)]
final class ContentEncodingTest extends TestCase
{
    public function testCases(): void
    {
        self::assertSame('br', ContentEncoding::BR->value);
        self::assertSame('gzip', ContentEncoding::GZIP->value);
        self::assertSame('deflate', ContentEncoding::DEFLATE->value);
        self::assertSame('identity', ContentEncoding::IDENTITY->value);
    }

    public function testFromValueOrNull(): void
    {
        self::assertSame(ContentEncoding::BR, ContentEncoding::fromValueOrNull('br'));
        self::assertSame(ContentEncoding::GZIP, ContentEncoding::fromValueOrNull('gzip'));
        // x-gzip is normalized to GZIP.
        self::assertSame(ContentEncoding::GZIP, ContentEncoding::fromValueOrNull('x-gzip'));
        self::assertSame(ContentEncoding::DEFLATE, ContentEncoding::fromValueOrNull('  DEFLATE '));
        self::assertSame(ContentEncoding::IDENTITY, ContentEncoding::fromValueOrNull('identity'));
        self::assertNull(ContentEncoding::fromValueOrNull('compress'));
    }
}
