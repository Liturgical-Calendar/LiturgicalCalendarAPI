<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http\Enum;

use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AcceptabilityLevel::class)]
final class AcceptabilityLevelTest extends TestCase
{
    public function testCases(): void
    {
        $cases = AcceptabilityLevel::cases();
        self::assertContains(AcceptabilityLevel::LAX, $cases);
        self::assertContains(AcceptabilityLevel::INTERMEDIATE, $cases);
        self::assertContains(AcceptabilityLevel::STRICT, $cases);
        self::assertContains(AcceptabilityLevel::DEFAULT, $cases);
        self::assertCount(4, $cases);
    }
}
