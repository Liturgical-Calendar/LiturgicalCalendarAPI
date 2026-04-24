<?php

namespace LiturgicalCalendar\Api\Tests\Services;

use LiturgicalCalendar\Api\Services\OpenFgaClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the OpenFgaClient service.
 *
 * Tests configuration and static methods without requiring a running OpenFGA server.
 * Integration tests with a live OpenFGA instance are covered by the Docker-based
 * end-to-end verification.
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

    public function testConstructorAcceptsParameters(): void
    {
        $client = new OpenFgaClient(
            'http://localhost:8083',
            'store-123',
            'model-456'
        );

        $this->assertInstanceOf(OpenFgaClient::class, $client);
    }

    public function testCheckThrowsOnConnectionFailure(): void
    {
        // Point to a non-existent server
        $client = new OpenFgaClient(
            'http://127.0.0.1:19999',
            'store-id',
            'model-id'
        );

        $this->expectException(RuntimeException::class);
        $client->check('user:test', 'editor', 'national_calendar:IT');
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
}
