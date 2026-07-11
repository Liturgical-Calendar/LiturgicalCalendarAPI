<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\ReadWrite;

use LiturgicalCalendar\Tests\ApiTestCase;

/**
 * Integration tests for authenticated write operations on the /decrees API endpoint.
 *
 * These tests verify JWT authentication and validation for PUT, PATCH, and DELETE operations
 * on per-item /decrees/{decree_id} routes introduced in the decrees write-paths feature.
 *
 * To keep data files in their original state, the full-lifecycle test wraps mutating
 * steps in a try/finally block that issues a cleanup DELETE even on assertion failure.
 *
 * @group slow
 */
final class DecreesTest extends ApiTestCase
{
    /**
     * Build a valid createNew payload for an arbitrary decree_id.
     *
     * Adapted verbatim from DecreesHandlerWriteTest::createNewPayload() so that
     * the route-level tests exercise the same schema path as the handler-level tests.
     *
     * @param string $decreeId
     * @return array<string,mixed>
     */
    private static function createNewPayload(string $decreeId = 'StZzTest_Create'): array
    {
        return [
            'decree_id'        => $decreeId,
            'decree_date'      => '2025-01-01',
            'decree_protocol'  => 'Prot. N. 1/25',
            'description'      => 'Test decree creating a new liturgical event.',
            'liturgical_event' => [
                'event_key' => 'StZzTest',
                'day'       => 14,
                'month'     => 2,
                'color'     => ['white'],
                'grade'     => 2,
                'common'    => ['Pastors'],
                'type'      => 'fixed',
                'calendar'  => 'GENERAL ROMAN',
            ],
            'metadata'         => [
                'action'     => 'createNew',
                'since_year' => 2025,
                'url'        => 'https://www.vatican.va/roman_curia/congregations/ccdds/documents/test.html',
            ],
            'i18n'             => ['en' => 'Saint Zz Test'],
            'readings'         => [
                'en' => [
                    'first_reading'      => 'Genesis 1:1',
                    'responsorial_psalm' => 'Psalm 1',
                    'gospel_acclamation' => 'John 1:1',
                    'gospel'             => 'John 1:1-14',
                ],
            ],
        ];
    }

    /**
     * Test that unauthenticated PUT returns 401.
     */
    public function testPutWithoutAuthReturns401(): void
    {
        $response = self::$http->request('PUT', '/decrees/StZzTest_Create', [
            'json'        => ['decree_id' => 'StZzTest_Create'],
            'http_errors' => false,
        ]);
        $this->assertSame(
            401,
            $response->getStatusCode(),
            'PUT without authentication should return 401 Unauthorized'
        );
    }

    /**
     * Test that unauthenticated DELETE returns 401.
     */
    public function testDeleteWithoutAuthReturns401(): void
    {
        $response = self::$http->request('DELETE', '/decrees/StZzTest_Create', ['http_errors' => false]);
        $this->assertSame(
            401,
            $response->getStatusCode(),
            'DELETE without authentication should return 401 Unauthorized'
        );
    }

    /**
     * Test that an authenticated PUT on an already-shipped decree_id returns 409.
     */
    public function testAuthenticatedPutOnExistingDecreeReturns409(): void
    {
        if (!self::isDatabaseConfigured()) {
            $this->markTestSkipped('Database not configured — authorization middleware requires database connection');
        }
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        // StMaryMagdalene_Upgrade ships with the API, so a PUT on it must conflict.
        $payload = self::createNewPayload('StMaryMagdalene_Upgrade');

        $response = self::$http->request('PUT', '/decrees/StMaryMagdalene_Upgrade', [
            'headers'     => array_merge(
                self::authHeaders($token),
                ['Content-Type' => 'application/json', 'Accept-Language' => 'en']
            ),
            'body'        => json_encode($payload),
            'http_errors' => false,
        ]);

        $this->assertSame(
            409,
            $response->getStatusCode(),
            'Authenticated PUT on existing decree_id should return 409 Conflict'
        );
    }

