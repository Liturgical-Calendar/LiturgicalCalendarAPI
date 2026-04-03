<?php

namespace LiturgicalCalendar\Tests\Routes\ReadWrite;

use GuzzleHttp\Promise\EachPromise;
use LiturgicalCalendar\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;

#[Group('ReadWrite')]
class RegionalDataTest extends ApiTestCase
{
    private static string $existingBody = <<<JSON
{
    "litcal": [
        {
            "liturgical_event": {
                "event_key": "RemembranceDay",
                "day": 11,
                "month": 11,
                "color": [
                    "white"
                ],
                "grade": 3,
                "common": []
            },
            "metadata": {
                "action": "createNew",
                "since_year": 2016
            }
        }
    ],
    "settings": {
        "epiphany": "SUNDAY_JAN2_JAN8",
        "ascension": "SUNDAY",
        "corpus_christi": "SUNDAY",
        "eternal_high_priest": false
    },
    "metadata": {
        "nation": "CA",
        "locales": [
            "en_CA",
            "fr_CA"
        ],
        "wider_region": "Americas",
        "missals": [
            "CA_2011",
            "CA_2016"
        ]
    },
    "i18n": {
        "en_CA": {
            "RemembranceDay": "Remembrance Day"
        },
        "fr_CA": {
            "RemembranceDay": "Jour du Souvenir"
        }
    }
}
JSON;


    public function testGetOrPostWithoutPathParametersReturnsError(): void
    {
        $getResponse = self::$http->get('/data', []);
        $this->validateGetPostNoPathParametersErrorResponse($getResponse);
        $postResponse = self::$http->post('/data', []);
        $this->validateGetPostNoPathParametersErrorResponse($postResponse);
    }

    public function testRequestWithUnacceptableHeaderReturnsError(): void
    {
        $getResponse = self::$http->get('/data/nation/IT', [
            'headers' => ['Accept' => 'application/xml']
        ]);
        $this->assertSame(406, $getResponse->getStatusCode(), 'Expected HTTP 406 Not Acceptable');
    }

    public function testPutOrPatchOrDeleteWithoutPathParametersReturnsError(): void
    {
        // Note: These requests return 401 Unauthorized because JWT authentication is required
        // for PUT/PATCH/DELETE operations. Without authentication, the request doesn't reach
        // the path parameter validation logic that would return 400 Bad Request.
        $putResponse = self::$http->put('/data', []);
        $this->assertSame(401, $putResponse->getStatusCode(), 'Expected HTTP 401 Unauthorized (authentication required for PUT)');

        $patchResponse = self::$http->patch('/data', []);
        $this->assertSame(401, $patchResponse->getStatusCode(), 'Expected HTTP 401 Unauthorized (authentication required for PATCH)');

        $deleteResponse = self::$http->delete('/data', []);
        $this->assertSame(401, $deleteResponse->getStatusCode(), 'Expected HTTP 401 Unauthorized (authentication required for DELETE)');
    }

