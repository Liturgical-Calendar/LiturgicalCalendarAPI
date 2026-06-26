<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\Exception\TupleAlreadyExistsException;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
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
        mkdir($this->dir . '/US', 0777, true);
        mkdir($this->dir . '/XX', 0777, true); // no wider_region -> skipped
        file_put_contents($this->dir . '/IT/IT.json', json_encode(['metadata' => ['nation' => 'IT', 'wider_region' => 'Europe']]));
        file_put_contents($this->dir . '/US/US.json', json_encode(['metadata' => ['nation' => 'US', 'wider_region' => 'Americas']]));
        file_put_contents($this->dir . '/XX/XX.json', json_encode(['metadata' => ['nation' => 'XX']]));
    }

    protected function tearDown(): void
    {
        foreach (['IT', 'US', 'XX', 'NOFILE'] as $n) {
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

    public function testComputeTuplesSkipsDirectoryWithNoJsonFile(): void
    {
        // NOFILE directory exists but contains no NOFILE.json — must be skipped
        mkdir($this->dir . '/NOFILE', 0777, true);

        $tuples = ( new WiderRegionMembershipSeeder() )->computeTuples($this->dir);

        foreach ($tuples as $t) {
            $this->assertStringNotContainsString('NOFILE', $t['user']);
        }
    }

    public function testComputeTuplesSkipsNationWhereMetadataIsNotArray(): void
    {
        // Write a JSON file where 'metadata' is a scalar, not an array
        file_put_contents($this->dir . '/IT/IT.json', json_encode(['metadata' => 'invalid']));

        $tuples = ( new WiderRegionMembershipSeeder() )->computeTuples($this->dir);

        foreach ($tuples as $t) {
            $this->assertStringNotContainsString('national_calendar:IT', $t['user']);
        }
    }

    public function testSeedApplyFalseReturnsPlanCountWithZeroWritten(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->never())->method('writeTuple');

        $result = ( new WiderRegionMembershipSeeder() )->seed($client, $this->dir, false);

        $this->assertSame(2, $result['planned']); // IT + US
        $this->assertSame(0, $result['written']);
    }

    public function testSeedApplyTrueWritesTuplesAndReturnsCount(): void
    {
        $client = $this->createMock(OpenFgaClient::class);
        $client->expects($this->exactly(2))->method('writeTuple');

        $result = ( new WiderRegionMembershipSeeder() )->seed($client, $this->dir, true);

        $this->assertSame(2, $result['planned']);
        $this->assertSame(2, $result['written']);
    }

    public function testSeedSwallowsTupleAlreadyExistsException(): void
    {
        $client = $this->createStub(OpenFgaClient::class);
        $client->method('writeTuple')
            ->willThrowException(new TupleAlreadyExistsException('already exists', 409));

        // Should not throw; written stays 0 because every write was a duplicate
        $result = ( new WiderRegionMembershipSeeder() )->seed($client, $this->dir, true);

        $this->assertSame(2, $result['planned']);
        $this->assertSame(0, $result['written']); // all skipped as already-seeded
    }

    public function testSeedWithEmptyDirectoryReturnsZeros(): void
    {
        $emptyDir = sys_get_temp_dir() . '/wr_seed_empty_' . uniqid();
        mkdir($emptyDir, 0777, true);

        try {
            $client = $this->createMock(OpenFgaClient::class);
            $client->expects($this->never())->method('writeTuple');

            $result = ( new WiderRegionMembershipSeeder() )->seed($client, $emptyDir, true);

            $this->assertSame(0, $result['planned']);
            $this->assertSame(0, $result['written']);
        } finally {
            @rmdir($emptyDir);
        }
    }
}
