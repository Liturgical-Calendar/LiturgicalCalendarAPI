<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\WiderRegionMembershipSeeder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WiderRegionMembershipSeeder::class)]
class WiderRegionMembershipSeederTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wr_seed_' . uniqid();
        mkdir($this->dir . '/IT', 0777, true);
        mkdir($this->dir . '/SE', 0777, true);
        mkdir($this->dir . '/XX', 0777, true); // no region declared
        file_put_contents($this->dir . '/IT/IT.json', json_encode(['metadata' => ['nation' => 'IT', 'wider_region' => 'Europe']]));
        file_put_contents($this->dir . '/SE/SE.json', json_encode(['metadata' => ['nation' => 'SE', 'wider_regions' => ['Europe', 'Nordic']]]));
        file_put_contents($this->dir . '/XX/XX.json', json_encode(['metadata' => ['nation' => 'XX']]));
    }

    protected function tearDown(): void
    {
        foreach (['IT', 'SE', 'XX', 'NOFILE'] as $n) {
            @unlink("{$this->dir}/{$n}/{$n}.json");
            @rmdir("{$this->dir}/{$n}");
        }
        @rmdir($this->dir);
    }

    public function testDeclaredRegionsMapsNationsToRegionListsMostGeneralFirst(): void
    {
        $declared = ( new WiderRegionMembershipSeeder() )->declaredRegions($this->dir);

        $this->assertSame(
            ['IT' => ['Europe'], 'SE' => ['Europe', 'Nordic'], 'XX' => []],
            $declared
        );
    }

    public function testDeclaredRegionsThrowsOnInvalidJson(): void
    {
        file_put_contents($this->dir . '/IT/IT.json', '{ not valid json');
        $this->expectException(\RuntimeException::class);
        ( new WiderRegionMembershipSeeder() )->declaredRegions($this->dir);
    }

    public function testDeclaredRegionsSkipsDirectoryWithNoJsonFile(): void
    {
        // NOFILE directory exists but contains no NOFILE.json — must be skipped
        mkdir($this->dir . '/NOFILE', 0777, true);

        $declared = ( new WiderRegionMembershipSeeder() )->declaredRegions($this->dir);

        $this->assertArrayNotHasKey('NOFILE', $declared);
    }

    public function testDeclaredRegionsSkipsNationWhereMetadataIsNotArray(): void
    {
        // Write a JSON file where 'metadata' is a scalar, not an array
        file_put_contents($this->dir . '/IT/IT.json', json_encode(['metadata' => 'invalid']));

        $declared = ( new WiderRegionMembershipSeeder() )->declaredRegions($this->dir);

        $this->assertSame([], $declared['IT']);
    }

    public function testDeclaredRegionsReturnsEmptyArrayForEmptyDirectory(): void
    {
        $emptyDir = sys_get_temp_dir() . '/wr_seed_empty_' . uniqid();
        mkdir($emptyDir, 0777, true);

        try {
            $declared = ( new WiderRegionMembershipSeeder() )->declaredRegions($emptyDir);

            $this->assertSame([], $declared);
        } finally {
            @rmdir($emptyDir);
        }
    }
}