    /**
     * Test that an authenticated PUT with a schema-invalid body returns 400.
     */
    public function testAuthenticatedPutWithInvalidPayloadReturns400(): void
    {
        if (!self::isDatabaseConfigured()) {
            $this->markTestSkipped('Database not configured — authorization middleware requires database connection');
        }
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        // Body contains only decree_id — all required fields (metadata, liturgical_event, …) are absent.
        $response = self::$http->request('PUT', '/decrees/StZzTest_Create', [
            'headers'     => array_merge(
                self::authHeaders($token),
                ['Content-Type' => 'application/json', 'Accept-Language' => 'en']
            ),
            'body'        => json_encode(['decree_id' => 'StZzTest_Create']),
            'http_errors' => false,
        ]);

        $this->assertSame(
            400,
            $response->getStatusCode(),
            'Authenticated PUT with schema-invalid body should return 400 Bad Request'
        );
    }

    /**
     * Test that an authenticated DELETE on an unknown decree_id returns 404.
     */
    public function testAuthenticatedDeleteUnknownDecreeReturns404(): void
    {
        if (!self::isDatabaseConfigured()) {
            $this->markTestSkipped('Database not configured — authorization middleware requires database connection');
        }
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        $response = self::$http->request('DELETE', '/decrees/Nonexistent_Create', [
            'headers'     => self::authHeaders($token),
            'http_errors' => false,
        ]);

        $this->assertSame(
            404,
            $response->getStatusCode(),
            'Authenticated DELETE on unknown decree_id should return 404 Not Found'
        );
    }

    /**
     * Full lifecycle: PUT (201) → PATCH (200) → DELETE (200) for a synthetic decree_id.
     *
     * Steps 2 and 3 are wrapped in try/finally so the cleanup DELETE always runs and
     * the data files are returned to their original state even if an assertion fails.
     */
    public function testFullLifecycleCreatePatchDelete(): void
    {
        if (!self::isDatabaseConfigured()) {
            $this->markTestSkipped('Database not configured — authorization middleware requires database connection');
        }
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        $decreeId    = 'StZzTest_Create';
        $authHeaders = array_merge(
            self::authHeaders($token),
            ['Content-Type' => 'application/json', 'Accept-Language' => 'en']
        );

        // Step 1: PUT — create the new decree (expect 201 Created)
        $createPayload = self::createNewPayload($decreeId);
        $putResponse   = self::$http->request('PUT', "/decrees/{$decreeId}", [
            'headers'     => $authHeaders,
            'body'        => json_encode($createPayload),
            'http_errors' => false,
        ]);
        $this->assertSame(
            201,
            $putResponse->getStatusCode(),
            "PUT /decrees/{$decreeId} should return 201 Created"
        );

        // Steps 2 and 3 are inside try/finally to guarantee cleanup.
        try {
            // Step 2: PATCH — amend the description (expect 200 OK)
            $patchPayload                = self::createNewPayload($decreeId);
            $patchPayload['description'] = 'Amended description for integration test.';
            $patchPayload['i18n']        = ['en' => 'Saint Zz Test, Amended'];
            unset($patchPayload['readings']); // readings are optional on PATCH

            $patchResponse = self::$http->request('PATCH', "/decrees/{$decreeId}", [
                'headers'     => $authHeaders,
                'body'        => json_encode($patchPayload),
                'http_errors' => false,
            ]);
            $this->assertSame(
                200,
                $patchResponse->getStatusCode(),
                "PATCH /decrees/{$decreeId} should return 200 OK"
            );
        } finally {
            // Step 3: DELETE — remove the test decree (expect 200 OK)
            $deleteResponse = self::$http->request('DELETE', "/decrees/{$decreeId}", [
                'headers'     => self::authHeaders($token),
                'http_errors' => false,
            ]);
            $this->assertSame(
                200,
                $deleteResponse->getStatusCode(),
                "DELETE /decrees/{$decreeId} should return 200 OK"
            );
        }
    }
}
