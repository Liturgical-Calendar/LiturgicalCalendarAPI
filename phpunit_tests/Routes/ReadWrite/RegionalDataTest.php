<?php

namespace LiturgicalCalendar\Tests\Routes\ReadWrite;

use LiturgicalCalendar\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('ReadWrite')]
class RegionalDataTest extends ApiTestCase
{

    public function testGetPostWithoutPathParametersReturnsError(): void
    {
        $getResponse = $this->http->get('/data');
        $this->validateGetPostNoPathParametersErrorResponse($getResponse);
        $postResponse = $this->http->post('/data');
        $this->validateGetPostNoPathParametersErrorResponse($postResponse);
    }

    public function testPutPatchDeleteWithoutAcceptHeaderErrorResponse(): void
    {
        $putResponse = $this->http->put('/data');
        $this->assertSame(406, $putResponse->getStatusCode(), 'Expected HTTP 406 Not Acceptable');
        $patchResponse = $this->http->patch('/data');
        $this->assertSame(406, $patchResponse->getStatusCode(), 'Expected HTTP 406 Not Acceptable');
        $deleteResponse = $this->http->delete('/data');
        $this->assertSame(406, $deleteResponse->getStatusCode(), 'Expected HTTP 406 Not Acceptable');
    }

    public function testPutPatchDeleteWithoutPathParametersReturnsError(): void
    {
        $putResponse = $this->http->put('/data', [
            'headers'=> ['Accept' => 'application/json']
        ]);
        $this->validatePutNoPathParametersErrorResponse($putResponse);

        $patchResponse = $this->http->patch('/data', [
            'headers'=> ['Accept' => 'application/json']
        ]);
        $this->validatePatchDeleteNoPathParametersErrorResponse($patchResponse);
        $deleteResponse = $this->http->delete('/data', [
            'headers'=> ['Accept' => 'application/json']
        ]);
        $this->validatePatchDeleteNoPathParametersErrorResponse($deleteResponse);
    }

    public function testGetNationalOrDiocesanCalendarDataWithoutIdentifierReturnsError(): void
    {
        $getResponse = $this->http->get('/data/nation');
        $this->assertSame(400, $getResponse->getStatusCode(), 'Expected HTTP 400 Bad Request');
        $this->validateGetPostNationalOrDiocesanCalendarDataNoIdentifierErrorResponse($getResponse);
        $postResponse = $this->http->post('/data/diocese');
        $this->assertSame(400, $postResponse->getStatusCode(), 'Expected HTTP 400 Bad Request');
        $this->validateGetPostNationalOrDiocesanCalendarDataNoIdentifierErrorResponse($postResponse);
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

    private function validateRequestNoPathParametersErrorResponse(\Psr\Http\Message\ResponseInterface $response, string $content_type = 'application/json'): string
    {
        $this->assertSame(400, $response->getStatusCode(), 'Expected HTTP 400 Bad Request');
        $this->assertStringStartsWith($content_type, $response->getHeaderLine('Content-Type'), "Content-Type should be $content_type");
        $data = (string) $response->getBody();
        $this->assertJson($data);
        $json = json_decode($data);
        $this->assertIsObject($json);
        $this->assertObjectHasProperty('status', $json);
        $this->assertSame('ERROR', $json->status);
        $this->assertObjectHasProperty('response', $json);
        $this->assertObjectHasProperty('description', $json);
        return $json->description;
    }

    private function validateGetPostNoPathParametersErrorResponse(\Psr\Http\Message\ResponseInterface $response, string $content_type = 'application/json'): void
    {
        $description = $this->validateRequestNoPathParametersErrorResponse($response, $content_type);
        $this->assertSame('Expected at least two and at most three path params for GET and POST requests, received 0', $description);
    }

    private function validateGetPostNationalOrDiocesanCalendarDataNoIdentifierErrorResponse(\Psr\Http\Message\ResponseInterface $response, string $content_type = 'application/json'): void
    {
        $description = $this->validateRequestNoPathParametersErrorResponse($response, $content_type);
        $this->assertSame('Expected at least two and at most three path params for GET and POST requests, received 1', $description);
    }

    private function validatePutNoPathParametersErrorResponse(\Psr\Http\Message\ResponseInterface $response, string $content_type = 'application/json'): void
    {
        $description = $this->validateRequestNoPathParametersErrorResponse($response);
        $this->assertSame('Expected one path param for PUT requests, received 0', $description);
    }

    private function validatePatchDeleteNoPathParametersErrorResponse(\Psr\Http\Message\ResponseInterface $response, string $content_type = 'application/json'): void
    {
        $description = $this->validateRequestNoPathParametersErrorResponse($response);
        $this->assertSame('Expected two and exactly two path params for PATCH and DELETE requests, received 0', $description);
    }
}
