<?php

namespace LiturgicalCalendar\Tests\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the OpenFgaClient service.
 *
 * Uses Guzzle's MockHandler to test HTTP interactions without a running OpenFGA server.
 */
class OpenFgaClientTest extends TestCase
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $requestHistory = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Clear any OpenFGA env vars to ensure clean state
        unset($_ENV['OPENFGA_API_URL'], $_ENV['OPENFGA_STORE_ID'], $_ENV['OPENFGA_MODEL_ID']);
        putenv('OPENFGA_API_URL');
        putenv('OPENFGA_STORE_ID');
        putenv('OPENFGA_MODEL_ID');
    }

    protected function tearDown(): void
    {
        // Clean up env vars
        unset($_ENV['OPENFGA_API_URL'], $_ENV['OPENFGA_STORE_ID'], $_ENV['OPENFGA_MODEL_ID']);
        putenv('OPENFGA_API_URL');
        putenv('OPENFGA_STORE_ID');
        putenv('OPENFGA_MODEL_ID');

        parent::tearDown();
    }

    private function createClientWithMock(MockHandler $mock): OpenFgaClient
    {
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

    private function createClientCapturingRequests(MockHandler $mock): OpenFgaClient
    {
        $this->requestHistory = [];
        $handlerStack         = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($this->requestHistory));
        $httpClient = new Client(['handler' => $handlerStack]);
        $psr17      = new Psr17Factory();

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
     * Pull the JSON-decoded payload of the Nth recorded request.
     *
     * @return array<string, mixed>
     */
    private function decodedRequestPayload(int $index): array
    {
        self::assertArrayHasKey($index, $this->requestHistory, "no request at index {$index}");
        $tx = $this->requestHistory[$index];
        self::assertArrayHasKey('request', $tx);
        $body    = (string) $tx['request']->getBody();
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    public function testIsConfiguredReturnsFalseWhenNoEnvVars(): void
    {
        $this->assertFalse(OpenFgaClient::isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenPartialConfig(): void
    {
        $_ENV['OPENFGA_API_URL'] = 'http://localhost:8083';
        // Missing STORE_ID and MODEL_ID
        $this->assertFalse(OpenFgaClient::isConfigured());
    }

    public function testIsConfiguredReturnsTrueWhenFullConfig(): void
    {
        $_ENV['OPENFGA_API_URL']  = 'http://localhost:8083';
        $_ENV['OPENFGA_STORE_ID'] = 'test-store-id';
        $_ENV['OPENFGA_MODEL_ID'] = 'test-model-id';

        $this->assertTrue(OpenFgaClient::isConfigured());
    }

    public function testFromEnvThrowsWhenNotConfigured(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenFGA not configured');

        OpenFgaClient::fromEnv();
    }

    public function testFromEnvCreatesClientWhenConfigured(): void
    {
        $_ENV['OPENFGA_API_URL']  = 'http://localhost:8083';
        $_ENV['OPENFGA_STORE_ID'] = 'test-store-id';
        $_ENV['OPENFGA_MODEL_ID'] = 'test-model-id';

        $client = OpenFgaClient::fromEnv();
        $this->assertInstanceOf(OpenFgaClient::class, $client);
    }

    public function testIsConfiguredReadsFromGetenv(): void
    {
        putenv('OPENFGA_API_URL=http://localhost:8083');
        putenv('OPENFGA_STORE_ID=test-store-id');
        putenv('OPENFGA_MODEL_ID=test-model-id');

        $this->assertTrue(OpenFgaClient::isConfigured());

        // Clean up
        putenv('OPENFGA_API_URL');
        putenv('OPENFGA_STORE_ID');
        putenv('OPENFGA_MODEL_ID');
    }

    public function testCheckReturnsTrueWhenAllowed(): void
    {
        $mock   = new MockHandler([
            new Response(200, [], json_encode(['allowed' => true]) ?: ''),
        ]);
        $client = $this->createClientWithMock($mock);

        $this->assertTrue($client->check('user:test', 'editor', 'national_calendar:IT'));
    }

    public function testCheckReturnsFalseWhenDenied(): void
    {
        $mock   = new MockHandler([
            new Response(200, [], json_encode(['allowed' => false]) ?: ''),
        ]);
        $client = $this->createClientWithMock($mock);

        $this->assertFalse($client->check('user:test', 'editor', 'national_calendar:IT'));
    }

    public function testCheckThrowsOnApiError(): void
    {
        $mock   = new MockHandler([
            new Response(400, [], json_encode(['message' => 'invalid request']) ?: ''),
        ]);
        $client = $this->createClientWithMock($mock);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenFGA API error (HTTP 400): invalid request');
        $client->check('user:test', 'editor', 'national_calendar:IT');
    }

    public function testWriteTupleSucceeds(): void
    {
        $mock   = new MockHandler([
            new Response(200, [], '{}'),
        ]);
        $client = $this->createClientWithMock($mock);

        $client->writeTuple('user:test', 'editor', 'national_calendar:IT');
        $this->assertEquals(0, $mock->count());
    }

    public function testDeleteTupleSucceeds(): void
    {
        $mock   = new MockHandler([
            new Response(200, [], '{}'),
        ]);
        $client = $this->createClientWithMock($mock);

        $client->deleteTuple('user:test', 'editor', 'national_calendar:IT');
        $this->assertEquals(0, $mock->count());
    }

    public function testReadTuplesReturnsParsedTuples(): void
    {
        $mock   = new MockHandler([
            new Response(200, [], (string) json_encode([
                'tuples'             => [
                    ['key' => ['user' => 'user:alice',  'relation' => 'editor', 'object' => 'national_calendar:IT']],
                    ['key' => ['user' => 'user:bob',    'relation' => 'viewer', 'object' => 'national_calendar:IT']],
                ],
                'continuation_token' => '',
            ])),
        ]);
        $client = $this->createClientWithMock($mock);

        $result = $client->readTuples('', 'national_calendar:IT');

        self::assertSame('', $result['next_continuation_token']);
        self::assertCount(2, $result['tuples']);
        self::assertSame(['user' => 'user:alice', 'relation' => 'editor', 'object' => 'national_calendar:IT'], $result['tuples'][0]);
        self::assertSame(['user' => 'user:bob', 'relation' => 'viewer', 'object' => 'national_calendar:IT'], $result['tuples'][1]);
    }

    public function testReadTuplesReturnsEmptyArrayWhenNoTuples(): void
    {
        $mock   = new MockHandler([
            new Response(200, [], (string) json_encode([
                'tuples'             => [],
                'continuation_token' => '',
            ])),
        ]);
        $client = $this->createClientWithMock($mock);

        $result = $client->readTuples('', 'national_calendar:IT');

        self::assertSame([], $result['tuples']);
        self::assertSame('', $result['next_continuation_token']);
    }

    public function testReadTuplesPassesLimitAndContinuationToken(): void
    {
        $mock   = new MockHandler([
            new Response(200, [], (string) json_encode([
                'tuples'             => [['key' => ['user' => 'user:alice', 'relation' => 'editor', 'object' => 'national_calendar:IT']]],
                'continuation_token' => 'xyz',
            ])),
        ]);
        $client = $this->createClientCapturingRequests($mock);

        $result = $client->readTuples('user:alice', 'national_calendar:IT', null, 50, 'abc');

        self::assertSame('xyz', $result['next_continuation_token']);
        self::assertCount(1, $result['tuples']);

        $payload = $this->decodedRequestPayload(0);
        self::assertSame(50, $payload['page_size']);
        self::assertSame('abc', $payload['continuation_token']);
        self::assertSame(['user' => 'user:alice', 'object' => 'national_calendar:IT'], $payload['tuple_key']);
    }

    public function testReadTuplesOmitsLimitWhenNull(): void
    {
        $mock   = new MockHandler([
            new Response(200, [], (string) json_encode([
                'tuples'             => [],
                'continuation_token' => '',
            ])),
        ]);
        $client = $this->createClientCapturingRequests($mock);

        $client->readTuples('', 'national_calendar:IT');

        $payload = $this->decodedRequestPayload(0);
        self::assertArrayNotHasKey('page_size', $payload);
        self::assertArrayNotHasKey('continuation_token', $payload);
    }

    public function testReadTuplesOmitsContinuationTokenWhenNullOrEmpty(): void
    {
        $mock   = new MockHandler([
            new Response(200, [], (string) json_encode(['tuples' => [], 'continuation_token' => ''])),
            new Response(200, [], (string) json_encode(['tuples' => [], 'continuation_token' => ''])),
        ]);
        $client = $this->createClientCapturingRequests($mock);

        // null continuation token
        $client->readTuples('', 'national_calendar:IT', null, 10, null);
        self::assertArrayNotHasKey('continuation_token', $this->decodedRequestPayload(0));

        // empty-string continuation token
        $client->readTuples('', 'national_calendar:IT', null, 10, '');
        self::assertArrayNotHasKey('continuation_token', $this->decodedRequestPayload(1));
    }

    public function testReadTuplesReturnsEmptyTokenWhenServerOmits(): void
    {
        $mock   = new MockHandler([
            new Response(200, [], (string) json_encode(['tuples' => []])),  // no continuation_token field at all
        ]);
        $client = $this->createClientWithMock($mock);

        $result = $client->readTuples('', 'national_calendar:IT');

        self::assertSame('', $result['next_continuation_token']);
    }

    public function testReadTuplesNoLongerAutoPaginates(): void
    {
        // First response carries a continuation_token. The old auto-loop would
        // have fetched a second page; the new contract returns immediately and
        // hands the token back to the caller.
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'tuples'             => [['key' => ['user' => 'user:alice', 'relation' => 'editor', 'object' => 'national_calendar:IT']]],
                'continuation_token' => 'tok2',
            ])),
            // A second response is queued but should NEVER be consumed.
            new Response(500, [], 'should not be reached'),
        ]);
        $client = $this->createClientCapturingRequests($mock);

        $result = $client->readTuples('', 'national_calendar:IT');

        self::assertSame('tok2', $result['next_continuation_token']);
        self::assertCount(1, $this->requestHistory);  // exactly ONE HTTP call
    }
}
