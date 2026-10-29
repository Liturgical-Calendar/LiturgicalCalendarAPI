<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Support;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * Builds an `OpenFgaClient` whose HTTP transport replays queued responses, so tests can script OpenFGA answers
 * without a running server. The queue stays inspectable through `$fgaMock` (e.g. `$this->fgaMock->count()`).
 */
trait MockOpenFgaClientTrait
{
    private MockHandler $fgaMock;

    /**
     * @param array<int, GuzzleResponse> $responses Queued, replayed in order.
     */
    private function mockOpenFgaClient(array $responses): OpenFgaClient
    {
        $this->fgaMock = new MockHandler($responses);
        $guzzle        = new GuzzleClient(['handler' => HandlerStack::create($this->fgaMock)]);
        $psr17         = new Psr17Factory();

        return new OpenFgaClient(
            apiUrl: 'http://openfga.test',
            storeId: 'test-store',
            modelId: 'test-model',
            httpClient: $guzzle,
            requestFactory: $psr17,
            streamFactory: $psr17,
            apiToken: 'test-token'
        );
    }

    /**
     * A `/check` response body.
     */
    private static function fgaAllowed(bool $allowed): GuzzleResponse
    {
        return new GuzzleResponse(200, [], $allowed ? '{"allowed":true}' : '{"allowed":false}');
    }
}
