<?php

namespace LiturgicalCalendar\Tests\Routes\ReadWrite;

use LiturgicalCalendar\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('ReadWrite')]
class RegionalDataTest extends ApiTestCase
{
    public function testGetOrPostWithoutPathParametersReturnsError(): void
    {
        $getResponse = $this->http->get('/data');
        $this->validateGetPostNoPathParametersErrorResponse($getResponse);
        $postResponse = $this->http->post('/data');
        $this->validateGetPostNoPathParametersErrorResponse($postResponse);
    }

    public function testRequestWithUnacceptableHeaderReturnsError(): void
    {
        $getResponse = $this->http->get('/data/nation/IT', [
            'headers' => ['Accept' => 'application/xml']
        ]);
        $this->assertSame(406, $getResponse->getStatusCode(), 'Expected HTTP 406 Not Acceptable');
    }

    public function testPutOrPatchOrDeleteWithoutPathParametersReturnsError(): void
    {
        $putResponse = $this->http->put('/data');
        $this->validatePutNoPathParametersErrorResponse($putResponse);

        $patchResponse = $this->http->patch('/data');
        $this->validatePatchDeleteNoPathParametersErrorResponse($patchResponse);
        $deleteResponse = $this->http->delete('/data');
        $this->validatePatchDeleteNoPathParametersErrorResponse($deleteResponse);
    }

    public function testGetOrPostOrPatchOrDeleteWithoutKeyParameterInPathReturnsError(): void
    {
        $getResponse = $this->http->get('/data/nation');
        $this->assertSame(400, $getResponse->getStatusCode(), 'Expected HTTP 400 Bad Request');
        $this->validateGetPostNationalOrDiocesanCalendarDataNoIdentifierErrorResponse($getResponse);

        $postResponse = $this->http->post('/data/nation');
        $this->assertSame(400, $postResponse->getStatusCode(), 'Expected HTTP 400 Bad Request');
        $this->validateGetPostNationalOrDiocesanCalendarDataNoIdentifierErrorResponse($postResponse);

        $patchResponse = $this->http->patch('/data/nation');
        $this->assertSame(400, $patchResponse->getStatusCode(), 'Expected HTTP 400 Bad Request');
        $this->validatePatchDeleteNationalOrDiocesanCalendarDataNoIdentifierErrorResponse($patchResponse);

        $deleteResponse = $this->http->delete('/data/nation');
        $this->assertSame(400, $deleteResponse->getStatusCode(), 'Expected HTTP 400 Bad Request');
        $this->validatePatchDeleteNationalOrDiocesanCalendarDataNoIdentifierErrorResponse($deleteResponse);

        $getResponse = $this->http->get('/data/diocese');
        $this->assertSame(400, $getResponse->getStatusCode(), 'Expected HTTP 400 Bad Request');
        $this->validateGetPostNationalOrDiocesanCalendarDataNoIdentifierErrorResponse($getResponse);

        $postResponse = $this->http->post('/data/diocese');
        $this->assertSame(400, $postResponse->getStatusCode(), 'Expected HTTP 400 Bad Request');
        $this->validateGetPostNationalOrDiocesanCalendarDataNoIdentifierErrorResponse($postResponse);

        $patchResponse = $this->http->patch('/data/diocese');
        $this->assertSame(400, $patchResponse->getStatusCode(), 'Expected HTTP 400 Bad Request');
        $this->validatePatchDeleteNationalOrDiocesanCalendarDataNoIdentifierErrorResponse($patchResponse);

        $deleteResponse = $this->http->delete('/data/diocese');
        $this->assertSame(400, $deleteResponse->getStatusCode(), 'Expected HTTP 400 Bad Request');
        $this->validatePatchDeleteNationalOrDiocesanCalendarDataNoIdentifierErrorResponse($deleteResponse);
    }

    public function testPutOrPatchWithoutContentTypeHeaderReturnsError(): void
    {
        $putResponse = $this->http->put('/data/nation');
        $this->assertSame(415, $putResponse->getStatusCode(), 'Expected HTTP 415 Unsupported Media Type');
        $patchResponse = $this->http->patch('/data/nation/IT');
        $this->assertSame(415, $patchResponse->getStatusCode(), 'Expected HTTP 415 Unsupported Media Type');
    }

    /*
    public function testGetNationalCalendarDataReturnsJson(): void
    {
        $response = $this->http->get('/data');
        $this->assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK');
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'), 'Content-Type should be application/json');
        $data = (string) $response->getBody();
        $this->assertJson($data);
        $json = json_decode($data);
        $this->assertIsObject($json);
    }
    */

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
