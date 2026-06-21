<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\ResourceAdminService;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResourceAdminService::class)]
final class ResourceAdminServiceTest extends TestCase
{
    /**
     * @param array<int, GuzzleResponse> $responses Queued, replayed in order.
     */
    private function serviceWith(array $responses): ResourceAdminService
    {
        $stack  = HandlerStack::create(new MockHandler($responses));
        $guzzle = new GuzzleClient(['handler' => $stack]);
        $psr17  = new Psr17Factory();
        $client = new OpenFgaClient(
            apiUrl: 'http://openfga.test',
            storeId: 'test-store',
            modelId: 'test-model',
            httpClient: $guzzle,
            requestFactory: $psr17,
            streamFactory: $psr17,
            apiToken: 'test-token'
        );
        return new ResourceAdminService($client);
    }

    public function testResolveScopesUnionsAdminTuplesAcrossTypes(): void
    {
        // One list-objects response per ADMIN_OBJECT_TYPES entry, in order:
        // national_calendar, diocesan_calendar, wider_region, general_roman_calendar
        $service = $this->serviceWith([
            new GuzzleResponse(200, [], '{"objects":["national_calendar:IT"]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
        ]);

        self::assertSame(
            [['object_type' => 'national_calendar', 'object_id' => 'IT']],
            $service->resolveScopes('cei-admin')
        );
    }

    public function testResolveScopesFailsClosedOnOpenFgaError(): void
    {
        $service = $this->serviceWith([
            new GuzzleResponse(500, [], 'boom'),
        ]);

        self::assertSame([], $service->resolveScopes('cei-admin'));
    }

    public function testFilterByAdminAccessKeepsOnlyFullyAdministeredRequests(): void
    {
        // check() calls, in request order:
        // req A perm national_calendar:IT -> allowed
        // req B perm national_calendar:US -> denied
        $service = $this->serviceWith([
            new GuzzleResponse(200, [], '{"allowed":true}'),
            new GuzzleResponse(200, [], '{"allowed":false}'),
        ]);

        $requests = [
            ['id' => 'A', 'permissions' => [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]],
            ['id' => 'B', 'permissions' => [['object_type' => 'national_calendar', 'object_id' => 'US', 'relation' => 'editor']]],
            ['id' => 'C', 'permissions' => []],
        ];

        $filtered = $service->filterByAdminAccess($requests, 'cei-admin');

        self::assertSame(['A'], array_column($filtered, 'id'));
    }

    public function testResolveScopesUnionsMultipleTypesWithResults(): void
    {
        // One list-objects response per ADMIN_OBJECT_TYPES entry, in order:
        // national_calendar, diocesan_calendar, wider_region, general_roman_calendar.
        // Two types return results; the union must include both in ADMIN_OBJECT_TYPES order.
        // (The existing happy-path test covers a single type; this proves the cross-type union.)
        $service = $this->serviceWith([
            new GuzzleResponse(200, [], '{"objects":["national_calendar:IT"]}'),
            new GuzzleResponse(200, [], '{"objects":["diocesan_calendar:ROMA"]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
        ]);

        self::assertSame(
            [
                ['object_type' => 'national_calendar', 'object_id' => 'IT'],
                ['object_type' => 'diocesan_calendar', 'object_id' => 'ROMA'],
            ],
            $service->resolveScopes('multi-admin')
        );
    }

    public function testFilterByAdminAccessCachesDuplicateResourceChecks(): void
    {
        // The same resource (national_calendar:IT) appears twice in the permissions
        // array of a single request. Without per-call caching, filterByAdminAccess
        // would call check() twice, consuming two queued responses. With caching it
        // calls check() only once per unique "{type}:{id}" key.
        //
        // Proof mechanism: Guzzle MockHandler throws OutOfBoundsException when the
        // response queue is exhausted. Queuing exactly ONE response and observing
        // that the test completes without exception — and that the request is kept —
        // demonstrates the second check was served from the in-memory cache rather
        // than making a second network call.
        $service = $this->serviceWith([
            new GuzzleResponse(200, [], '{"allowed":true}'),
        ]);

        $requests = [
            [
                'id'          => 'A',
                'permissions' => [
                    ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
                    ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
                ],
            ],
        ];

        $filtered = $service->filterByAdminAccess($requests, 'cei-admin');

        self::assertSame(['A'], array_column($filtered, 'id'));
    }

    public function testFilterByAdminAccessPropagatesRuntimeException(): void
    {
        // filterByAdminAccess does NOT catch RuntimeException (contrast with
        // resolveScopes, which fails closed on error). A 500 response from
        // OpenFGA causes OpenFgaApiException (which extends RuntimeException)
        // to propagate out of the method uncaught.
        $service = $this->serviceWith([
            new GuzzleResponse(500, [], 'boom'),
        ]);

        $requests = [
            ['id' => 'A', 'permissions' => [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]],
        ];

        $this->expectException(\RuntimeException::class);
        $service->filterByAdminAccess($requests, 'cei-admin');
    }
}
