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
}
