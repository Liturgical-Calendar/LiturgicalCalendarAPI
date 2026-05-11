<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\SchemasHandler;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SchemasHandler::class)]
final class SchemasHandlerTest extends AbstractHandlerTestCase
{
    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new SchemasHandler() )->handle(
            $this->requestFor('OPTIONS', '/schemas', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testNoPathParamsReturnsSchemaIndex(): void
    {
        $response = ( new SchemasHandler() )->handle($this->requestFor('GET', '/schemas'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $body = $this->decodeJsonBody($response);
        self::assertArrayHasKey('litcal_schemas', $body);
        self::assertIsArray($body['litcal_schemas']);
        self::assertNotEmpty($body['litcal_schemas']);
        // Each entry is a URL ending in .json
        foreach ($body['litcal_schemas'] as $entry) {
            self::assertStringEndsWith('.json', $entry);
        }
    }

    public function testKnownSchemaFileIsReturnedRaw(): void
    {
        // Pick a known schema that lives in jsondata/schemas/. LitCalTest.json
        // ships with the repo per CLAUDE.md's docs.
        $handler = new SchemasHandler(['LitCalTest.json']);

        $response = $handler->handle($this->requestFor('GET', '/schemas/LitCalTest.json'));

        self::assertSame(200, $response->getStatusCode());
        $raw = (string) $response->getBody();
        self::assertJson($raw, 'Schema body must be valid JSON');
    }

    public function testMissingSchemaIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $handler = new SchemasHandler(['Does_Not_Exist.json']);
        $handler->handle($this->requestFor('GET', '/schemas/Does_Not_Exist.json'));
    }

    public function testTooManyPathParamsIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        $handler = new SchemasHandler(['a.json', 'b.json']);
        $handler->handle($this->requestFor('GET', '/schemas/a.json/b.json'));
    }
}
