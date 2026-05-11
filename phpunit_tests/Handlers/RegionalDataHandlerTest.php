<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\RegionalDataHandler;
use LiturgicalCalendar\Api\Http\Exception\UnprocessableContentException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * RegionalDataHandler serves and edits per-nation / per-diocese / per-wider-
 * region calendar source data. The PUT/PATCH/DELETE branches are gated by
 * JWT auth middleware (added by the router before handler invocation) and
 * involve disk writes; this suite covers the read paths and the path /
 * category validators that run before any side effects.
 */
#[CoversClass(RegionalDataHandler::class)]
final class RegionalDataHandlerTest extends AbstractHandlerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The handler resolves national/diocesan keys against the /calendars
        // metadata; route the fetch at the M1 fixture so the lookups succeed
        // deterministically without an HTTP server.
        $fixturePath = realpath(__DIR__ . '/../fixtures/api');
        self::assertNotFalse($fixturePath, 'M1 calendars fixture must be present');
        Router::$apiPath = 'file://' . $fixturePath;
    }

    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new RegionalDataHandler() )->handle(
            $this->requestFor('OPTIONS', '/data', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testTooFewPathParamsIsValidationError(): void
    {
        // GET requires at least two segments (category + key); pass one.
        $this->expectException(ValidationException::class);
        ( new RegionalDataHandler(['nation']) )
            ->handle($this->requestFor('GET', '/data/nation'));
    }

    public function testInvalidCategoryIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        ( new RegionalDataHandler(['planet', 'mars']) )
            ->handle($this->requestFor('GET', '/data/planet/mars'));
    }

    public function testGetForUnknownNationalCalendarIsUnprocessable(): void
    {
        // 'ZZ' isn't a real nation key; the handler surfaces an
        // UnprocessableContentException listing valid keys.
        $this->expectException(UnprocessableContentException::class);
        ( new RegionalDataHandler(['nation', 'ZZ']) )
            ->handle($this->requestFor('GET', '/data/nation/ZZ'));
    }

    public function testPutWithoutPayloadIsValidationError(): void
    {
        // PUT requires exactly 1 path param (the category). Passing the
        // request without a body trips the empty-payload check in
        // parseBodyPayload → ValidationException.
        $this->expectException(ValidationException::class);
        ( new RegionalDataHandler(['nation']) )
            ->handle($this->requestFor('PUT', '/data/nation', ['Content-Type' => 'application/json'], ''));
    }
}
