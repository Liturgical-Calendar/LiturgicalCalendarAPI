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

    /**
     * The missal level is read-only: a sanctorale write names the entry it writes.
     *
     * This previously reached a "Not yet implemented" stub, and the old test asserted that
     * string. #943 retired the stub rather than filling it in: an `event_key` carried only in
     * the body makes a rename inexpressible and therefore unrefusable, so the key became a path
     * segment and writes moved one level deeper. A plain 405 here is now the correct answer.
     */
    public function testAuthenticatedPutAtMissalLevelIsMethodNotAllowed(): void
    {
        if (!self::isDatabaseConfigured()) {
            $this->markTestSkipped('Database not configured — authorization middleware requires database connection');
        }
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        $response = self::$http->put('/missals/EDITIO_TYPICA_1970', [
            'headers' => array_merge(
                self::authHeaders($token),
                ['Content-Type' => 'application/json']
            ),
            'body'    => '{}'
        ]);
        $this->assertSame(405, $response->getStatusCode(), 'Expected HTTP 405 for PUT /missals/{missal_id}, instead got ' . $response->getStatusCode() . ': ' . $response->getBody());
        $this->assertStringNotContainsString('Not yet implemented', (string) $response->getBody(), 'The unimplemented stub was retired by #943; a lingering mention would mean the old route survived');
    }

    /**
     * The entry level accepts writes — asserted by what it does NOT answer.
     *
     * A `{}` body cannot pass schema validation, so 400 is the expected outcome. The point of
     * the assertion is that it is not 405: that would mean the route is unregistered or the
     * method disallowed, which is exactly the regression that would leave #943 shipped-but-dead.
     */
    public function testAuthenticatedPutAtEntryLevelReachesTheWriteHandler(): void
    {
        if (!self::isDatabaseConfigured()) {
            $this->markTestSkipped('Database not configured — authorization middleware requires database connection');
        }
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        $response = self::$http->put('/missals/EDITIO_TYPICA_1970/StIsidore', [
            'headers' => array_merge(
                self::authHeaders($token),
                ['Content-Type' => 'application/json']
            ),
            'body'    => '{}'
        ]);
        $this->assertNotSame(405, $response->getStatusCode(), 'PUT /missals/{missal_id}/{event_key} must be routed and allowed, but the method was rejected: ' . $response->getBody());
        $this->assertSame(400, $response->getStatusCode(), 'Expected HTTP 400 for an empty payload, instead got ' . $response->getStatusCode() . ': ' . $response->getBody());
    }
}
