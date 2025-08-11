<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use PHPUnit\Framework\Attributes\Group;

final class CalendarTest extends ApiTestCase
{
    private static object $metadata;

    public function testGetCalendarReturnsJson(): void
    {
        $response = $this->http->get('/calendar');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $data = json_decode((string) $response->getBody());
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg());
        $this->assertionsForCalendarObject($data);
    }

    public function testGetCalendarReturnsYaml(): void
    {
        if (!extension_loaded('yaml')) {
            $this->markTestSkipped('YAML extension is not installed');
        }

        $response = $this->http->get('/calendar', [
            'headers' => ['Accept' => 'application/yaml']
        ]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/yaml', $response->getHeaderLine('Content-Type'));

        $yaml = yaml_parse((string) $response->getBody());
        $this->assertIsArray($yaml);
        $encoded = json_encode($yaml);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg());
        $data = json_decode($encoded);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg());
        $this->assertIsObject($data);
        $this->assertionsForCalendarObject($data);
    }

    public function testGetCalendarReturnsICS(): void
    {
        $response = $this->http->get('/calendar', [
            'headers' => ['Accept' => 'text/calendar']
        ]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/calendar', $response->getHeaderLine('Content-Type'));
        $data = (string) $response->getBody();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $data);
        $this->assertStringContainsString('END:VCALENDAR', $data);
    }

    public function testGetCalendarReturnsXML(): void
    {
        $response = $this->http->get('/calendar', [
            'headers' => ['Accept' => 'application/xml']
        ]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/xml', $response->getHeaderLine('Content-Type'));
        $data = (string) $response->getBody();
        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $data);
        $this->assertStringContainsString('<LiturgicalCalendar xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns="http://www.bibleget.io/catholicliturgy" xmlns:cl="http://www.bibleget.io/catholicliturgy" xsi:schemaLocation="http://www.bibleget.io/catholicliturgy', $data);
        $this->assertStringContainsString('<LitCal>', $data);
        $this->assertStringContainsString('</LitCal>', $data);
    }

    public function testPostCalendarReturnsJson(): void
    {
        $response = $this->http->post('/calendar');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $data = json_decode((string) $response->getBody());
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg());

        $this->assertionsForCalendarObject($data);
    }

    public function testPutCalendarReturnsError(): void
    {
        $response = $this->http->put('/calendar');
        $this->assertSame(405, $response->getStatusCode(), 'Expected HTTP 405 Method Not Allowed');
    }

    public function testPatchCalendarReturnsError(): void
    {
        $response = $this->http->patch('/calendar');
        $this->assertSame(405, $response->getStatusCode(), 'Expected HTTP 405 Method Not Allowed');
    }

    public function testDeleteCalendarReturnsError(): void
    {
        $response = $this->http->delete('/calendar');
        $this->assertSame(405, $response->getStatusCode(), 'Expected HTTP 405 Method Not Allowed');
    }

    #[Group('slow')]
    public function testGetCalendarSampleAllCalendars(): void
    {
        for ($year = 1970; $year < 2050; $year++) {
            foreach (self::$metadata->national_calendars_keys as $key) {
                $response = $this->http->get("/calendar/nation/{$key}/{$year}");
                $this->assertSame(200, $response->getStatusCode());
                $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
            }
            foreach (self::$metadata->diocesan_calendars_keys as $key) {
                $response = $this->http->get("/calendar/diocese/{$key}/{$year}");
                $this->assertSame(200, $response->getStatusCode());
                $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
            }
        }
    }

    private function assertionsForCalendarObject(object $data): void
    {
        $this->assertIsObject($data);
        $this->assertObjectHasProperty('settings', $data);
        $this->assertObjectHasProperty('metadata', $data);
        $this->assertObjectHasProperty('litcal', $data);
        $this->assertObjectHasProperty('messages', $data);
        $this->assertIsObject($data->settings);
        $this->assertIsObject($data->metadata);
        $this->assertIsArray($data->litcal);
        $this->assertIsArray($data->messages);

        // Assertions for metadata
        $this->assertObjectHasProperty('version', $data->metadata);
        $this->assertObjectHasProperty('request_headers', $data->metadata);
        $this->assertObjectHasProperty('solemnities_lord_bvm', $data->metadata);
        $this->assertObjectHasProperty('solemnities_lord_bvm_keys', $data->metadata);
        $this->assertObjectHasProperty('solemnities', $data->metadata);
        $this->assertObjectHasProperty('solemnities_keys', $data->metadata);
        $this->assertObjectHasProperty('feasts_lord', $data->metadata);
        $this->assertObjectHasProperty('feasts_lord_keys', $data->metadata);
        $this->assertObjectHasProperty('feasts', $data->metadata);
        $this->assertObjectHasProperty('feasts_keys', $data->metadata);
        $this->assertObjectHasProperty('memorials', $data->metadata);
        $this->assertObjectHasProperty('memorials_keys', $data->metadata);

        // Assertions for litcal events
        foreach ($data->litcal as $event) {
            $this->assertIsObject($event);
            $this->assertObjectHasProperty('event_idx', $event);
            $this->assertObjectHasProperty('event_key', $event);
            $this->assertObjectHasProperty('name', $event);
            $this->assertObjectHasProperty('date', $event);
            $this->assertObjectHasProperty('color', $event);
            $this->assertObjectHasProperty('type', $event);
            $this->assertObjectHasProperty('grade', $event);
            $this->assertObjectHasProperty('common', $event);
            $this->assertObjectHasProperty('liturgical_season', $event);
        }
    }

    public function setUp(): void
    {
        parent::setUp();

        $response = $this->http->get('/calendars');
        try {
            $data = json_decode((string) $response->getBody());
            $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg());
            self::$metadata = $data->litcal_metadata;
        } catch (\JsonException $e) {
            $this->markTestSkipped('Failed to decode calendars metadata JSON: ' . $e->getMessage());
        }
    }
}
