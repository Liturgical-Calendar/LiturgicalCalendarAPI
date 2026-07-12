<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\EventsHandler;
use LiturgicalCalendar\Api\Http\Exception\UnprocessableContentException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(EventsHandler::class)]
final class EventsHandlerTest extends AbstractHandlerTestCase
{
    // EventsHandler constructs an EventsParams, which now builds the calendars
    // metadata index in-process from local source data (CalendarMetadataProvider)
    // rather than fetching /calendars over HTTP. AbstractHandlerTestCase already
    // pins Router::$apiFilePath to the project root, so the build resolves
    // against the bundled sourcedata with no HTTP server or fixture needed.

    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new EventsHandler() )->handle(
            $this->requestFor('OPTIONS', '/events', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testGetReturnsLiturgicalEvents(): void
    {
        $response = ( new EventsHandler() )->handle(
            $this->requestFor('GET', '/events', ['Accept-Language' => 'la'])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertArrayHasKey('litcal_events', $body);
        self::assertNotEmpty($body['litcal_events']);
        self::assertArrayHasKey('settings', $body);
        self::assertSame('la', $body['settings']['locale']);
        // Each event has an event_key (the catalog id used by frontends).
        self::assertArrayHasKey('event_key', $body['litcal_events'][0]);
    }

    public function testGetIncludesTemporaleEventsWithLocalizedNames(): void
    {
        // The catalog must include temporale (Proprium de Tempore) anchors — e.g. Pentecost — so a decree's
        // relative strtotime can reference them. These are absent from the sanctorale-only Missal data.
        $response = ( new EventsHandler() )->handle(
            $this->requestFor('GET', '/events', ['Accept-Language' => 'en'])
        );
        self::assertSame(200, $response->getStatusCode());
        $events = $this->decodeJsonBody($response)['litcal_events'];

        $byKey = [];
        foreach ($events as $e) {
            $byKey[$e['event_key']] = $e;
        }

        self::assertArrayHasKey('Pentecost', $byKey, 'temporale anchor Pentecost must be in the events catalog');
        self::assertArrayHasKey('Easter', $byKey);
        self::assertSame('Pentecost', $byKey['Pentecost']['name']);
        self::assertSame('mobile', $byKey['Pentecost']['type']);
        // Temporale entries are date-less: no month/day/strtotime, but carry the required localized fields.
        self::assertArrayNotHasKey('month', $byKey['Pentecost']);
        self::assertArrayNotHasKey('strtotime', $byKey['Pentecost']);
        self::assertArrayHasKey('grade_lcl', $byKey['Pentecost']);
        self::assertArrayHasKey('name', $byKey['Pentecost']);
    }

    public function testGetForNationalCalendarReturnsThatCalendar(): void
    {
        $handler  = new EventsHandler(['nation', 'IT']);
        $response = $handler->handle($this->requestFor('GET', '/events/nation/IT', ['Accept-Language' => 'it']));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertSame('IT', $body['settings']['national_calendar']);
    }

    public function testBadResourceTypeIsUnprocessable(): void
    {
        $this->expectException(UnprocessableContentException::class);
        ( new EventsHandler(['galaxy', 'IT']) )
            ->handle($this->requestFor('GET', '/events/galaxy/IT', ['Accept-Language' => 'la']));
    }

    public function testTooFewPathPartsIsUnprocessable(): void
    {
        // count=1 misses the count-2 branch and falls through to the error
        // path. UnprocessableContentException is the expected surface.
        $this->expectException(UnprocessableContentException::class);
        ( new EventsHandler(['nation']) )
            ->handle($this->requestFor('GET', '/events/nation', ['Accept-Language' => 'la']));
    }

    public function testUnknownDiocesanCalendarIsValidationError(): void
    {
        // 'diocese' is a valid resource path but the second segment is an
        // unknown diocesan key, which EventsParams rejects.
        $this->expectException(ValidationException::class);
        ( new EventsHandler(['diocese', 'nowhere_zz']) )
            ->handle($this->requestFor('GET', '/events/diocese/nowhere_zz', ['Accept-Language' => 'la']));
    }
}
