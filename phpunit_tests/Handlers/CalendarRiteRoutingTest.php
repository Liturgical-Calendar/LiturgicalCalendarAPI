<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\ApiException;

final class CalendarRiteRoutingTest extends AbstractHandlerTestCase
{
    /**
     * In-process handler tests call handle() directly, bypassing the PSR-15
     * ErrorHandlingMiddleware that normally converts an ApiException into an
     * HTTP problem+json response (see Router::route()). So here we catch the
     * ApiException ourselves and read its status, mirroring what the
     * middleware does in production.
     *
     * @param string[] $pathParts
     */
    private function handle(array $pathParts, Rite $rite, string $uri): int
    {
        $handler = new CalendarHandler($pathParts, $rite);
        $handler->setAllowedReturnTypes([
            ReturnTypeParam::JSON,
            ReturnTypeParam::YAML,
            ReturnTypeParam::XML,
            ReturnTypeParam::ICS,
        ]);
        try {
            return $handler->handle($this->requestFor('GET', $uri, ['Accept' => 'application/json']))->getStatusCode();
        } catch (ApiException $e) {
            return $e->getStatus();
        }
    }

    public function testRomanDefaultStillWorks(): void
    {
        self::assertSame(200, $this->handle(['2025'], Rite::ROMAN, '/calendar/2025'));
    }

    public function testAmbrosianComuneBaseReturns501(): void
    {
        self::assertSame(StatusCode::NOT_IMPLEMENTED->value, $this->handle([], Rite::AMBROSIAN, '/calendar/ambrosian'));
    }

    public function testAmbrosianComuneWithYearReturns501(): void
    {
        self::assertSame(StatusCode::NOT_IMPLEMENTED->value, $this->handle(['2008'], Rite::AMBROSIAN, '/calendar/ambrosian/2008'));
    }

    public function testAmbrosianRejectsNationalCalendarWith400(): void
    {
        self::assertSame(StatusCode::BAD_REQUEST->value, $this->handle(['nation', 'US'], Rite::AMBROSIAN, '/calendar/ambrosian/nation/US'));
    }

    public function testAmbrosianRejectsNonWhitelistedDioceseWith400(): void
    {
        self::assertSame(StatusCode::BAD_REQUEST->value, $this->handle(['diocese', 'romamo_it'], Rite::AMBROSIAN, '/calendar/ambrosian/diocese/romamo_it'));
    }

    public function testAmbrosianRejectsYearBelow1976With400(): void
    {
        self::assertSame(StatusCode::BAD_REQUEST->value, $this->handle(['1975'], Rite::AMBROSIAN, '/calendar/ambrosian/1975'));
    }
}
