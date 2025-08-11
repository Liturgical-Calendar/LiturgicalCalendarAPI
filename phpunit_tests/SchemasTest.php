<?php

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Tests\ApiTestCase;

final class SchemasTest extends ApiTestCase
{
    public function testGetSchemasReturnsJson(): void
    {
        $response = $this->http->get('/schemas');
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody());
        $this->assertIsObject($data);
        $this->assertObjectHasProperty('litcal_schemas', $data);
        $this->assertIsArray($data->litcal_schemas);

        $regex = sprintf(
            '/^%s:\/\/%s:%d\/schemas\/(?:[A-Z][A-Za-z]+|openapi)\.json$/',
            $_ENV['API_PROTOCOL'],
            $_ENV['API_HOST'],
            $_ENV['API_PORT']
        );

        // There are not more than 20 schemas, so this shouldn't be too expensive
        foreach ($data->litcal_schemas as $schema) {
            $this->assertIsString($schema);
            $this->assertMatchesRegularExpression($regex, $schema);
            $response = $this->http->get($schema);
            $this->assertSame(200, $response->getStatusCode());
            $data = json_decode((string) $response->getBody());
            $this->assertIsObject($data);
            if (property_exists($data, 'openapi')) {
                $this->assertIsString($data->openapi);
                $this->assertEquals('3.1.0', $data->openapi);
            } elseif (property_exists($data, '$schema')) {
                $this->assertIsString($data->{'$schema'});
                $this->assertEquals('http://json-schema.org/draft-07/schema#', $data->{'$schema'});
            } else {
                $this->fail('Data object has neither openapi nor $schema property.');
            }
        }
    }

    public function testPostSchemasReturnsJson(): void
    {
        $response = $this->http->post('/schemas');
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody());
        $this->assertIsObject($data);
        $this->assertObjectHasProperty('litcal_schemas', $data);
        $this->assertIsArray($data->litcal_schemas);
    }

    public function testGetSchemasReturnsYaml(): void
    {
        $response = $this->http->get('/schemas', [
            'headers' => ['Accept' => 'application/yaml']
        ]);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPutSchemasReturnsError(): void
    {
        $response = $this->http->put('/schemas');
        $this->assertSame(405, $response->getStatusCode(), 'Expected HTTP 405 Method Not Allowed');
    }

    public function testPatchSchemasReturnsError(): void
    {
        $response = $this->http->patch('/schemas');
        $this->assertSame(405, $response->getStatusCode(), 'Expected HTTP 405 Method Not Allowed');
    }

    public function testDeleteSchemasReturnsError(): void
    {
        $response = $this->http->delete('/schemas');
        $this->assertSame(405, $response->getStatusCode(), 'Expected HTTP 405 Method Not Allowed');
    }
}
