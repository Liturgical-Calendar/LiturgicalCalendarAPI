<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Calendar\Rite\RomanRiteProfile;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\RomanTemporale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RomanRiteProfile::class)]
final class RomanRiteProfileTest extends TestCase
{
    public function testRiteIsRoman(): void
    {
        self::assertSame(Rite::ROMAN, ( new RomanRiteProfile() )->rite());
    }

    public function testTemporaleEngineReturnsRomanTemporale(): void
    {
        $profile = new RomanRiteProfile();
        $this->assertInstanceOf(RomanTemporale::class, $profile->temporaleEngine());
    }

    public function testPrecedenceResolverThrowsLogicException(): void
    {
        $profile = new RomanRiteProfile();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Roman precedence is resolved inline in CalendarHandler; no resolver is extracted.'
        );

        $profile->precedenceResolver();
    }
}
