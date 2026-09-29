<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\WiderRegionMembershipReconciler;
use LiturgicalCalendar\Api\Services\WiderRegionMembershipSeeder;
use Nyholm\Psr7\Factory\Psr17Factory;
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

    /**
     * @param list<Response> $responses
     */
    private function clientWith(array $responses): OpenFgaClient
    {
        $mock         = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $httpClient   = new Client(['handler' => $handlerStack]);
        $psr17        = new Psr17Factory();

        return new OpenFgaClient(
            'http://localhost:8083',
            'store-123',
            'model-456',
            $httpClient,
            $psr17,
            $psr17
        );
    }

    /**
     * @param list<array{string, string, string}> $tuples
     */
    private static function readResponse(array $tuples): Response
    {
        $encoded = array_map(
            static fn (array $t): array => ['key' => ['user' => $t[0], 'relation' => $t[1], 'object' => $t[2]]],
            $tuples
        );

        return new Response(200, [], (string) json_encode([
            'tuples'             => $encoded,
            'continuation_token' => '',
        ]));
    }

    /**
     * `reconcile()` merges nations declared by a file with nations holding any tuple. Here only SE has a file, and
     * only FI holds a tuple (for a region it no longer declares — it has no file at all), so a dry run must both
     * plan writing SE's Europe tuple and pruning FI's stale one. The mock queue is ordered exactly as `reconcile()`
     * reads: {@see WiderRegionMembershipReconciler::nationsWithTuples()} first (one global read), then one
     * {@see WiderRegionMembershipReconciler::currentRegions()} (two reads: qualified user, then legacy user) per
     * nation in `$declared`'s iteration order — SE (from the file, ksorted first) then FI (appended after).
     */
    public function testReconcileWritesForADeclaringNationAndPrunesANationWithNoFile(): void
    {
        $dir = sys_get_temp_dir() . '/wr_seed_reconcile_' . uniqid();
        mkdir($dir . '/SE', 0777, true);
        file_put_contents($dir . '/SE/SE.json', json_encode(['metadata' => ['wider_regions' => ['Europe']]]));

        try {
            $client = $this->clientWith([
                // nationsWithTuples(): global read
                self::readResponse([
                    ['national_calendar:roman/FI', 'member_nation', 'wider_region:roman/Europe'],
                ]),
                // syncNation('SE', ...) -> currentRegions('SE'): qualified user, then legacy user
                self::readResponse([]),
                self::readResponse([]),
                // syncNation('FI', ...) -> currentRegions('FI'): qualified user, then legacy user
                self::readResponse([
                    ['national_calendar:roman/FI', 'member_nation', 'wider_region:roman/Europe'],
                ]),
                self::readResponse([]),
            ]);
            $reconciler = new WiderRegionMembershipReconciler($client);

            $result = ( new WiderRegionMembershipSeeder() )->reconcile($reconciler, $dir, false);

            $this->assertContains(
                'wider_region:roman/Europe#member_nation@national_calendar:roman/SE',
                $result['writes']
            );
            $this->assertContains(
                'wider_region:roman/Europe#member_nation@national_calendar:roman/FI',
                $result['deletes']
            );
        } finally {
            @unlink($dir . '/SE/SE.json');
            @rmdir($dir . '/SE');
            @rmdir($dir);
        }
    }
}
