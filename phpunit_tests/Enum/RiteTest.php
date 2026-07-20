<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\Rite;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Rite::class)]
final class RiteTest extends TestCase
{
    public function testDefaultIsRoman(): void
    {
        self::assertSame(Rite::ROMAN, Rite::default());
    }

    public function testFromValue(): void
    {
        self::assertSame(Rite::AMBROSIAN, Rite::from('ambrosian'));
    }
}
