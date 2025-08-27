<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\Readonly;

use GuzzleHttp\Promise\EachPromise;
use LiturgicalCalendar\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;

final class CalendarTest extends ApiTestCase
{
    private static object $metadata;

    public function testGetCalendarReturnsJson(): void
    {
        $response = self::$http->get('/calendar', []);
        $this->assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK');
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'), 'Content-Type should be application/json');

        $data = json_decode((string) $response->getBody());
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg());
        $this->assertionsForCalendarObject($data);
    }

    public function testGetCalendarReturnsYaml(): void
    {
        if (!extension_loaded('yaml')) {
            $this->markTestSkipped('YAML extension is not installed');
        }

        $response = self::$http->get('/calendar', [
            'headers' => ['Accept' => 'application/yaml']
        ]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('application/yaml', $response->getHeaderLine('Content-Type'), 'Content-Type should be application/yaml');

        $yaml = yaml_parse((string) $response->getBody());
        $this->assertIsArray($yaml, 'YAML Response should be an array');
        $encoded = json_encode($yaml);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'YAML Response should have been encoded to JSON: ' . json_last_error_msg());
        $data = json_decode($encoded);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'YAML Response should have been decoded as an object: ' . json_last_error_msg());
        $this->assertIsObject($data, 'Response should have been transformed to a JSON object');
        $this->assertionsForCalendarObject($data);
    }

    public function testGetCalendarReturnsIcal(): void
    {
        $response = self::$http->get('/calendar', [
            'headers' => ['Accept' => 'text/calendar']
        ]);
        $this->assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK, but found error: ' . $response->getBody());
        $this->assertStringStartsWith('text/calendar', $response->getHeaderLine('Content-Type'), 'Content-Type should be text/calendar');
        $data = (string) $response->getBody();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $data, 'Calendar should start with BEGIN:VCALENDAR');
        $this->assertStringContainsString('END:VCALENDAR', $data, 'Calendar should end with END:VCALENDAR');
    }

    public function testGetCalendarReturnsXML(): void
    {
        $response = self::$http->get('/calendar', [
            'headers' => ['Accept' => 'application/xml']
        ]);
        $this->assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK');
        $this->assertStringStartsWith('application/xml', $response->getHeaderLine('Content-Type'), 'Content-Type should be application/xml');
        libxml_use_internal_errors(true);
        $data       = (string) $response->getBody();
        $xml        = new \DOMDocument();
        $loadResult = $xml->loadXML($data);
        $errors     = libxml_get_errors();
        libxml_clear_errors();
        $this->assertTrue(
            $loadResult,
            'Invalid XML' . ( !empty($errors) ? ': ' . $errors[0]->message : '' )
        );
        $root = ApiTestCase::findProjectRoot();
        if ($root === null) {
            $this->markTestSkipped('Project root not found (composer.json not located).');
        }
        $xmlSchema = $root . '/jsondata/schemas/LiturgicalCalendar.xsd';
        $this->assertFileExists($xmlSchema, 'File not found: ' . $xmlSchema);
        $validationResult = $xml->schemaValidate($xmlSchema);
        $errors           = libxml_get_errors();
        libxml_clear_errors();
        $this->assertTrue(
            $validationResult,
            'Expected XML to validate against schema'  . ( !empty($errors) ? ': ' . $errors[0]->message : '' )
        );
    }

    public function testPostCalendarReturnsJson(): void
    {
        $response = self::$http->post('/calendar', []);
        $this->assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK');
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'), 'Content-Type should be application/json');

        $data = json_decode((string) $response->getBody());
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg());

        $this->assertionsForCalendarObject($data);
    }

    public function testPutCalendarReturnsError(): void
    {
        $response = self::$http->put('/calendar', []);
        $this->assertSame(405, $response->getStatusCode(), 'Expected HTTP 405 Method Not Allowed');
    }

    public function testPatchCalendarReturnsError(): void
    {
        $response = self::$http->patch('/calendar', []);
        $this->assertSame(405, $response->getStatusCode(), 'Expected HTTP 405 Method Not Allowed');
    }

    public function testDeleteCalendarReturnsError(): void
    {
        $response = self::$http->delete('/calendar', []);
        $this->assertSame(405, $response->getStatusCode(), 'Expected HTTP 405 Method Not Allowed');
    }

    #[Group('slow')]
    public function testGetCalendarSampleAllCalendars(): void
    {
        $requests = [];

        // Collect all requests first
        for ($year = 1970; $year < 2050; $year++) {
            foreach (self::$metadata->national_calendars_keys as $key) {
                $requests[] = [
                    'uri'  => "/calendar/nation/{$key}/{$year}",
                    'type' => 'national',
                ];
            }
            foreach (self::$metadata->diocesan_calendars_keys as $key) {
                $requests[] = [
                    'uri'  => "/calendar/diocese/{$key}/{$year}",
                    'type' => 'diocesan',
                ];
            }
        }

        $responses = [];
        $errors    = [];

        $each = new EachPromise(
            ( function () use ($requests, &$responses, &$errors) {
                foreach ($requests as $idx => $request) {
                    yield self::$http
                        ->getAsync($request['uri'], [
                            'http_errors' => false
                        ])
                        ->then(
                            function (ResponseInterface $response) use ($idx, $request, &$responses) {
                                $responses[$idx] = $response;
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
                200,
                $response->getStatusCode(),
                "Expected HTTP 200 for {$request['uri']}, got {$response->getStatusCode()}: {$response->getBody()}"
            );
            $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        }
    }

    private function assertionsForCalendarObject(object $data): void
    {
        $this->assertIsObject($data, 'Response should be a JSON object');
        $this->assertObjectHasProperty('settings', $data, 'Response should have a settings property');
        $this->assertObjectHasProperty('metadata', $data, 'Response should have a metadata property');
        $this->assertObjectHasProperty('litcal', $data, 'Response should have a litcal property');
        $this->assertObjectHasProperty('messages', $data, 'Response should have a messages property');
        $this->assertIsObject($data->settings, 'Response settings should be an object');
        $this->assertIsObject($data->metadata, 'Response metadata should be an object');
        $this->assertIsArray($data->litcal, 'Response litcal should be an array');
        $this->assertIsArray($data->messages, 'Response messages should be an array');

        // Assertions for metadata
        $this->assertObjectHasProperty('version', $data->metadata, 'Response metadata should have a version property');
        $this->assertObjectHasProperty('request_headers', $data->metadata, 'Response metadata should have a request_headers property');
        $this->assertObjectHasProperty('solemnities_lord_bvm', $data->metadata, 'Response metadata should have a solemnities_lord_bvm property');
        $this->assertObjectHasProperty('solemnities_lord_bvm_keys', $data->metadata, 'Response metadata should have a solemnities_lord_bvm_keys property');
        $this->assertObjectHasProperty('solemnities', $data->metadata, 'Response metadata should have a solemnities property');
        $this->assertObjectHasProperty('solemnities_keys', $data->metadata, 'Response metadata should have a solemnities_keys property');
        $this->assertObjectHasProperty('feasts_lord', $data->metadata, 'Response metadata should have a feasts_lord property');
        $this->assertObjectHasProperty('feasts_lord_keys', $data->metadata, 'Response metadata should have a feasts_lord_keys property');
        $this->assertObjectHasProperty('feasts', $data->metadata, 'Response metadata should have a feasts property');
        $this->assertObjectHasProperty('feasts_keys', $data->metadata, 'Response metadata should have a feasts_keys property');
        $this->assertObjectHasProperty('memorials', $data->metadata, 'Response metadata should have a memorials property');
        $this->assertObjectHasProperty('memorials_keys', $data->metadata, 'Response metadata should have a memorials_keys property');

        // Assertions for litcal events
        foreach ($data->litcal as $event) {
            $this->assertIsObject($event, 'Response litcal event should be an object');
            $this->assertObjectHasProperty('event_idx', $event, 'Response litcal event should have an event_idx property');
            $this->assertObjectHasProperty('event_key', $event, 'Response litcal event should have an event_key property');
            $this->assertObjectHasProperty('name', $event, 'Response litcal event should have a name property');
            $this->assertObjectHasProperty('date', $event, 'Response litcal event should have a date property');
            $this->assertObjectHasProperty('color', $event, 'Response litcal event should have a color property');
            $this->assertObjectHasProperty('type', $event, 'Response litcal event should have a type property');
            $this->assertObjectHasProperty('grade', $event, 'Response litcal event should have a grade property');
            $this->assertObjectHasProperty('common', $event, 'Response litcal event should have a common property');
            $this->assertObjectHasProperty('liturgical_season', $event, 'Response litcal event should have a liturgical_season property');
        }
    }

    public function setUp(): void
    {
        parent::setUp();

        if (false === isset(self::$metadata)) {
            try {
                $response       = self::$http->get('/calendars', [
                    'http_errors' => true
                ]);
                $data           = json_decode((string) $response->getBody(), false, 512, JSON_THROW_ON_ERROR);
                self::$metadata = $data->litcal_metadata;
            } catch (\JsonException $e) {
                $this->markTestSkipped('Failed to decode calendars metadata JSON: ' . $e->getMessage());
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                $this->markTestSkipped('Failed to get calendars metadata: ' . $e->getMessage());
            } catch (\GuzzleHttp\Exception\ServerException $e) {
                $this->markTestSkipped('Failed to get calendars metadata: ' . $e->getMessage());
            } catch (\GuzzleHttp\Exception\ConnectException $e) {
                $this->markTestSkipped('Failed to get calendars metadata: ' . $e->getMessage());
            }
        }
    }
}
