<?php

namespace LiturgicalCalendar\Tests\Routes\ReadWrite;

use LiturgicalCalendar\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Route-shape assertions for the /missals write paths.
 *
 * The missals write handlers are still "Not yet implemented" stubs, but the
 * route shape is already aligned with the PUT /{collection}/{id} convention
 * (see issue #706): PUT is routed at /missals/{missal_id}, and the retired
 * collection-level PUT /missals is no longer an allowed method. These tests
 * pin that shape so a Router change cannot silently resurrect the old form.
 */
#[Group('ReadWrite')]
class MissalsTest extends ApiTestCase
{
    public function testPutWithoutAuthenticationIsUnauthorized(): void
    {
        // Authentication middleware runs before method validation for write requests.
        $response = self::$http->put('/missals/EDITIO_TYPICA_1970', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => '{}'
        ]);
        $this->assertSame(401, $response->getStatusCode(), 'Expected HTTP 401 Unauthorized (authentication required for PUT)');
    }

    public function testAuthenticatedPutAtCollectionLevelIsMethodNotAllowed(): void
    {
        if (!self::isDatabaseConfigured()) {
            $this->markTestSkipped('Database not configured — authorization middleware requires database connection');
        }
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        // The retired create shape: PUT /missals (id in body) must no longer be routed.
        $response = self::$http->put('/missals', [
            'headers' => array_merge(
                self::authHeaders($token),
                ['Content-Type' => 'application/json']
            ),
            'body'    => '{}'
        ]);
        $this->assertSame(405, $response->getStatusCode(), 'Expected HTTP 405 Method Not Allowed for collection-level PUT /missals, instead got ' . $response->getStatusCode() . ': ' . $response->getBody());
    }

    public function testAuthenticatedPutAtItemLevelReachesUnimplementedStub(): void
    {
        if (!self::isDatabaseConfigured()) {
            $this->markTestSkipped('Database not configured — authorization middleware requires database connection');
        }
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        // PUT /missals/{missal_id} is routed, but the write handler is still a
        // 405 "Not yet implemented" stub; the future implementation debuts here.
        $response = self::$http->put('/missals/EDITIO_TYPICA_1970', [
            'headers' => array_merge(
                self::authHeaders($token),
                ['Content-Type' => 'application/json']
            ),
            'body'    => '{}'
        ]);
        $this->assertSame(405, $response->getStatusCode(), 'Expected HTTP 405 Not yet implemented for PUT /missals/{missal_id}, instead got ' . $response->getStatusCode() . ': ' . $response->getBody());
        $this->assertStringContainsString('Not yet implemented', (string) $response->getBody(), 'Expected the item-level PUT to reach the unimplemented-stub handler');
    }
}
