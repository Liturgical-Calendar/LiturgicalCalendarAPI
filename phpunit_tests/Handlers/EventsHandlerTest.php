<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\EventsHandler;
use LiturgicalCalendar\Api\Http\Exception\UnprocessableContentException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(EventsHandler::class)]
final class EventsHandlerTest extends AbstractHandlerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // EventsHandler constructs an EventsParams, which fetches
        // /calendars metadata via file_get_contents during its constructor.
        // Point Router::$apiPath at the file:// fixture introduced in M1 so
        // the fetch is deterministic and offline.
        $fixturePath = realpath(__DIR__ . '/../fixtures/api');
        self::assertNotFalse($fixturePath, 'M1 calendars fixture must be present');
        Router::$apiPath = 'file://' . $fixturePath;
    }

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
