<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Calendar\Missal\AmbrosianMissalResolver;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\AmbrosianPrecedenceResolver;
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

    public function testTemporaleEngineReturnsAmbrosianTemporale(): void
    {
        $profile = new AmbrosianRiteProfile();
        $this->assertInstanceOf(AmbrosianTemporale::class, $profile->temporaleEngine());
    }

    public function testPrecedenceResolverReturnsAmbrosianPrecedenceResolver(): void
    {
        $profile = new AmbrosianRiteProfile();
        $this->assertInstanceOf(AmbrosianPrecedenceResolver::class, $profile->precedenceResolver());
    }

    public function testMissalResolverReturnsAmbrosianMissalResolver(): void
    {
        $profile = new AmbrosianRiteProfile();
        $this->assertInstanceOf(AmbrosianMissalResolver::class, $profile->missalResolver());
    }
}
