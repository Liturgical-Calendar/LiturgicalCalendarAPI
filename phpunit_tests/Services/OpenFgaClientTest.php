<?php

namespace LiturgicalCalendar\Tests\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LiturgicalCalendar\Api\Services\Exception\OpenFgaApiException;
use LiturgicalCalendar\Api\Services\Exception\TupleAlreadyExistsException;
use LiturgicalCalendar\Api\Services\Exception\TupleNotFoundException;
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

    // --- Typed exceptions for benign write/delete errors (issue #567) ----

    public function testWriteTupleTranslatesDuplicateErrorByOpenFgaCode(): void
    {
        $mock   = new MockHandler([
            new Response(400, [], (string) json_encode([
                'code'    => 'cannot_allow_duplicate_tuple',
                'message' => 'cannot write duplicate tuple',
            ])),
        ]);
        $client = $this->createClientWithMock($mock);

        $this->expectException(TupleAlreadyExistsException::class);
        $client->writeTuple('user:test', 'editor', 'national_calendar:IT');
    }

    public function testWriteTupleTranslatesDuplicateErrorByMessageSubstring(): void
    {
        // Older OpenFGA versions return the generic input-error code and carry
        // the cause only in the message. The classifier should still spot it.
        $mock   = new MockHandler([
            new Response(400, [], (string) json_encode([
                'code'    => 'write_failed_due_to_invalid_input',
                'message' => 'tuple_key already exists',
            ])),
        ]);
        $client = $this->createClientWithMock($mock);

        $this->expectException(TupleAlreadyExistsException::class);
        $client->writeTuple('user:test', 'editor', 'national_calendar:IT');
    }

    public function testWriteTupleRethrowsNonBenignErrorsAsApiException(): void
    {
        $mock   = new MockHandler([
            new Response(400, [], (string) json_encode([
                'code'    => 'validation_error',
                'message' => 'invalid relation',
            ])),
        ]);
        $client = $this->createClientWithMock($mock);

        try {
            $client->writeTuple('user:test', 'editor', 'national_calendar:IT');
            $this->fail('Expected OpenFgaApiException');
        } catch (OpenFgaApiException $e) {
            // Should be the base class, not the benign subclass.
            $this->assertNotInstanceOf(TupleAlreadyExistsException::class, $e);
            $this->assertSame(400, $e->getHttpStatus());
            $this->assertSame('validation_error', $e->getErrorCode());
        }
    }

    public function testDeleteTupleTranslatesNotFoundErrorByOpenFgaCode(): void
    {
        $mock   = new MockHandler([
            new Response(400, [], (string) json_encode([
                'code'    => 'cannot_allow_unknown_tuple_to_be_deleted',
                'message' => 'cannot delete unknown tuple',
            ])),
        ]);
        $client = $this->createClientWithMock($mock);

        $this->expectException(TupleNotFoundException::class);
        $client->deleteTuple('user:test', 'editor', 'national_calendar:IT');
    }

    public function testDeleteTupleTranslatesNotFoundErrorByMessageSubstring(): void
    {
        $mock   = new MockHandler([
            new Response(400, [], (string) json_encode([
                'code'    => 'write_failed_due_to_invalid_input',
                'message' => 'tuple_key not found',
            ])),
        ]);
        $client = $this->createClientWithMock($mock);

        $this->expectException(TupleNotFoundException::class);
        $client->deleteTuple('user:test', 'editor', 'national_calendar:IT');
    }

    public function testDeleteTupleRethrowsNonBenignErrorsAsApiException(): void
    {
        $mock   = new MockHandler([
            new Response(500, [], (string) json_encode([
                'code'    => 'internal_error',
                'message' => 'internal server error',
            ])),
        ]);
        $client = $this->createClientWithMock($mock);

        try {
            $client->deleteTuple('user:test', 'editor', 'national_calendar:IT');
            $this->fail('Expected OpenFgaApiException');
        } catch (OpenFgaApiException $e) {
            $this->assertNotInstanceOf(TupleNotFoundException::class, $e);
            $this->assertSame(500, $e->getHttpStatus());
            $this->assertSame('internal_error', $e->getErrorCode());
        }
    }

    public function testOpenFgaApiExceptionCarriesResponseBody(): void
    {
        $mock   = new MockHandler([
            new Response(400, [], (string) json_encode([
                'code'    => 'validation_error',
                'message' => 'invalid',
                'detail'  => 'extra structured field',
            ])),
        ]);
        $client = $this->createClientWithMock($mock);

        try {
            $client->check('user:test', 'editor', 'national_calendar:IT');
            $this->fail('Expected OpenFgaApiException');
        } catch (OpenFgaApiException $e) {
            $body = $e->getResponseBody();
            $this->assertSame('validation_error', $body['code']);
            $this->assertSame('extra structured field', $body['detail']);
        }
    }

    public function testWriteTupleDoesNotMisclassifyCodelessResponseWithDuplicateInMessage(): void
    {
        // Regression guard: a 500 response with no `code` field whose
        // message happens to contain "duplicate" must surface as the base
        // OpenFgaApiException, NOT as the benign
        // TupleAlreadyExistsException. The substring fallback is reserved
        // for the documented legacy `write_failed_due_to_invalid_input`
        // code — unrelated errors stay fatal.
        $mock   = new MockHandler([
            new Response(500, [], (string) json_encode(['message' => 'database returned duplicate key error'])),
        ]);
        $client = $this->createClientWithMock($mock);

        try {
            $client->writeTuple('user:test', 'editor', 'national_calendar:IT');
            $this->fail('Expected base OpenFgaApiException');
        } catch (OpenFgaApiException $e) {
            $this->assertNotInstanceOf(TupleAlreadyExistsException::class, $e);
            $this->assertSame(500, $e->getHttpStatus());
            $this->assertNull($e->getErrorCode());
        }
    }

    public function testDeleteTupleDoesNotMisclassifyCodelessResponseWithNotFoundInMessage(): void
    {
        // Symmetric regression guard for the delete side.
        $mock   = new MockHandler([
            new Response(500, [], (string) json_encode(['message' => 'upstream service not found'])),
        ]);
        $client = $this->createClientWithMock($mock);

        try {
            $client->deleteTuple('user:test', 'editor', 'national_calendar:IT');
            $this->fail('Expected base OpenFgaApiException');
        } catch (OpenFgaApiException $e) {
            $this->assertNotInstanceOf(TupleNotFoundException::class, $e);
            $this->assertSame(500, $e->getHttpStatus());
            $this->assertNull($e->getErrorCode());
        }
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
