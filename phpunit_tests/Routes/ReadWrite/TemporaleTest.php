<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\ReadWrite;

use LiturgicalCalendar\Tests\ApiTestCase;

/**
 * Integration tests for authenticated write operations on the /temporale API endpoint.
 *
 * These tests verify JWT authentication and validation for PUT, PATCH, and DELETE operations.
 * To avoid modifying production data, tests focus on validation errors and non-existent resources.
 *
 * @group slow
 */
final class TemporaleTest extends ApiTestCase
{
    /**
     * Test that unauthenticated DELETE returns 401.
     */
    public function testDeleteTemporaleWithoutAuthReturns401(): void
    {
        $response = self::$http->delete('/temporale/Easter', [
            'http_errors' => false
        ]);
        $this->assertSame(
            401,
            $response->getStatusCode(),
            'DELETE without authentication should return 401 Unauthorized'
        );
    }

    /**
     * Test that unauthenticated PUT returns 401.
     */
    public function testPutTemporaleWithoutAuthReturns401(): void
    {
        $response = self::$http->put('/temporale', [
            'http_errors' => false,
            'json'        => []
        ]);
        $this->assertSame(
            401,
            $response->getStatusCode(),
            'PUT without authentication should return 401 Unauthorized'
        );
    }

    /**
     * Test that unauthenticated PATCH returns 401.
     */
    public function testPatchTemporaleWithoutAuthReturns401(): void
    {
        $response = self::$http->patch('/temporale', [
            'http_errors' => false,
            'json'        => []
        ]);
        $this->assertSame(
            401,
            $response->getStatusCode(),
            'PATCH without authentication should return 401 Unauthorized'
        );
    }

    /**
     * Test that authenticated PUT when data exists returns 409 Conflict.
     */
    public function testAuthenticatedPutWhenDataExistsReturns409(): void
    {
        if (!self::isDatabaseConfigured()) {
            $this->markTestSkipped('Database not configured — authorization middleware requires database connection');
        }
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        // Valid payload structure (unified per-event), but data already exists so should return 409
        $payload = [
            'locales' => ['en'],
            'events'  => [
                [
                    'event_key' => 'TestEvent',
                    'grade'     => 3,
                    'type'      => 'mobile',
                    'color'     => ['white'],
                    'i18n'      => [
                        'en' => 'Test Event Name'
                    ]
                ]
            ]
        ];

        $response = self::$http->put('/temporale', [
            'headers'     => array_merge(
                self::authHeaders($token),
                ['Content-Type' => 'application/json', 'Accept-Language' => 'en']
            ),
            'body'        => json_encode($payload),
            'http_errors' => false
        ]);

        $this->assertSame(
            409,
            $response->getStatusCode(),
            'PUT when temporale data already exists should return 409 Conflict'
        );
    }

    /**
     * Test that authenticated PATCH with invalid payload structure returns 400.
     */
    public function testAuthenticatedPatchWithInvalidPayloadReturns400(): void
    {
        if (!self::isDatabaseConfigured()) {
            $this->markTestSkipped('Database not configured — authorization middleware requires database connection');
        }
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        // Payload must be object with events property, not an array
        $response = self::$http->patch('/temporale', [
            'headers'     => array_merge(
                self::authHeaders($token),
                ['Content-Type' => 'application/json']
            ),
            'body'        => json_encode([['grade' => 3, 'type' => 'mobile', 'color' => ['white']]]),
            'http_errors' => false
        ]);

        $this->assertSame(
            400,
            $response->getStatusCode(),
            'PATCH with array instead of object should return 400'
        );
    }

    /**
     * Test that authenticated PATCH with event missing event_key returns 400.
     */
    public function testAuthenticatedPatchWithMissingEventKeyReturns400(): void
    {
        if (!self::isDatabaseConfigured()) {
            $this->markTestSkipped('Database not configured — authorization middleware requires database connection');
        }
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        // Event without event_key
        $payload = [
            'events' => [['grade' => 3, 'type' => 'mobile', 'color' => ['white']]]
        ];

        $response = self::$http->patch('/temporale', [
            'headers'     => array_merge(
                self::authHeaders($token),
                ['Content-Type' => 'application/json']
            ),
            'body'        => json_encode($payload),
            'http_errors' => false
        ]);

        $this->assertSame(
            400,
            $response->getStatusCode(),
            'PATCH with event missing event_key should return 400'
        );
    }

    /**
     * Test that authenticated PATCH with duplicate event_keys returns 400.
     */
    public function testAuthenticatedPatchWithDuplicateEventKeysReturns400(): void
    {
        if (!self::isDatabaseConfigured()) {
            $this->markTestSkipped('Database not configured — authorization middleware requires database connection');
        }
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        $duplicatePayload = [
            'events' => [
                [
                    'event_key' => 'DuplicateTest',
                    'grade'     => 3,
                    'type'      => 'mobile',
                    'color'     => ['white']
                ],
                [
                    'event_key' => 'DuplicateTest',
                    'grade'     => 4,
                    'type'      => 'fixed',
                    'color'     => ['red']
                ]
            ]
        ];

        $response = self::$http->patch('/temporale', [
            'headers'     => array_merge(
                self::authHeaders($token),
                ['Content-Type' => 'application/json']
            ),
            'body'        => json_encode($duplicatePayload),
            'http_errors' => false
        ]);

        $this->assertSame(
            400,
            $response->getStatusCode(),
            'PATCH with duplicate event_keys should return 400'
        );
    }

    /**
     * Test that authenticated DELETE for non-existent event returns 404.
     */
    public function testAuthenticatedDeleteNonExistentEventReturns404(): void
    {
        if (!self::isDatabaseConfigured()) {
            $this->markTestSkipped('Database not configured — authorization middleware requires database connection');
        }
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        $response = self::$http->delete('/temporale/NonExistentEventKey12345', [
            'headers'     => self::authHeaders($token),
            'http_errors' => false
        ]);

        $this->assertSame(
            404,
            $response->getStatusCode(),
            'DELETE for non-existent event_key should return 404'
        );
    }

    /**
     * Test that authenticated PUT/PATCH without Content-Type returns error.
     * Note: PUT checks for existing data first (409), but PATCH should still check Content-Type.
     */
    public function testAuthenticatedWriteWithoutContentTypeReturnsError(): void
    {
        if (!self::isDatabaseConfigured()) {
            $this->markTestSkipped('Database not configured — authorization middleware requires database connection');
        }
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        // PUT without Content-Type - will return 409 since data exists (checked before Content-Type)
        $putResponse = self::$http->put('/temporale', [
            'headers'     => self::authHeaders($token),
            'body'        => '{"locales":[],"events":[]}',
            'http_errors' => false
        ]);
        // Since data exists, 409 is returned before Content-Type check
        $this->assertSame(
            409,
            $putResponse->getStatusCode(),
            'PUT when data exists should return 409 (even without Content-Type)'
        );

        // PATCH without Content-Type
        $patchResponse = self::$http->patch('/temporale', [
            'headers'     => self::authHeaders($token),
            'body'        => '{"events":[]}',
            'http_errors' => false
        ]);
        $this->assertSame(
            415,
            $patchResponse->getStatusCode(),
            'PATCH without Content-Type should return 415'
        );
    }
}
