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
     * The key must be one the corpus does not already carry. `MISSAL_WRITE` is shared by PUT and
     * PATCH, so it cannot mark month/day required; a create with `{}` therefore passes schema
     * validation and is refused later, by buildRow()'s required-property check inside the lock.
     * The conflict check runs FIRST, so an existing key (an earlier draft of this test used
     * `StIsidore`, which propriumdesanctis_1970 declares) answers 409 and never reaches that guard.
     *
     * The load-bearing assertion is `assertNotSame(405, …)`: a 405 would mean the route is
     * unregistered or the method disallowed, which is exactly the regression that would leave
     * #943 shipped-but-dead. The 400 then pins WHICH refusal it is.
     */
    public function testAuthenticatedPutAtEntryLevelReachesTheWriteHandler(): void
    {
        if (!self::isDatabaseConfigured()) {
            $this->markTestSkipped('Database not configured — authorization middleware requires database connection');
        }
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        // Deliberately absent from every missal, i18n and lectionary file in the corpus, so the
        // create path runs past the conflict check. Nothing is written: the request is refused.
        $response = self::$http->put('/missals/EDITIO_TYPICA_1970/ZzzRouteProbe', [
            'headers' => array_merge(
                self::authHeaders($token),
                ['Content-Type' => 'application/json']
            ),
            'body'    => '{}'
        ]);
        $this->assertNotSame(405, $response->getStatusCode(), 'PUT /missals/{missal_id}/{event_key} must be routed and allowed, but the method was rejected: ' . $response->getBody());
        $this->assertSame(400, $response->getStatusCode(), 'Expected HTTP 400 for an incomplete create payload, instead got ' . $response->getStatusCode() . ': ' . $response->getBody());
        $this->assertStringContainsString('A sanctorale entry needs', (string) $response->getBody(), 'Expected buildRow()\'s required-property guard, not some other 400');
    }
}
