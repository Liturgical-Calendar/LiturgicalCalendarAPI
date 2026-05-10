<?php

namespace LiturgicalCalendar\Tests\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
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
        $responseBody = json_encode([
            'tuples' => [
                ['key' => ['user' => 'user:test', 'relation' => 'editor', 'object' => 'national_calendar:IT']],
                ['key' => ['user' => 'user:test', 'relation' => 'viewer', 'object' => 'national_calendar:IT']],
            ],
        ]);
        $mock         = new MockHandler([
            new Response(200, [], $responseBody ?: ''),
        ]);
        $client       = $this->createClientWithMock($mock);

        $tuples = $client->readTuples('user:test', 'national_calendar:IT');

        $this->assertCount(2, $tuples);
        $this->assertEquals('editor', $tuples[0]['relation']);
        $this->assertEquals('viewer', $tuples[1]['relation']);
    }

    public function testReadTuplesReturnsEmptyArrayWhenNoTuples(): void
    {
        $mock   = new MockHandler([
            new Response(200, [], json_encode(['tuples' => []]) ?: ''),
        ]);
        $client = $this->createClientWithMock($mock);

        $tuples = $client->readTuples('user:test', 'national_calendar:IT');
        $this->assertCount(0, $tuples);
    }
}
