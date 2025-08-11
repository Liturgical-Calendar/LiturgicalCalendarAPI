<?php

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

final class CalendarTest extends ApiTestCase
{
    private static object $metadata;

    public function testGetCalendarReturnsJson(): void
    {
        $response = $this->http->get('/calendar');
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody());

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

        $yaml = yaml_parse((string) $response->getBody());
        $data = json_decode(
            json_encode($yaml, JSON_THROW_ON_ERROR),
            false,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertionsForCalendarObject($data);
    }

    public function testPostCalendarReturnsJson(): void
    {
        $response = $this->http->post('/calendar');
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody());

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
            }
            foreach (self::$metadata->diocesan_calendars_keys as $key) {
                $response = $this->http->get("/calendar/diocese/{$key}/{$year}");
                $this->assertSame(200, $response->getStatusCode());
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
            $data           = json_decode((string) $response->getBody(), false, 512, JSON_THROW_ON_ERROR);
            self::$metadata = $data->litcal_metadata;
        } catch (\JsonException $e) {
            $this->markTestSkipped('Failed to decode calendars metadata JSON: ' . $e->getMessage());
        }
    }
}
