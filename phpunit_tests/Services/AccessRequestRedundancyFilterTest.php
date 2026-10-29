<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Services\AccessRequestRedundancyFilter;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Tests\Support\CollectingLogger;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AccessRequestRedundancyFilter::class)]
final class AccessRequestRedundancyFilterTest extends TestCase
{
    private MockHandler $mock;

    private CollectingLogger $logger;

    /**
     * @param array<int, GuzzleResponse> $responses Queued, replayed in order.
     */
    private function filterWith(array $responses, ?float $budgetSeconds = null, ?\Closure $clock = null): AccessRequestRedundancyFilter
    {
        $this->mock   = new MockHandler($responses);
        $this->logger = new CollectingLogger();
        $guzzle       = new GuzzleClient(['handler' => HandlerStack::create($this->mock)]);
        $psr17        = new Psr17Factory();
        $client       = new OpenFgaClient(
            apiUrl: 'http://openfga.test',
            storeId: 'test-store',
            modelId: 'test-model',
            httpClient: $guzzle,
            requestFactory: $psr17,
            streamFactory: $psr17,
            apiToken: 'test-token'
        );

        return new AccessRequestRedundancyFilter($client, $this->logger, $budgetSeconds, $clock);
    }

    private static function allowed(bool $allowed): GuzzleResponse
    {
        return new GuzzleResponse(200, [], $allowed ? '{"allowed":true}' : '{"allowed":false}');
    }

    /**
     * @return array{object_type: string, object_id: string, relation: string}
     */
    private static function perm(string $relation, string $id, string $type = 'national_calendar'): array
    {
        return ['object_type' => $type, 'object_id' => $id, 'relation' => $relation];
    }

    public function testDuplicateKeepsHighestRelationPerObject(): void
    {
        $filter = $this->filterWith([self::allowed(false), self::allowed(false)]);

        $result = $filter->filter('u1', [
            self::perm('editor', 'roman/IT'),
            self::perm('admin', 'roman/IT'),
            self::perm('viewer', 'roman/FR'),
        ], []);

        $this->assertSame([self::perm('admin', 'roman/IT'), self::perm('viewer', 'roman/FR')], $result['kept']);
        $this->assertSame([self::perm('editor', 'roman/IT') + ['reason' => 'duplicate']], $result['skipped']);
    }

    public function testIdenticalTriplesKeepTheFirst(): void
    {
        $filter = $this->filterWith([self::allowed(false)]);

        $result = $filter->filter('u1', [self::perm('editor', 'roman/IT'), self::perm('editor', 'roman/IT')], []);

        $this->assertCount(1, $result['kept']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame('duplicate', $result['skipped'][0]['reason']);
    }

    public function testPendingEqualOrHigherSkips(): void
    {
        $filter = $this->filterWith([]);

        $result = $filter->filter('u1', [self::perm('editor', 'roman/IT')], [self::perm('admin', 'roman/IT')]);

        $this->assertSame([], $result['kept']);
        $this->assertSame([self::perm('editor', 'roman/IT') + ['reason' => 'pending']], $result['skipped']);
        $this->assertSame(0, $this->mock->count());
    }

    public function testPendingLowerDoesNotSkip(): void
    {
        $filter = $this->filterWith([self::allowed(false)]);

        $result = $filter->filter('u1', [self::perm('admin', 'roman/IT')], [self::perm('viewer', 'roman/IT')]);

        $this->assertSame([self::perm('admin', 'roman/IT')], $result['kept']);
        $this->assertSame([], $result['skipped']);
    }

    public function testPendingLegacyWiderRegionIdIsCanonicalized(): void
    {
        $filter = $this->filterWith([]);

        $result = $filter->filter(
            'u1',
            [self::perm('editor', 'roman/europe', 'wider_region')],
            [self::perm('editor', 'roman/Europe', 'wider_region')]
        );

        $this->assertSame([], $result['kept']);
        $this->assertSame('pending', $result['skipped'][0]['reason']);
    }

    public function testGrantedSkips(): void
    {
        $filter = $this->filterWith([self::allowed(true)]);

        $result = $filter->filter('u1', [self::perm('editor', 'roman/IT')], []);

        $this->assertSame([], $result['kept']);
        $this->assertSame([self::perm('editor', 'roman/IT') + ['reason' => 'granted']], $result['skipped']);
    }

    public function testFgaErrorFailsOpen(): void
    {
        $filter = $this->filterWith([new GuzzleResponse(500, [], 'boom')]);

        $result = $filter->filter('u1', [self::perm('editor', 'roman/IT')], []);

        $this->assertSame([self::perm('editor', 'roman/IT')], $result['kept']);
        $this->assertSame([], $result['skipped']);
        $this->assertCount(1, $this->logger->records());
    }

    public function testNullClientGrantsNothing(): void
    {
        $filter = new AccessRequestRedundancyFilter(null);

        $result = $filter->filter('u1', [self::perm('admin', 'roman/IT')], []);

        $this->assertSame([self::perm('admin', 'roman/IT')], $result['kept']);
        $this->assertSame([], $result['skipped']);
    }

    public function testBudgetExhaustedFailsOpen(): void
    {
        $filter = $this->filterWith([self::allowed(true), self::allowed(true), self::allowed(true)], 0.0);

        $result = $filter->filter('u1', [
            self::perm('editor', 'roman/IT'),
            self::perm('editor', 'roman/FR'),
            self::perm('editor', 'roman/DE'),
        ], []);

        $this->assertCount(3, $result['kept']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame(3, $this->mock->count(), 'No request may be sent once the budget is spent');
        $this->assertCount(1, $this->logger->records());
    }

    public function testBudgetExpiresMidLoopFailsOpen(): void
    {
        $now    = 0.0;
        $filter = $this->filterWith(
            [
                static function () use (&$now): GuzzleResponse {
                    $now += 2.0;
                    return new GuzzleResponse(200, [], '{"allowed":false}');
                },
                self::allowed(false),
                self::allowed(false),
            ],
            1.5,
            static function () use (&$now): float {
                return $now;
            }
        );

        $result = $filter->filter('u1', [
            self::perm('editor', 'roman/IT'),
            self::perm('editor', 'roman/FR'),
            self::perm('editor', 'roman/DE'),
        ], []);

        $this->assertCount(3, $result['kept']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame(2, $this->mock->count(), 'Exactly one request is sent before the budget expires');
        $this->assertCount(1, $this->logger->records());
        $this->assertSame(2, $this->logger->records()[0]['context']['skipped']);
    }
}
