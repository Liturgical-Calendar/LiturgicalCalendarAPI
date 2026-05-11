<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\MissalsHandler;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MissalsHandler::class)]
final class MissalsHandlerTest extends AbstractHandlerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The handler caches its built index on a static; reset so each test
        // starts cold and we can also verify the build path.
        MissalsHandler::$missalsIndex = null;
    }

    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new MissalsHandler() )->handle(
            $this->requestFor('OPTIONS', '/missals', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testGetReturnsMissalsIndex(): void
    {
        $response = ( new MissalsHandler() )->handle(
            $this->requestFor('GET', '/missals', ['Accept-Language' => 'la'])
        );

        self::assertSame(200, $response->getStatusCode());
        // MissalsParams canonicalizes Accept-Language 'la' down to 'la'
        // (its short form) rather than expanding to 'la_VA'.
        self::assertSame('la', $response->getHeaderLine('X-Litcal-Missals-Locale'));
        $body = $this->decodeJsonBody($response);
        self::assertArrayHasKey('litcal_missals', $body);
        self::assertNotEmpty($body['litcal_missals']);
        // Each entry has a missal_id we can look up individually.
        self::assertArrayHasKey('missal_id', $body['litcal_missals'][0]);
    }

    public function testGetReturnsSingleMissalByPathId(): void
    {
        // First, discover a missal_id from the index.
        $indexResp = ( new MissalsHandler() )->handle(
            $this->requestFor('GET', '/missals', ['Accept-Language' => 'la'])
        );
        $missalId  = $this->decodeJsonBody($indexResp)['litcal_missals'][0]['missal_id'];
        self::assertIsString($missalId);

        $handler = new MissalsHandler([$missalId]);
        $resp    = $handler->handle($this->requestFor('GET', '/missals/' . $missalId, ['Accept-Language' => 'la']));

        self::assertSame(200, $resp->getStatusCode());
        $body = $this->decodeJsonBody($resp);
        // Single-missal response is an array of event rows.
        self::assertIsArray($body);
    }

    public function testUnknownMissalIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        ( new MissalsHandler(['NOT_A_REAL_MISSAL']) )
            ->handle($this->requestFor('GET', '/missals/NOT_A_REAL_MISSAL', ['Accept-Language' => 'la']));
    }

    public function testTooManyPathParamsIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        ( new MissalsHandler(['a', 'b']) )
            ->handle($this->requestFor('GET', '/missals/a/b', ['Accept-Language' => 'la']));
    }

    public function testPutIsMethodNotAllowed(): void
    {
        $this->expectException(MethodNotAllowedException::class);
        ( new MissalsHandler() )->handle(
            $this->requestFor('PUT', '/missals', ['Accept-Language' => 'la'], ['name' => 'fake'])
        );
    }

    public function testRegionFilterIsApplied(): void
    {
        $response = ( new MissalsHandler() )->handle(
            $this->requestFor('GET', '/missals?region=IT', ['Accept-Language' => 'la'])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('IT', $response->getHeaderLine('X-Litcal-Missals-Region'));
        $body = $this->decodeJsonBody($response);
        // Every returned missal must match the region filter.
        foreach ($body['litcal_missals'] as $missal) {
            self::assertSame('IT', $missal['region']);
        }
    }
}
