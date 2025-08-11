<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use PHPUnit\Framework\Attributes\Group;

final class EventsTest extends ApiTestCase
{
    private static object $metadata;

    public function testGetEventsReturnsJson(): void
    {
        $response = $this->http->get('/events');
        $this->assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK');
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function setUp(): void
    {
        parent::setUp();

        $response = $this->http->get('/calendars');
        $this->assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK');
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        try {
            $data = json_decode((string) $response->getBody());
            $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg());
            self::$metadata = $data->litcal_metadata;
        } catch (\JsonException $e) {
            $this->markTestSkipped('Failed to decode calendars metadata JSON: ' . $e->getMessage());
        }
    }
}
