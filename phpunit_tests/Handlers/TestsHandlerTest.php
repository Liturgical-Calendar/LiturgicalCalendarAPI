<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\TestsHandler;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TestsHandler::class)]
final class TestsHandlerTest extends AbstractHandlerTestCase
{
    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new TestsHandler() )->handle(
            $this->requestFor('OPTIONS', '/tests', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testGetReturnsTestSuiteIndex(): void
    {
        $response = ( new TestsHandler() )->handle($this->requestFor('GET', '/tests'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertArrayHasKey('litcal_tests', $body);
        self::assertNotEmpty($body['litcal_tests']);
    }

    public function testGetSingleTestByNameReturnsThatTest(): void
    {
        // MaryMotherChurchTest ships with the repo per jsondata/tests/.
        $handler  = new TestsHandler(['MaryMotherChurchTest']);
        $response = $handler->handle($this->requestFor('GET', '/tests/MaryMotherChurchTest'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJson((string) $response->getBody());
    }

    public function testUnknownTestIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        ( new TestsHandler(['NotARealTest']) )
            ->handle($this->requestFor('GET', '/tests/NotARealTest'));
    }

    public function testTooManyPathParamsIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        ( new TestsHandler(['a', 'b']) )
            ->handle($this->requestFor('GET', '/tests/a/b'));
    }

    public function testPutWithMalformedPayloadIsValidationError(): void
    {
        // PUT (writes), with a JSON array of scalars (not objects). parseBodyPayload
        // surfaces this as a ValidationException at the AbstractHandler layer before
        // TestsHandler's own object check fires.
        $this->expectException(ValidationException::class);
        $req = $this->requestFor('PUT', '/tests', [], '[1, 2, 3]')
            ->withHeader('Content-Type', 'application/json');
        ( new TestsHandler() )->handle($req);
    }
}
