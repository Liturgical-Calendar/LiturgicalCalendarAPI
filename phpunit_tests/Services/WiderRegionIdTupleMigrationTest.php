<?php

namespace LiturgicalCalendar\Tests\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\WiderRegionIdTupleMigration;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WiderRegionIdTupleMigration::class)]
final class WiderRegionIdTupleMigrationTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $history = [];

    /**
     * @param list<array{user:string,relation:string,object:string}> $tuples
     * @param int $extraResponses number of empty 200 responses queued after the read page
     */
    private function clientReturning(array $tuples, int $extraResponses = 0): OpenFgaClient
    {
        $rows = array_map(static fn(array $t): array => ['key' => $t], $tuples);
        $mock = new MockHandler([new Response(200, [], json_encode(['tuples' => $rows, 'continuation_token' => ''], JSON_THROW_ON_ERROR))]);
        for ($i = 0; $i < $extraResponses; $i++) {
            $mock->append(new Response(200, [], '{}'));
        }
        $this->history = [];
        $stack         = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $psr17 = new Psr17Factory();

        return new OpenFgaClient('http://localhost:8083', 'store-123', 'model-456', new Client(['handler' => $stack]), $psr17, $psr17);
    }

    /** @return list<array{path:string, body:array<string, mixed>}> */
    private function requests(): array
    {
        $out = [];
        foreach ($this->history as $tx) {
            $decoded = json_decode((string) $tx['request']->getBody(), true);
            self::assertIsArray($decoded);
            $out[] = ['path' => $tx['request']->getUri()->getPath(), 'body' => $decoded];
        }

        return $out;
    }

    public function testMapReference(): void
    {
        self::assertSame('wider_region:roman/europe', WiderRegionIdTupleMigration::mapReference('wider_region:roman/Europe'));
        self::assertSame('wider_region:roman/middle-east', WiderRegionIdTupleMigration::mapReference('wider_region:roman/Middle East'));
        self::assertSame('wider_region:roman/europe', WiderRegionIdTupleMigration::mapReference('wider_region:roman/europe'));
        self::assertSame('national_calendar:roman/IT', WiderRegionIdTupleMigration::mapReference('national_calendar:roman/IT'));
        self::assertSame('user:abc', WiderRegionIdTupleMigration::mapReference('user:abc'));
        // A tuple written before #786 rite-qualification: wider regions exist only in the Roman rite.
        self::assertSame('wider_region:roman/europe', WiderRegionIdTupleMigration::mapReference('wider_region:Europe'));
        self::assertSame('wider_region:roman/middle-east', WiderRegionIdTupleMigration::mapReference('wider_region:Middle East'));
        // A bare id that is not a legacy name is not ours to rewrite.
        self::assertSame('wider_region:europe', WiderRegionIdTupleMigration::mapReference('wider_region:europe'));
    }

    public function testPlanCopiesOnlyTuplesNamingALegacyRegion(): void
    {
        $migration = new WiderRegionIdTupleMigration($this->clientReturning([
            ['user' => 'user:1', 'relation' => 'editor', 'object' => 'wider_region:roman/Europe'],
            ['user' => 'national_calendar:roman/IT', 'relation' => 'member_nation', 'object' => 'wider_region:roman/Europe'],
            ['user' => 'user:2', 'relation' => 'editor', 'object' => 'wider_region:roman/asia'],
            ['user' => 'user:3', 'relation' => 'editor', 'object' => 'national_calendar:roman/US'],
        ]));

        self::assertSame(
            [
                [
                    'from' => ['user' => 'user:1', 'relation' => 'editor', 'object' => 'wider_region:roman/Europe'],
                    'to'   => ['user' => 'user:1', 'relation' => 'editor', 'object' => 'wider_region:roman/europe']
                ],
                [
                    'from' => ['user' => 'national_calendar:roman/IT', 'relation' => 'member_nation', 'object' => 'wider_region:roman/Europe'],
                    'to'   => ['user' => 'national_calendar:roman/IT', 'relation' => 'member_nation', 'object' => 'wider_region:roman/europe']
                ],
            ],
            $migration->plan()
        );
    }

    public function testApplyWritesBeforeItDeletesAndOnlyDeletesWithPrune(): void
    {
        $tuples = [
            ['user' => 'user:1', 'relation' => 'editor', 'object' => 'wider_region:roman/Europe'],
            ['user' => 'user:2', 'relation' => 'admin', 'object' => 'wider_region:roman/Americas'],
            ['user' => 'user:3', 'relation' => 'editor', 'object' => 'national_calendar:roman/US'],
        ];

        // Copy only: read, then one write per planned tuple, and no delete.
        $migration = new WiderRegionIdTupleMigration($this->clientReturning($tuples, 2));
        self::assertSame(['copied' => 2, 'pruned' => 0], $migration->apply(false));
        $requests = $this->requests();
        self::assertCount(3, $requests);
        self::assertStringEndsWith('/read', $requests[0]['path']);
        foreach ([1, 2] as $i) {
            self::assertStringEndsWith('/write', $requests[$i]['path']);
            self::assertArrayHasKey('writes', $requests[$i]['body']);
            self::assertArrayNotHasKey('deletes', $requests[$i]['body']);
        }
        self::assertSame('wider_region:roman/europe', $requests[1]['body']['writes']['tuple_keys'][0]['object']);
        self::assertSame('wider_region:roman/americas', $requests[2]['body']['writes']['tuple_keys'][0]['object']);

        // Copy and prune: each tuple is written before its original is deleted.
        $migration = new WiderRegionIdTupleMigration($this->clientReturning($tuples, 4));
        self::assertSame(['copied' => 2, 'pruned' => 2], $migration->apply(true));
        $requests = $this->requests();
        self::assertCount(5, $requests);
        self::assertStringEndsWith('/read', $requests[0]['path']);
        $expected = [
            [1, 'writes', 'wider_region:roman/europe'],
            [2, 'deletes', 'wider_region:roman/Europe'],
            [3, 'writes', 'wider_region:roman/americas'],
            [4, 'deletes', 'wider_region:roman/Americas'],
        ];
        foreach ($expected as [$i, $key, $object]) {
            self::assertStringEndsWith('/write', $requests[$i]['path']);
            self::assertArrayHasKey($key, $requests[$i]['body']);
            self::assertSame($object, $requests[$i]['body'][$key]['tuple_keys'][0]['object']);
        }
    }
}
