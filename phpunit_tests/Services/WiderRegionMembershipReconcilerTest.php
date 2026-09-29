<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\WiderRegionMembershipReconciler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @see WiderRegionMembershipReconciler
 */
#[CoversClass(WiderRegionMembershipReconciler::class)]
class WiderRegionMembershipReconcilerTest extends TestCase
{
    /**
     * @param list<Response> $responses
     * @return array{0: OpenFgaClient, 1: \ArrayObject}
     */
    private function clientWith(array $responses): array
    {
        $history      = new \ArrayObject();
        $mock         = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));
        $httpClient = new Client(['handler' => $handlerStack]);
        $psr17      = new Psr17Factory();

        $client = new OpenFgaClient(
            'http://localhost:8083',
            'store-123',
            'model-456',
            $httpClient,
            $psr17,
            $psr17
        );

        return [$client, $history];
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

    public function testSyncWritesMissingRegionsAndDeletesUndeclaredOnes(): void
    {
        // Read #1 (qualified user): the nation is in Europe and Scandinavia. Read #2 (legacy unqualified user): none.
        [$client, $history] = $this->clientWith([
            self::readResponse([
                ['national_calendar:roman/SE', 'member_nation', 'wider_region:roman/Europe'],
                ['national_calendar:roman/SE', 'member_nation', 'wider_region:roman/Scandinavia'],
            ]),
            self::readResponse([]),
            new Response(200, [], '{}'), // write Nordic
            new Response(200, [], '{}'), // delete Scandinavia
        ]);

        $result = ( new WiderRegionMembershipReconciler($client) )->syncNation('SE', ['Europe', 'Nordic']);

        self::assertSame(['wider_region:roman/Nordic#member_nation@national_calendar:roman/SE'], $result['writes']);
        self::assertSame(['wider_region:roman/Scandinavia#member_nation@national_calendar:roman/SE'], $result['deletes']);
        self::assertCount(4, $history);
    }

    public function testUnqualifiedLegacyTuplesAreReplacedByQualifiedOnes(): void
    {
        [$client] = $this->clientWith([
            self::readResponse([]),
            self::readResponse([['national_calendar:IT', 'member_nation', 'wider_region:Europe']]),
            new Response(200, [], '{}'), // write qualified
            new Response(200, [], '{}'), // delete legacy
        ]);

        $result = ( new WiderRegionMembershipReconciler($client) )->syncNation('IT', ['Europe']);

        self::assertSame(['wider_region:roman/Europe#member_nation@national_calendar:roman/IT'], $result['writes']);
        self::assertSame(['wider_region:Europe#member_nation@national_calendar:IT'], $result['deletes']);
    }

    public function testADryRunPlansWithoutWriting(): void
    {
        [$client, $history] = $this->clientWith([self::readResponse([]), self::readResponse([])]);

        $result = ( new WiderRegionMembershipReconciler($client) )->syncNation('IT', ['Europe'], apply: false);

        self::assertCount(1, $result['writes']);
        self::assertCount(2, $history, 'Only the two reads');
    }

    public function testCurrentRegionsSeparatesQualifiedFromLegacy(): void
    {
        [$client] = $this->clientWith([
            self::readResponse([
                ['national_calendar:roman/SE', 'member_nation', 'wider_region:roman/Europe'],
            ]),
            self::readResponse([
                ['national_calendar:SE', 'member_nation', 'wider_region:Europe'],
            ]),
        ]);

        $result = ( new WiderRegionMembershipReconciler($client) )->currentRegions('SE');

        self::assertSame(['Europe'], $result['qualified']);
        self::assertSame(
            [['user' => 'national_calendar:SE', 'relation' => 'member_nation', 'object' => 'wider_region:Europe']],
            $result['legacy']
        );
    }

    public function testNationsWithTuplesListsDistinctNations(): void
    {
        [$client] = $this->clientWith([
            self::readResponse([
                ['national_calendar:roman/SE', 'member_nation', 'wider_region:roman/Europe'],
                ['national_calendar:roman/SE', 'member_nation', 'wider_region:roman/Nordic'],
                ['national_calendar:IT', 'member_nation', 'wider_region:Europe'],
                ['user:someone', 'admin', 'national_calendar:roman/SE'],
            ]),
        ]);

        $result = ( new WiderRegionMembershipReconciler($client) )->nationsWithTuples();

        self::assertSame(['IT', 'SE'], $result);
    }

    /**
     * A half-qualified tuple — user qualified, object NOT Roman rite-qualified (`wider_region:Europe`, no rite
     * prefix at all) — must not be counted as a satisfied region, or it would never be replaced with the properly
     * qualified one. It is pruned alongside the genuinely legacy (unqualified-user) tuples.
     */
    public function testAHalfQualifiedTupleIsNotCountedAsQualifiedAndIsPruned(): void
    {
        [$client] = $this->clientWith([
            self::readResponse([
                ['national_calendar:roman/IT', 'member_nation', 'wider_region:Europe'],
            ]),
            self::readResponse([]),
            new Response(200, [], '{}'), // write qualified Europe
            new Response(200, [], '{}'), // delete half-qualified
        ]);

        $result = ( new WiderRegionMembershipReconciler($client) )->syncNation('IT', ['Europe']);

        self::assertSame(['wider_region:roman/Europe#member_nation@national_calendar:roman/IT'], $result['writes']);
        self::assertSame(['wider_region:Europe#member_nation@national_calendar:roman/IT'], $result['deletes']);
    }

    public function testSyncNationDoesNotWriteARegionAlreadyPresent(): void
    {
        [$client] = $this->clientWith([
            self::readResponse([
                ['national_calendar:roman/IT', 'member_nation', 'wider_region:roman/Europe'],
            ]),
            self::readResponse([]),
        ]);

        $result = ( new WiderRegionMembershipReconciler($client) )->syncNation('IT', ['Europe']);

        self::assertSame([], $result['writes']);
        self::assertSame([], $result['deletes']);
    }
}