    #[Group('slow')]
    public function testGetOrPostOrPatchOrDeleteWithoutKeyParameterInPathReturnsError(): void
    {
        $requests = [
            [ 'uri' => '/data/nation/', 'method' => 'GET' ],
            [ 'uri' => '/data/nation/', 'method' => 'POST' ],
            [ 'uri' => '/data/nation/', 'method' => 'PATCH' ],
            [ 'uri' => '/data/nation/', 'method' => 'DELETE' ],
            [ 'uri' => '/data/diocese/', 'method' => 'GET' ],
            [ 'uri' => '/data/diocese/', 'method' => 'POST' ],
            [ 'uri' => '/data/diocese/', 'method' => 'PATCH' ],
            [ 'uri' => '/data/diocese/', 'method' => 'DELETE' ],
        ];

        $responses = [];
        $errors    = [];

        $each = new EachPromise(
            ( function () use ($requests, &$responses, &$errors) {
                foreach ($requests as $idx => $request) {
                    yield self::$http
                        ->requestAsync($request['method'], $request['uri'], [
                            'http_errors' => false
                        ])
                        ->then(
                            function (ResponseInterface $response) use ($idx, $request, &$responses) {
                                $responses[$idx] = $response;
                                // PATCH and DELETE require authentication and return 401
                                // GET and POST don't require authentication and return 400 for invalid parameters
                                $expectedCode = in_array($request['method'], ['PATCH', 'DELETE'], true) ? 401 : 400;
                                if ($response->getStatusCode() !== $expectedCode) {
                                    throw new \RuntimeException(
                                        "Expected HTTP $expectedCode for {$request['method']} {$request['uri']}, got {$response->getStatusCode()}"
                                    );
                                }
                            },
                            function ($reason) use ($idx, &$errors) {
                                $errors[$idx] = $reason instanceof \Throwable
                                    ? $reason
                                    : new \RuntimeException((string) $reason);
                            }
                        );
                }
            } )(),
            [ 'concurrency' => 6 ]
        );

        $each->promise()->wait();

        // Fail if we had transport-level errors
        $this->assertEmpty($errors, 'Encountered transport-level errors: ' . implode('; ', array_map(
            function ($e) {
                return $e instanceof \Throwable ? $e->getMessage() : (string) $e;
            },
            $errors
        )));

        $this->assertCount(count($requests), $responses, 'Some requests did not complete successfully: expected ' . count($requests) . ', received ' . count($responses));

        foreach ($responses as $idx => $response) {
            $request = $requests[$idx];
            // PATCH and DELETE require authentication and return 401
            // GET and POST don't require authentication and return 400 for invalid parameters
            $expectedCode = in_array($request['method'], ['PATCH', 'DELETE'], true) ? 401 : 400;
            $this->assertSame(
                $expectedCode,
                $response->getStatusCode(),
                "Expected HTTP $expectedCode for {$request['method']} {$request['uri']}, got {$response->getStatusCode()}"
            );
            // For GET and POST, validate the error detail message
            // For PATCH and DELETE, we get 401 before the handler logic
            if (in_array($request['method'], ['GET', 'POST'], true)) {
                $this->validateGetPostNationalOrDiocesanCalendarDataNoIdentifierErrorResponse($response);
            }
        }
    }

    public function testPutOrPatchWithoutContentTypeHeaderReturnsError(): void
    {
        // Note: These requests return 401 Unauthorized because JWT authentication is required
        // for PUT/PATCH operations. Without authentication, the request doesn't reach the
        // Content-Type validation. To test Content-Type validation, authentication must be provided.
        $putResponse = self::$http->put('/data/nation', []);
        $this->assertSame(401, $putResponse->getStatusCode(), 'Expected HTTP 401 Unauthorized (authentication required for PUT)');
        $patchResponse = self::$http->patch('/data/nation/IT', []);
        $this->assertSame(401, $patchResponse->getStatusCode(), 'Expected HTTP 401 Unauthorized (authentication required for PATCH)');
    }


    public function testGetNationalCalendarDataReturnsJson(): void
    {
        $response = self::$http->get('/data/nation/CA', [
            'headers' => ['Accept-Language' => 'fr-CA']
        ]);
        $this->assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK');
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'), 'Content-Type should be application/json');
        $data = (string) $response->getBody();
        $this->assertJson($data);
        $json = json_decode($data);
        $this->assertIsObject($json);
    }

    public function testGetNationalCalendarI18nDataReturnsJson(): void
    {
        $response = self::$http->get('/data/nation/CA/fr_CA', []);
        $this->assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK');
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'), 'Content-Type should be application/json');
        $data = (string) $response->getBody();
        $this->assertJson($data);
        $json = json_decode($data);
        $this->assertIsObject($json);
    }

    public function testPutDataExistingCalendarReturnsError(): void
    {
        // Note: This request returns 401 Unauthorized because JWT authentication is required
        // for PUT operations. Without authentication, the request doesn't reach the handler
        // logic that would check for existing calendars (409 Conflict).
        $response = self::$http->put('/data/nation', [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => self::$existingBody
        ]);
        $this->assertSame(401, $response->getStatusCode(), 'Expected HTTP 401 Unauthorized (authentication required for PUT), instead got ' . $response->getBody());
    }

