<?php

namespace LiturgicalCalendar\Tests\Routes\ReadWrite;

use LiturgicalCalendar\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;
use GuzzleHttp\Promise\EachPromise;

#[Group('ReadWrite')]
class RegionalDataTest extends ApiTestCase
{
    private static string $body = <<<JSON
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
        $getResponse = self::$http->get('/data');
        $this->validateGetPostNoPathParametersErrorResponse($getResponse);
        $postResponse = self::$http->post('/data');
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
        $putResponse = self::$http->put('/data');
        $this->validatePutNoPathParametersErrorResponse($putResponse);

        $patchResponse = self::$http->patch('/data');
        $this->validatePatchDeleteNoPathParametersErrorResponse($patchResponse);
        $deleteResponse = self::$http->delete('/data');
        $this->validatePatchDeleteNoPathParametersErrorResponse($deleteResponse);
    }

    #[Group('slow')]
    public function testGetOrPostOrPatchOrDeleteWithoutKeyParameterInPathReturnsError(): void
    {
        $requests = [
            [ 'uri' => '/data/nation/', 'method' => 'get' ],
            [ 'uri' => '/data/nation/', 'method' => 'post' ],
            [ 'uri' => '/data/nation/', 'method' => 'patch' ],
            [ 'uri' => '/data/nation/', 'method' => 'delete' ],
            [ 'uri' => '/data/diocese/', 'method' => 'get' ],
            [ 'uri' => '/data/diocese/', 'method' => 'post' ],
            [ 'uri' => '/data/diocese/', 'method' => 'patch' ],
            [ 'uri' => '/data/diocese/', 'method' => 'delete' ],
        ];

        $failures = [];

        $each = new EachPromise(
            ( function () use ($requests) {
                foreach ($requests as $request) {
                    yield self::$http->requestAsync($request['method'], $request['uri'])
                        ->then(
                            function ($response) use ($request) {
                                $this->assertSame(400, $response->getStatusCode(), 'Expected HTTP 400 Bad Request');
                                $this->validateGetPostNationalOrDiocesanCalendarDataNoIdentifierErrorResponse($response);
                            },
                            function ($exception) use ($request, &$failures) {
                                // Capture failure
                                $failures[] = "Request with method {$request['method']} failed for {$_ENV['API_PROTOCOL']}://{$_ENV['API_HOST']}:{$_ENV['API_PORT']}{$request['uri']}: {$exception->getMessage()}";
                            }
                        );
                }
            } )(),
            [ 'concurrency' => 6 ] // <= number of PHP built-in server workers
        );

        $each->promise()->wait();

        if ($failures) {
            $this->fail(implode("\n", $failures));
        }
    }

    public function testPutOrPatchWithoutContentTypeHeaderReturnsError(): void
    {
        $putResponse = self::$http->put('/data/nation');
        $this->assertSame(415, $putResponse->getStatusCode(), 'Expected HTTP 415 Unsupported Media Type');
        $patchResponse = self::$http->patch('/data/nation/IT');
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

    public function testPutDataExistingCalendarReturnsError(): void
    {
        $response = self::$http->put('/data/nation', [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => self::$body
        ]);
        $this->assertSame(409, $response->getStatusCode(), 'Expected HTTP 409 Conflict, instead got ' . $response->getBody());
    }

    public function testPatchCalendarDataIdMismatchReturnsError(): void
    {
        // Attempting to patch the national calendar of Italy with data for the national calendar of Canada
        $response = self::$http->patch('/data/nation/IT', [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => self::$body
        ]);
        $this->assertSame(422, $response->getStatusCode(), 'Expected HTTP 422 Unprocessable Content, instead got ' . $response->getBody());
    }

    public function deleteCalendarDataNationStillHeldByDiocesanCalendarsReturnsError(\Psr\Http\Message\ResponseInterface $response): void
    {
        $response = self::$http->delete('/data/nation/CA');
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
