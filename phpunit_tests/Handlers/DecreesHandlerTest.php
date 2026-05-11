<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\DecreesHandler;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DecreesHandler::class)]
final class DecreesHandlerTest extends AbstractHandlerTestCase
{
    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new DecreesHandler() )->handle(
            $this->requestFor('OPTIONS', '/decrees', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testGetReturnsDecreesIndex(): void
    {
        $response = ( new DecreesHandler() )->handle(
            $this->requestFor('GET', '/decrees', ['Accept-Language' => 'la'])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertArrayHasKey('litcal_decrees', $body);
        self::assertNotEmpty($body['litcal_decrees']);
        // Each entry has a decree_id we can look up individually.
        self::assertNotEmpty($body['litcal_decrees'][0]['decree_id']);
    }

    public function testGetSingleDecreeReturnsThatDecree(): void
    {
        // Discover the first decree id from the index, then ask for it by id.
        $indexResp = ( new DecreesHandler() )->handle(
            $this->requestFor('GET', '/decrees', ['Accept-Language' => 'la'])
        );
        $decreeId  = $this->decodeJsonBody($indexResp)['litcal_decrees'][0]['decree_id'];
        self::assertIsString($decreeId);
        self::assertNotEmpty($decreeId);

        $handler = new DecreesHandler([$decreeId]);
        $resp    = $handler->handle($this->requestFor('GET', '/decrees/' . $decreeId, ['Accept-Language' => 'la']));

        self::assertSame(200, $resp->getStatusCode());
        $body = $this->decodeJsonBody($resp);
        self::assertSame($decreeId, $body['decree_id']);
    }

    public function testUnknownDecreeIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        ( new DecreesHandler(['totally-not-a-real-decree-id']) )
            ->handle($this->requestFor('GET', '/decrees/totally-not-a-real-decree-id', ['Accept-Language' => 'la']));
    }

    public function testTooManyPathParamsIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        ( new DecreesHandler(['a', 'b']) )
            ->handle($this->requestFor('GET', '/decrees/a/b', ['Accept-Language' => 'la']));
    }

    public function testPutIsNotImplemented(): void
    {
        $resp = ( new DecreesHandler() )->handle(
            $this->requestFor(
                'PUT',
                '/decrees',
                ['Accept-Language' => 'la'],
                ['decree_id' => 'fake']
            )
        );
        self::assertSame(405, $resp->getStatusCode());
    }
}
