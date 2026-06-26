<?php

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\WiderRegionMembershipSeeder;
use PHPUnit\Framework\TestCase;

class WiderRegionMembershipSeederTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wr_seed_' . uniqid();
        mkdir($this->dir . '/IT', 0777, true);
        mkdir($this->dir . '/US', 0777, true);
        mkdir($this->dir . '/XX', 0777, true); // no wider_region -> skipped
        file_put_contents($this->dir . '/IT/IT.json', json_encode(['metadata' => ['nation' => 'IT', 'wider_region' => 'Europe']]));
        file_put_contents($this->dir . '/US/US.json', json_encode(['metadata' => ['nation' => 'US', 'wider_region' => 'Americas']]));
        file_put_contents($this->dir . '/XX/XX.json', json_encode(['metadata' => ['nation' => 'XX']]));
    }

    protected function tearDown(): void
    {
        foreach (['IT', 'US', 'XX'] as $n) {
            @unlink("{$this->dir}/{$n}/{$n}.json");
            @rmdir("{$this->dir}/{$n}");
        }
        @rmdir($this->dir);
    }

    public function testComputeTuplesMapsNationsToRegions(): void
    {
        $tuples = ( new WiderRegionMembershipSeeder() )->computeTuples($this->dir);
        $this->assertContains(
            ['user' => 'national_calendar:IT', 'relation' => 'member_nation', 'object' => 'wider_region:Europe'],
            $tuples
        );
        $this->assertContains(
            ['user' => 'national_calendar:US', 'relation' => 'member_nation', 'object' => 'wider_region:Americas'],
            $tuples
        );
        $this->assertCount(2, $tuples); // XX skipped (no wider_region)
    }
}