    public function testPatchCalendarDataIdMismatchReturnsError(): void
    {
        // Note: This request returns 401 Unauthorized because JWT authentication is required
        // for PATCH operations. Without authentication, the request doesn't reach the handler
        // logic that would detect the ID mismatch (422 Unprocessable Content).
        // Attempting to patch the national calendar of Italy with data for the national calendar of Canada
        $response = self::$http->patch('/data/nation/IT', [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => self::$existingBody
        ]);
        $this->assertSame(401, $response->getStatusCode(), 'Expected HTTP 401 Unauthorized (authentication required for PATCH), instead got ' . $response->getBody());
    }

    /**
     * Test PUT with authentication returns 409 Conflict for existing calendar.
     *
     * This tests the handler-level behavior that was previously bypassed by 401 auth checks.
     */
    public function testAuthenticatedPutDataExistingCalendarReturns409(): void
    {
        if (!self::isDatabaseConfigured()) {
            $this->markTestSkipped('Database not configured — authorization middleware requires database connection');
        }
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        $response = self::$http->put('/data/nation', [
            'headers' => array_merge(
                self::authHeaders($token),
                [ 'Content-Type' => 'application/json' ]
            ),
            'body'    => self::$existingBody
        ]);
        $this->assertSame(409, $response->getStatusCode(), 'Expected HTTP 409 Conflict for existing calendar, instead got ' . $response->getStatusCode() . ': ' . $response->getBody());
    }

    /**
     * Test PATCH with authentication returns 422 Unprocessable Content for ID mismatch.
     *
     * This tests the handler-level behavior that was previously bypassed by 401 auth checks.
     * Attempting to patch the national calendar of Italy with data for Canada.
     */
    public function testAuthenticatedPatchCalendarDataIdMismatchReturns422(): void
    {
        if (!self::isDatabaseConfigured()) {
            $this->markTestSkipped('Database not configured — authorization middleware requires database connection');
        }
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        // Attempting to patch Italy with Canada's data
        $response = self::$http->patch('/data/nation/IT', [
            'headers' => array_merge(
                self::authHeaders($token),
                [ 'Content-Type' => 'application/json' ]
            ),
            'body'    => self::$existingBody
        ]);
        $this->assertSame(422, $response->getStatusCode(), 'Expected HTTP 422 Unprocessable Content for ID mismatch, instead got ' . $response->getStatusCode() . ': ' . $response->getBody());
    }

    /**
     * Test PUT/PATCH with authentication but without Content-Type header returns 415.
     *
     * This tests the handler-level Content-Type validation that was previously bypassed by 401 auth checks.
     */
    public function testAuthenticatedPutPatchWithoutContentTypeReturns415(): void
    {
        if (!self::isDatabaseConfigured()) {
            $this->markTestSkipped('Database not configured — authorization middleware requires database connection');
        }
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        // PUT without Content-Type
        $putResponse = self::$http->put('/data/nation', [
            'headers' => self::authHeaders($token),
            'body'    => self::$existingBody
        ]);
        $this->assertSame(415, $putResponse->getStatusCode(), 'Expected HTTP 415 Unsupported Media Type for PUT without Content-Type, instead got ' . $putResponse->getStatusCode() . ': ' . $putResponse->getBody());

        // PATCH without Content-Type
        $patchResponse = self::$http->patch('/data/nation/IT', [
            'headers' => self::authHeaders($token),
            'body'    => self::$existingBody
        ]);
        $this->assertSame(415, $patchResponse->getStatusCode(), 'Expected HTTP 415 Unsupported Media Type for PATCH without Content-Type, instead got ' . $patchResponse->getStatusCode() . ': ' . $patchResponse->getBody());
    }

