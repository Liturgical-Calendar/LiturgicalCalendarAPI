<?php

namespace LiturgicalCalendar\Tests\Routes\ReadWrite;

use GuzzleHttp\Promise\EachPromise;
use GuzzleHttp\Promise\PromiseInterface;
use LiturgicalCalendar\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\AssertionFailedError;
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
        $putResponse = self::$http->put('/data', []);
        $this->validatePutNoPathParametersErrorResponse($putResponse);

        $patchResponse = self::$http->patch('/data', []);
        $this->validatePatchDeleteNoPathParametersErrorResponse($patchResponse);

        $deleteResponse = self::$http->delete('/data', []);
        $this->validatePatchDeleteNoPathParametersErrorResponse($deleteResponse);
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
                                if ($response->getStatusCode() !== 400) {
                                    throw new \RuntimeException(
                                        "Expected HTTP 400 for {$request['method']} {$request['uri']}, got {$response->getStatusCode()}"
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
            $this->assertSame(
                400,
                $response->getStatusCode(),
                "Expected HTTP 400 for {$request['method']} {$request['uri']}, got {$response->getStatusCode()}"
            );
            // method-aware detail check
            if (in_array($request['method'], ['PATCH', 'DELETE'], true)) {
                $this->validatePatchDeleteNationalOrDiocesanCalendarDataNoIdentifierErrorResponse($response);
            } else {
                $this->validateGetPostNationalOrDiocesanCalendarDataNoIdentifierErrorResponse($response);
            }
        }
    }

    public function testPutOrPatchWithoutContentTypeHeaderReturnsError(): void
    {
        $putResponse = self::$http->put('/data/nation', []);
        $this->assertSame(415, $putResponse->getStatusCode(), 'Expected HTTP 415 Unsupported Media Type');
        $patchResponse = self::$http->patch('/data/nation/IT', []);
        $this->assertSame(415, $patchResponse->getStatusCode(), 'Expected HTTP 415 Unsupported Media Type');
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
        $response = self::$http->put('/data/nation', [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => self::$existingBody
        ]);
        $this->assertSame(409, $response->getStatusCode(), 'Expected HTTP 409 Conflict, instead got ' . $response->getBody());
    }

    public function testPatchCalendarDataIdMismatchReturnsError(): void
    {
        // Attempting to patch the national calendar of Italy with data for the national calendar of Canada
        $response = self::$http->patch('/data/nation/IT', [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => self::$existingBody
        ]);
        $this->assertSame(422, $response->getStatusCode(), 'Expected HTTP 422 Unprocessable Content, instead got ' . $response->getBody());
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

    private function validatePatchDeleteNationalOrDiocesanCalendarDataNoIdentifierErrorResponse(\Psr\Http\Message\ResponseInterface $response, string $content_type = 'application/problem+json'): void
    {
        $description = $this->validateRequestNoPathParametersErrorResponse($response, $content_type);
        $this->assertSame('Expected two path params for PATCH and DELETE requests, received 1', $description);
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
