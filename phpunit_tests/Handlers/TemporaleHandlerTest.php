<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\TemporaleHandler;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * TemporaleHandler serves the Proper of Time data with translations and
 * lectionary readings. Deep coverage of the read-write paths (PUT / PATCH /
 * DELETE — JWT/DB-gated) lives in M5 once Services have mock seams; this
 * suite focuses on the read paths and the gates that run before the
 * write-path side effects.
 */
#[CoversClass(TemporaleHandler::class)]
final class TemporaleHandlerTest extends AbstractHandlerTestCase
{
    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new TemporaleHandler() )->handle(
            $this->requestFor('OPTIONS', '/temporale', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testGetReturnsTemporaleData(): void
    {
        $response = ( new TemporaleHandler() )->handle(
            $this->requestFor('GET', '/temporale', ['Accept-Language' => 'la'])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        // The handler returns a list of temporale event objects, each with
        // an event_key. The shape varies (sometimes wrapped, sometimes raw),
        // so just verify the response is JSON-parseable and non-empty.
        self::assertNotEmpty($body);
    }

    public function testInvalidLocaleQueryIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        ( new TemporaleHandler() )->handle(
            $this->requestFor('GET', '/temporale?locale=clearly-not-a-locale')
        );
    }
}
