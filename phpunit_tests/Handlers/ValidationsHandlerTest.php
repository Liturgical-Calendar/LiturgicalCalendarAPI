<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\ValidationsHandler;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotAcceptableException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * `GET /validations` — what this API can be asked to check (#806 step A).
 *
 * The endpoint exists so clients stop hardcoding this repo's on-disk layout, so the assertion that
 * matters most is the negative one: no response may contain a filesystem path.
 */
#[CoversClass(ValidationsHandler::class)]
final class ValidationsHandlerTest extends AbstractHandlerTestCase
{
    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new ValidationsHandler() )->handle(
            $this->requestFor('OPTIONS', '/validations', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testGetReturnsTheInventoryEnvelope(): void
    {
        $response = ( new ValidationsHandler() )->handle($this->requestFor('GET', '/validations'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $body = $this->decodeJsonBody($response);
        self::assertArrayHasKey('litcal_validations', $body);
        self::assertCount(18, $body['litcal_validations']);
    }

    public function testEveryItemCarriesTheAdvertisedFields(): void
    {
        $body = $this->decodeJsonBody(
            ( new ValidationsHandler() )->handle($this->requestFor('GET', '/validations'))
        );

        foreach ($body['litcal_validations'] as $item) {
            foreach (['id', 'kind', 'rite', 'region', 'label', 'schema', 'steps'] as $key) {
                self::assertArrayHasKey($key, $item);
            }
            self::assertContains($item['kind'], ['file', 'folder']);
            self::assertContains($item['rite'], ['roman', 'ambrosian']);
            self::assertTrue($item['region'] === null || preg_match('/^[A-Z]{2}$/', $item['region']) === 1);
            self::assertStringEndsWith('.json', $item['schema']);
            self::assertSame(['exists', 'parses', 'validates'], $item['steps']);
        }
    }

    /** The reason the endpoint exists: no client should ever see a path again. */
    public function testTheResponseLeaksNoFilesystemPath(): void
    {
        $raw = (string) ( new ValidationsHandler() )->handle($this->requestFor('GET', '/validations'))->getBody();

        self::assertStringNotContainsString('jsondata', $raw);
        self::assertStringNotContainsString('sourcedata', $raw);
        self::assertStringNotContainsString('"path"', $raw);
    }

    public function testAnUnacceptableAcceptHeaderIsRejected(): void
    {
        $this->expectException(NotAcceptableException::class);
        ( new ValidationsHandler() )->handle(
            $this->requestFor('GET', '/validations', ['Accept' => 'image/png'])
        );
    }

    public function testANonGetVerbIsRejected(): void
    {
        $this->expectException(MethodNotAllowedException::class);
        ( new ValidationsHandler() )->handle($this->requestFor('DELETE', '/validations'));
    }

    public function testAPathParameterIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        ( new ValidationsHandler(['roman']) )->handle($this->requestFor('GET', '/validations/roman'));
    }
}
