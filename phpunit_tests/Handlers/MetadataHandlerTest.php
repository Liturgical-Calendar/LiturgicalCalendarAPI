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
        self::assertArrayHasKey('ambrosian_calendars', $body['litcal_metadata']);
        self::assertArrayHasKey('ambrosian_calendars_keys', $body['litcal_metadata']);
        // The "VA" General Roman calendar is always present.
        self::assertContains('VA', $body['litcal_metadata']['national_calendars_keys']);
        // The Ambrosian comune calendar (/calendar/ambrosian) is always present.
        self::assertContains('ambrosian', $body['litcal_metadata']['ambrosian_calendars_keys']);
        // Locate the comune entry by calendar_id rather than assuming a fixed array index.
        $ambrosianEntries = array_values(array_filter(
            $body['litcal_metadata']['ambrosian_calendars'],
            static fn (array $c): bool => ( $c['calendar_id'] ?? null ) === 'ambrosian'
        ));
        self::assertCount(1, $ambrosianEntries, 'Expected exactly one Ambrosian comune calendar entry');
        self::assertContains('it', $ambrosianEntries[0]['locales']);
        self::assertContains('la', $ambrosianEntries[0]['locales']);
        self::assertSame('ambrosian', $ambrosianEntries[0]['rite']);
    }

    /**
     * The `/calendars` discovery endpoint must announce all four Ambrosian
     * dioceses (milano_it, bergam_it, novara_it, lugano_ch) with
     * `rite: "ambrosian"`, alongside `diocesan_calendars_keys`, while Roman
     * dioceses remain unaffected and still report `rite: "roman"`.
     */
    public function testGetAnnouncesAmbrosianDiocesesWithRite(): void
    {
        $response = ( new MetadataHandler() )->handle($this->requestFor('GET', '/calendars'));
        self::assertSame(200, $response->getStatusCode());

        $body            = $this->decodeJsonBody($response);
        $diocesanKeys    = $body['litcal_metadata']['diocesan_calendars_keys'];
        $diocesanEntries = $body['litcal_metadata']['diocesan_calendars'];
        $entriesByCalId  = [];
        foreach ($diocesanEntries as $entry) {
            self::assertArrayHasKey('rite', $entry, "diocesan_calendars entry for `{$entry['calendar_id']}` is missing `rite`");
            $entriesByCalId[$entry['calendar_id']] = $entry;
        }

        $ambrosianDioceses = ['milano_it', 'bergam_it', 'novara_it', 'lugano_ch'];
        foreach ($ambrosianDioceses as $calendarId) {
            self::assertContains($calendarId, $diocesanKeys, "Expected `{$calendarId}` in diocesan_calendars_keys");
            self::assertArrayHasKey($calendarId, $entriesByCalId, "Expected `{$calendarId}` in diocesan_calendars");
            self::assertSame(
                'ambrosian',
                $entriesByCalId[$calendarId]['rite'],
                "Expected `{$calendarId}` to be tagged rite=ambrosian"
            );
        }

        // A Roman diocese must remain present and unaffected, still tagged rite=roman.
        self::assertContains('agrige_it', $diocesanKeys);
        self::assertArrayHasKey('agrige_it', $entriesByCalId);
        self::assertSame('roman', $entriesByCalId['agrige_it']['rite']);
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
