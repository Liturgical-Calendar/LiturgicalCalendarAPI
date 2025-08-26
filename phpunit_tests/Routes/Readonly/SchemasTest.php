<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\Readonly;

use LiturgicalCalendar\Tests\ApiTestCase;

final class SchemasTest extends ApiTestCase
{
    public function testGetSchemasReturnsJson(): void
    {
        $response = self::$http->get('/schemas', []);
        $this->assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK');
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'), 'Content-Type should be application/json');

        $data = json_decode((string) $response->getBody());
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg());
        $this->assertIsObject($data, 'Response should be a JSON object');
        $this->assertObjectHasProperty('litcal_schemas', $data, 'Response should have a litcal_schemas property');
        $this->assertIsArray($data->litcal_schemas, 'Response litcal_schemas should be an array');

        $regex = sprintf(
            '/^%s:\/\/%s:%d\/schemas\/(?:[A-Z][A-Za-z]+|openapi)\.json$/',
            $_ENV['API_PROTOCOL'],
            $_ENV['API_HOST'],
            $_ENV['API_PORT']
        );

        // There are not more than 20 schemas, so this shouldn't be too expensive
        foreach ($data->litcal_schemas as $schema) {
            $this->assertIsString($schema, 'Schema should be a string');
            $this->assertMatchesRegularExpression($regex, $schema, 'Schema should be a valid URL');
            $response = self::$http->get($schema);
            $this->assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK');
            $data = json_decode((string) $response->getBody());
            $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg());
            $this->assertIsObject($data, 'Response should be a JSON object');
            if (property_exists($data, 'openapi')) {
                $this->assertIsString($data->openapi, 'openapi should be a string');
                $this->assertEquals('3.1.0', $data->openapi, 'openapi should be 3.1.0');
            } elseif (property_exists($data, '$schema')) {
                $this->assertIsString($data->{'$schema'}, '$schema should be a string');
                $this->assertEquals('http://json-schema.org/draft-07/schema#', $data->{'$schema'}, '$schema should be http://json-schema.org/draft-07/schema#');
            } else {
                $this->fail('Data object has neither openapi nor $schema property.');
            }
        }
    }

    public function testPostSchemasReturnsJson(): void
    {
        $response = self::$http->post('/schemas', []);
        $this->assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK');
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'), 'Content-Type should be application/json');

        $data = json_decode((string) $response->getBody());
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg());
        $this->assertIsObject($data, 'Response should be a JSON object');
        $this->assertObjectHasProperty('litcal_schemas', $data, 'Response should have a litcal_schemas property');
        $this->assertIsArray($data->litcal_schemas, 'Response litcal_schemas should be an array');
    }

    public function testGetSchemasReturnsYaml(): void
    {
        if (!extension_loaded('yaml')) {
            $this->markTestSkipped('YAML extension is not installed');
        }

        $response = self::$http->get('/schemas', [
            'headers' => ['Accept' => 'application/yaml']
        ]);
        $this->assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK');
        $this->assertStringStartsWith('application/yaml', $response->getHeaderLine('Content-Type'), 'Content-Type should be application/yaml');
    }

    public function testPutSchemasReturnsError(): void
    {
        $response = self::$http->put('/schemas', []);
        $this->assertSame(405, $response->getStatusCode(), 'Expected HTTP 405 Method Not Allowed');
    }

    public function testPatchSchemasReturnsError(): void
    {
        $response = self::$http->patch('/schemas', []);
        $this->assertSame(405, $response->getStatusCode(), 'Expected HTTP 405 Method Not Allowed');
    }

    public function testDeleteSchemasReturnsError(): void
    {
        $response = self::$http->delete('/schemas', []);
        $this->assertSame(405, $response->getStatusCode(), 'Expected HTTP 405 Method Not Allowed');
    }
}
