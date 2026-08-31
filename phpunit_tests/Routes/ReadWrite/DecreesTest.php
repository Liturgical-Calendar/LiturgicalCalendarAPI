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
 * That cleanup is best-effort, and the mutation happens in the *server* process against
 * whatever checkout that server was started from — so it is additionally gated on the
 * served tree being disposable; see skipUnlessTheServedTreeIsDisposable().
 *
 * @group slow
 */
final class DecreesTest extends ApiTestCase
{
    /**
     * The local dev server may be a stale build without the decrees write
     * paths (returns 405). In CI the server runs this branch, so a 405 is
     * a real regression there and must fail loudly.
     *
     * @param \Psr\Http\Message\ResponseInterface $response
     * @return void
     */
    private function skipIfStaleServer(\Psr\Http\Message\ResponseInterface $response): void
    {
        if ($response->getStatusCode() === 405 && getenv('CI') === false) {
            $this->markTestSkipped('Server predates decrees write paths (stale local build); exercised in CI.');
        }
    }

    /**
     * Refuse to mutate source data in a tree that is not disposable (#945).
     *
     * The full-lifecycle test drives `PUT` -> `PATCH` -> `DELETE` against the **live** server, so
     * the writes happen in the server process, against whatever checkout that server was started
     * from. That puts it out of reach of the `Router::$apiFilePath` seam by construction:
     * `ShadowProjectRootTrait` repoints the *test* process, which changes nothing here. And unlike
     * the in-process cases #921 and #935 fixed, an interruption here leaves `decrees.json`
     * **modified** rather than deleted — the harder state to notice, because a modified decrees
     * corpus reorders under `ksort` and a `jq -S` comparison is blind to it.
     *
     * CI is safe by construction: the workflow runs `composer start` in the job's own checkout,
     * which is thrown away afterwards, so the test always runs there and coverage is unaffected.
     * A developer machine is not: the server on the configured port typically serves a real working
     * tree — possibly one another agent or editor is using — and dirtying it is a cost the test has
     * no way to undo reliably.
     *
     * So the mutation is opt-in off CI. Set `API_TEST_SERVER_DISPOSABLE=true` once the server on
     * this port is known to serve a throwaway tree. This fails closed: the default is to skip, and
     * a developer who has not thought about it does not silently pay for it.
     */
    private function skipUnlessTheServedTreeIsDisposable(): void
    {
        if (getenv('CI') !== false || self::envFlagIsTrue('API_TEST_SERVER_DISPOSABLE')) {
            return;
        }

        $this->markTestSkipped(
            'This test PUTs, PATCHes and DELETEs against the live server, mutating jsondata/sourcedata '
            . 'in whatever checkout that server was started from — which this test process cannot '
            . 'redirect, and an interrupted run leaves modified rather than restored. '
            . 'Set API_TEST_SERVER_DISPOSABLE=true once the server serves a throwaway tree. '
            . 'Always runs in CI, where the checkout is disposable by construction. '
            . 'The same write path is covered in-process by Handlers/DecreesHandlerWriteTest, which '
            . 'uses ShadowProjectRootTrait.'
        );
    }

    /**
     * True when $name is set to "true" (case-insensitively) in the environment.
     *
     * Reads `getenv()` first so a shell export is honoured, then `$_ENV` — the phpunit bootstrap
     * loads `.env.local` with `Dotenv::createMutable()`, which populates both, but an inherited
     * variable reaches only one of them.
     */
    private static function envFlagIsTrue(string $name): bool
    {
        $value = getenv($name);
        if (false === $value) {
            $value = isset($_ENV[$name]) && is_string($_ENV[$name]) ? $_ENV[$name] : null;
        }

        return is_string($value) && 'true' === strtolower(trim($value));
    }

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

        // MaryMotherChurch_Create ships with the API, so a PUT on it must conflict.
        // (A _Create decree_id is required: the write payload schema binds the
        // decree_id suffix to metadata.action, and the fixture payload is createNew.)
        $payload = self::createNewPayload('MaryMotherChurch_Create');

        $response = self::$http->request('PUT', '/decrees/MaryMotherChurch_Create', [
            'headers'     => array_merge(
                self::authHeaders($token),
                ['Content-Type' => 'application/json', 'Accept-Language' => 'en']
            ),
            'body'        => json_encode($payload),
            'http_errors' => false,
        ]);
        $this->skipIfStaleServer($response);

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
        $this->skipIfStaleServer($response);

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
        $this->skipIfStaleServer($response);

        $this->assertSame(
            404,
            $response->getStatusCode(),
            'Authenticated DELETE on unknown decree_id should return 404 Not Found'
        );
    }

    /**
     * Full lifecycle: PUT (201) → PATCH (200) → DELETE (200) for a synthetic decree_id.
     *
     * The mutating steps (PUT and PATCH) are wrapped in try/finally so the cleanup DELETE
     * always runs and the data files are returned to their original state even if an
     * assertion fails after a partial creation.
     */
    public function testFullLifecycleCreatePatchDelete(): void
    {
        $this->skipUnlessTheServedTreeIsDisposable();
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

        // Steps 1 and 2 are inside try/finally so that the cleanup DELETE runs even when
        // the PUT assertion fails after a partial creation.
        try {
            // Step 1: PUT — create the new decree (expect 201 Created)
            $createPayload = self::createNewPayload($decreeId);
            $putResponse   = self::$http->request('PUT', "/decrees/{$decreeId}", [
                'headers'     => $authHeaders,
                'body'        => json_encode($createPayload),
                'http_errors' => false,
            ]);
            $this->skipIfStaleServer($putResponse);
            $this->assertSame(
                201,
                $putResponse->getStatusCode(),
                "PUT /decrees/{$decreeId} should return 201 Created"
            );

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
            // Step 3: DELETE — remove the test decree.
            // Accept 200 (normal) or 404 (decree was never persisted due to a mid-lifecycle
            // failure) so that the cleanup itself does not mask the real assertion above.
            $deleteResponse = self::$http->request('DELETE', "/decrees/{$decreeId}", [
                'headers'     => self::authHeaders($token),
                'http_errors' => false,
            ]);
            $deleteStatus   = $deleteResponse->getStatusCode();
            // Mirror skipIfStaleServer(): on a stale local build the PUT above raised a skip,
            // and the cleanup DELETE also gets a 405 — don't let that turn the skip into a failure.
            if ($deleteStatus !== 405 || getenv('CI') !== false) {
                $this->assertContains(
                    $deleteStatus,
                    [200, 404],
                    "Cleanup DELETE /decrees/{$decreeId} should return 200 OK or 404 Not Found"
                );
            }
        }
    }
}