    /**
     * Test authenticated PUT/PATCH/DELETE without path parameters returns proper validation errors.
     *
     * This tests the handler-level path parameter validation that was previously bypassed by 401 auth checks.
     */
    public function testAuthenticatedWriteOperationsWithoutPathParametersReturnValidationErrors(): void
    {
        if (!self::isDatabaseConfigured()) {
            $this->markTestSkipped('Database not configured — authorization middleware requires database connection');
        }
        $token = self::getJwtToken();
        $this->assertNotNull($token, 'Failed to obtain JWT token for authenticated test');

        // PUT without path params should return 400 (expects one path param)
        $putResponse = self::$http->put('/data', [
            'headers' => array_merge(
                self::authHeaders($token),
                [ 'Content-Type' => 'application/json' ]
            ),
            'body'    => self::$existingBody
        ]);
        $this->assertSame(400, $putResponse->getStatusCode(), 'Expected HTTP 400 Bad Request for PUT without path params');
        $this->validatePutNoPathParametersErrorResponse($putResponse);

        // PATCH without path params should return 400 (expects two path params)
        $patchResponse = self::$http->patch('/data', [
            'headers' => array_merge(
                self::authHeaders($token),
                [ 'Content-Type' => 'application/json' ]
            ),
            'body'    => self::$existingBody
        ]);
        $this->assertSame(400, $patchResponse->getStatusCode(), 'Expected HTTP 400 Bad Request for PATCH without path params');
        $this->validatePatchDeleteNoPathParametersErrorResponse($patchResponse);

        // DELETE without path params should return 400 (expects two path params)
        $deleteResponse = self::$http->delete('/data', [
            'headers' => self::authHeaders($token)
        ]);
        $this->assertSame(400, $deleteResponse->getStatusCode(), 'Expected HTTP 400 Bad Request for DELETE without path params');
        $this->validatePatchDeleteNoPathParametersErrorResponse($deleteResponse);
    }

    public function deleteCalendarDataNationStillHeldByDiocesanCalendarsReturnsError(\Psr\Http\Message\ResponseInterface $response): void
    {
        $response = self::$http->delete('/data/nation/CA', []);
        $this->assertSame(422, $response->getStatusCode(), 'Expected HTTP 422 Unprocessable Content, instead got ' . $response->getBody());
    }

    public function deleteWiderRegionDataStillHeldByNationalCalendarsReturnsError(\Psr\Http\Message\ResponseInterface $response): void
    {
        $response = self::$http->delete('/data/wider_region/Americas', []);
        $this->assertSame(422, $response->getStatusCode(), 'Expected HTTP 422 Unprocessable Content, instead got ' . $response->getBody());
    }

    private function validateRequestNoPathParametersErrorResponse(\Psr\Http\Message\ResponseInterface $response, string $content_type = 'application/problem+json'): string
    {
        $this->assertSame(400, $response->getStatusCode(), 'Expected HTTP 400 Bad Request');
        $this->assertStringStartsWith($content_type, $response->getHeaderLine('Content-Type'), "Content-Type should be $content_type");
        $data = (string) $response->getBody();
        $this->assertJson($data);
        $json = json_decode($data);
        $this->assertIsObject($json);
        $this->assertObjectHasProperty('type', $json);
        $this->assertObjectHasProperty('title', $json);
        $this->assertObjectHasProperty('status', $json);
        $this->assertObjectHasProperty('detail', $json);
        $this->assertSame(400, $json->status);
        return $json->detail;
    }

    private function validateGetPostNoPathParametersErrorResponse(\Psr\Http\Message\ResponseInterface $response, string $content_type = 'application/problem+json'): void
    {
        $description = $this->validateRequestNoPathParametersErrorResponse($response, $content_type);
        $this->assertSame('Expected at least two and at most three path params for GET and POST requests, received 0', $description);
    }

    private function validateGetPostNationalOrDiocesanCalendarDataNoIdentifierErrorResponse(\Psr\Http\Message\ResponseInterface $response, string $content_type = 'application/problem+json'): void
    {
        $description = $this->validateRequestNoPathParametersErrorResponse($response, $content_type);
        $this->assertSame('Expected at least two and at most three path params for GET and POST requests, received 1', $description);
    }

    private function validatePutNoPathParametersErrorResponse(\Psr\Http\Message\ResponseInterface $response, string $content_type = 'application/problem+json'): void
    {
        $description = $this->validateRequestNoPathParametersErrorResponse($response, $content_type);
        $this->assertSame('Expected one path param for PUT requests, received 0', $description);
    }

    private function validatePatchDeleteNoPathParametersErrorResponse(\Psr\Http\Message\ResponseInterface $response, string $content_type = 'application/problem+json'): void
    {
        $description = $this->validateRequestNoPathParametersErrorResponse($response, $content_type);
        $this->assertSame('Expected two path params for PATCH and DELETE requests, received 0', $description);
    }
}
