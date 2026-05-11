<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\ICSErrorLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ICSErrorLevel::class)]
final class ICSErrorLevelTest extends TestCase
{
    public function testConstants(): void
    {
        self::assertSame(1, ICSErrorLevel::REPAIRED);
        self::assertSame(2, ICSErrorLevel::WARNING);
        self::assertSame(3, ICSErrorLevel::FATAL);
    }

    public function testCastsToHumanReadableString(): void
    {
        self::assertSame('Repaired value', (string) new ICSErrorLevel(ICSErrorLevel::REPAIRED));
        self::assertSame('Warning', (string) new ICSErrorLevel(ICSErrorLevel::WARNING));
        self::assertSame('Fatal Error', (string) new ICSErrorLevel(ICSErrorLevel::FATAL));
    }

    public function testRejectsLevelBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ICSErrorLevel(0);
    }

    public function testRejectsLevelAboveThree(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ICSErrorLevel(4);
    }
}
