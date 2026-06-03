<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\Readonly;

use LiturgicalCalendar\Tests\ApiTestCase;

/**
 * Integration tests for the `/health` route — verifies that the Router
 * wires HealthHandler under the unauthenticated public path and that
 * the response shape monitoring tooling depends on stays intact.
 *
 * The handler's own contract is exercised by HealthHandlerTest (in-process
 * handle() against AbstractHandlerTestCase). This test complements that by
 * asserting the live HTTP path through the Router + rate-limit middleware
 * — the surface load balancers and uptime checks actually hit.
 */
final class HealthTest extends ApiTestCase
{
    public function testGetHealthReturnsJsonWithExpectedShape(): void
    {
        $response = self::$http->get('/health');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        /** @var array{status: string, database: string, openfga_outbox: array<string, mixed>} $body */
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('ok', $body['status']);
        // Either 'reachable' (PG up + configured) or 'not_configured' is
        // acceptable depending on the dev environment running the suite.
        $this->assertContains($body['database'], ['reachable', 'not_configured']);

        $this->assertArrayHasKey('openfga_outbox', $body);
        $outbox = $body['openfga_outbox'];
        $this->assertIsInt($outbox['pending']);
        $this->assertIsInt($outbox['retrying']);
        $this->assertIsInt($outbox['succeeded']);
        $this->assertIsInt($outbox['failed_terminal']);
        $this->assertIsInt($outbox['oldest_pending_age_seconds']);

        $this->assertArrayHasKey('consumer', $outbox);
        $this->assertArrayHasKey('redis_reachable', $outbox['consumer']);
        $this->assertIsBool($outbox['consumer']['redis_reachable']);
    }

    public function testNonGetVerbReturns405(): void
    {
        // POST is not allowed on /health; the handler restricts to GET via
        // allowedRequestMethods. Disable Guzzle's http_errors so we can
        // inspect the 4xx response directly instead of catching an
        // exception.
        $response = self::$http->request('POST', '/health', ['http_errors' => false]);
        $this->assertSame(405, $response->getStatusCode());
    }
}
