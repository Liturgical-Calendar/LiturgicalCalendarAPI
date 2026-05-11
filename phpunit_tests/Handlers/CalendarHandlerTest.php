<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * CalendarHandler is the heart of the API — it computes the full liturgical
 * calendar for a year. This suite exercises the request-shape gates and a
 * handful of happy-path year computations. Deep coverage of edge cases
 * (suppression, transfer rules, Easter cycle anomalies) lives in the
 * external UnitTestInterface integration suite by design.
 */
#[CoversClass(CalendarHandler::class)]
final class CalendarHandlerTest extends AbstractHandlerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // CalendarHandler's CalendarParams fetches /calendars metadata from
        // Router::$apiPath; route it at the M1 fixture so the constructor
        // succeeds without an HTTP server.
        $fixturePath = realpath(__DIR__ . '/../fixtures/api');
        self::assertNotFalse($fixturePath, 'M1 calendars fixture must be present');
        Router::$apiPath = 'file://' . $fixturePath;
    }

    /**
     * In production, Router::buildHandler() calls setAllowedReturnTypes()
     * before invoking the handler; without it CalendarParams rejects the
     * default-negotiated 'JSON' return type. Centralise the setup here.
     */
    private function makeHandler(array $pathParams = []): CalendarHandler
    {
        $handler = new CalendarHandler($pathParams);
        $handler->setAllowedReturnTypes([
            ReturnTypeParam::JSON,
            ReturnTypeParam::YAML,
            ReturnTypeParam::XML,
            ReturnTypeParam::ICS,
        ]);
        return $handler;
    }

    public function testOptionsPreflightSucceeds(): void
    {
        $response = $this->makeHandler()->handle(
            $this->requestFor('OPTIONS', '/calendar', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testGetForCurrentYearReturnsCalendarShape(): void
    {
        $response = $this->makeHandler()->handle(
            $this->requestFor('GET', '/calendar', ['Accept-Language' => 'la'])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);

        // CalendarHandler produces a fixed top-level shape.
        self::assertArrayHasKey('settings', $body);
        self::assertArrayHasKey('metadata', $body);
        self::assertArrayHasKey('litcal', $body);
        self::assertNotEmpty($body['litcal'], 'A computed calendar must contain liturgical events');
    }

    public function testGetForExplicitYearAppliesIt(): void
    {
        $response = $this->makeHandler(['2025'])->handle(
            $this->requestFor('GET', '/calendar/2025', ['Accept-Language' => 'la'])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertSame(2025, $body['settings']['year']);
    }

    public function testYearOutsideRangeIsValidationError(): void
    {
        // YEAR_LOWER_LIMIT is 1970; 1900 must be rejected.
        $this->expectException(ValidationException::class);
        $this->makeHandler(['1900'])
            ->handle($this->requestFor('GET', '/calendar/1900', ['Accept-Language' => 'la']));
    }

    public function testNonNumericYearPathIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        $this->makeHandler(['twenty-twenty-five'])
            ->handle($this->requestFor('GET', '/calendar/twenty-twenty-five', ['Accept-Language' => 'la']));
    }

    public function testInvalidNationalCalendarPathIsValidationError(): void
    {
        // 'nation' segment + unknown nation key triggers CalendarParams's
        // validation against the M1 fixture's national_calendars_keys.
        $this->expectException(ValidationException::class);
        $this->makeHandler(['nation', 'ZZ', '2025'])
            ->handle($this->requestFor('GET', '/calendar/nation/ZZ/2025', ['Accept-Language' => 'la']));
    }
}
