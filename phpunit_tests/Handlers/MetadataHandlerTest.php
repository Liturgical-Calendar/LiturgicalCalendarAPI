<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\MetadataHandler;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MetadataHandler::class)]
final class MetadataHandlerTest extends AbstractHandlerTestCase
{
    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new MetadataHandler() )->handle(
            $this->requestFor('OPTIONS', '/calendars', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testGetReturnsCalendarsMetadata(): void
    {
        $response = ( new MetadataHandler() )->handle($this->requestFor('GET', '/calendars'));

        self::assertSame(200, $response->getStatusCode());
        $etag = trim($response->getHeaderLine('ETag'), ' "');
        self::assertNotEmpty($etag);

        $body = $this->decodeJsonBody($response);
        self::assertArrayHasKey('litcal_metadata', $body);
        self::assertArrayHasKey('national_calendars', $body['litcal_metadata']);
        self::assertArrayHasKey('national_calendars_keys', $body['litcal_metadata']);
        self::assertArrayHasKey('diocesan_calendars', $body['litcal_metadata']);
        self::assertArrayHasKey('diocesan_calendars_keys', $body['litcal_metadata']);
        self::assertArrayHasKey('wider_regions', $body['litcal_metadata']);
        self::assertArrayHasKey('locales', $body['litcal_metadata']);
        // The "VA" General Roman calendar is always present.
        self::assertContains('VA', $body['litcal_metadata']['national_calendars_keys']);
    }

    public function testConditionalGetWithMatchingEtagReturns304(): void
    {
        $first = ( new MetadataHandler() )->handle($this->requestFor('GET', '/calendars'));
        $etag  = trim($first->getHeaderLine('ETag'), ' "');
        self::assertNotEmpty($etag);

        $second = ( new MetadataHandler() )->handle(
            $this->requestFor('GET', '/calendars', ['If-None-Match' => '"' . $etag . '"'])
        );

        self::assertSame(304, $second->getStatusCode());
        self::assertSame('0', $second->getHeaderLine('Content-Length'));
    }
}
