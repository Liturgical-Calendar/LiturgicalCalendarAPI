<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Calendar\Rite\AmbrosianRiteProfile;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\AmbrosianTemporale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AmbrosianRiteProfile::class)]
final class AmbrosianRiteProfileTest extends TestCase
{
    public function testRiteIsAmbrosian(): void
    {
        self::assertSame(Rite::AMBROSIAN, ( new AmbrosianRiteProfile() )->rite());
    }

    public function testWhitelistIsTheFourDioceses(): void
    {
        self::assertSame(
            ['milano_it', 'bergam_it', 'novara_it', 'lugano_ch'],
            AmbrosianRiteProfile::SUPPORTED_DIOCESES
        );
    }

    public function testTemporaleEngineReturnsAmbrosianTemporale(): void
    {
        $profile = new AmbrosianRiteProfile();
        $this->assertInstanceOf(AmbrosianTemporale::class, $profile->temporaleEngine());
    }
}
