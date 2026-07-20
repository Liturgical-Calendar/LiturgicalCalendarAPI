<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Calendar\Rite\AmbrosianRiteProfile;
use LiturgicalCalendar\Api\Models\Calendar\Rite\RiteProfileFactory;
use LiturgicalCalendar\Api\Models\Calendar\Rite\RomanRiteProfile;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\RomanTemporale;
use PHPUnit\Framework\TestCase;

final class RiteProfileFactoryTest extends TestCase
{
    public function testRomanProfileSuppliesRomanTemporale(): void
    {
        $profile = RiteProfileFactory::forRite(Rite::ROMAN);
        self::assertInstanceOf(RomanRiteProfile::class, $profile);
        self::assertSame(Rite::ROMAN, $profile->rite());
        self::assertInstanceOf(RomanTemporale::class, $profile->temporaleEngine());
    }

    public function testAmbrosianProfileIsReturned(): void
    {
        $profile = RiteProfileFactory::forRite(Rite::AMBROSIAN);
        self::assertInstanceOf(AmbrosianRiteProfile::class, $profile);
        self::assertSame(Rite::AMBROSIAN, $profile->rite());
    }
}
