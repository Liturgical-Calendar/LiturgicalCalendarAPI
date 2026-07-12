<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\LitLocale;
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

    public function testPutOnCollectionRootIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        ( new DecreesHandler() )->handle(
            $this->requestFor('PUT', '/decrees', ['Accept-Language' => 'en'], ['decree_id' => 'fake'])
        );
    }

    public function testGetDecreeIncludesReadingsFromDecreesLectionary(): void
    {
        $resp = ( new DecreesHandler(['MaryMotherChurch_Create']) )->handle(
            $this->requestFor('GET', '/decrees/MaryMotherChurch_Create', ['Accept-Language' => 'en'])
        );
        $body = $this->decodeJsonBody($resp);
        self::assertArrayHasKey('readings', $body['liturgical_event']);
        self::assertNotEmpty($body['liturgical_event']['readings']['first_reading']);
    }

    public function testGetDecreeWithoutLectionaryEntryOmitsReadingsKey(): void
    {
        // StMaryMagdalene_Upgrade event_key is not present in lectionary/en.json,
        // so `readings` must be ABSENT (not null) in the response.
        $resp = ( new DecreesHandler(['StMaryMagdalene_Upgrade']) )->handle(
            $this->requestFor('GET', '/decrees/StMaryMagdalene_Upgrade', ['Accept-Language' => 'en'])
        );
        $body = $this->decodeJsonBody($resp);
        self::assertArrayNotHasKey('readings', $body['liturgical_event']);
    }

    public function testGetDecreeReadingsFallBackToBaseLocale(): void
    {
        // Validates params-level normalization of regional Accept-Language tags:
        // DecreesParams normalizes `en-US` to the primary language `en`, so the handler
        // finds the `en` lectionary file directly, without any base-locale fallback logic.
        $resp = ( new DecreesHandler(['MaryMotherChurch_Create']) )->handle(
            $this->requestFor('GET', '/decrees/MaryMotherChurch_Create', ['Accept-Language' => 'en-US'])
        );
        $body = $this->decodeJsonBody($resp);
        self::assertArrayHasKey('readings', $body['liturgical_event']);
        self::assertNotEmpty($body['liturgical_event']['readings']['first_reading']);
    }

    /**
     * When Accept-Language contains only invalid/unsupported locales,
     * Negotiator::pickLanguage() returns null, causing the handler to fall back
     * to LitLocale::LATIN (line 155 of DecreesHandler).
     */
    public function testGetWithInvalidAcceptLanguageUsesLatinFallback(): void
    {
        // 'zz-XX' is not a recognized locale → pickLanguage returns null → else branch (line 155)
        $resp = ( new DecreesHandler() )->handle(
            $this->requestFor('GET', '/decrees', ['Accept-Language' => 'zz-XX'])
        );
        self::assertSame(200, $resp->getStatusCode());
        $body = $this->decodeJsonBody($resp);
        self::assertArrayHasKey('litcal_decrees', $body);
        self::assertNotEmpty($body['litcal_decrees']);
    }

    /**
     * POST to /decrees is treated like GET (lines 185-186 of the switch statement).
     * With an empty body, `parseBodyParams` returns null and the handler falls through
     * to the GET logic and returns the full index.
     */
    public function testPostWithoutBodyReturnsDecreesIndex(): void
    {
        $resp = ( new DecreesHandler() )->handle(
            $this->requestFor('POST', '/decrees', ['Accept-Language' => 'en'])
        );
        self::assertSame(200, $resp->getStatusCode());
        $body = $this->decodeJsonBody($resp);
        self::assertArrayHasKey('litcal_decrees', $body);
    }

    /**
     * POST to /decrees with a body containing a `locale` key merges the body param
     * (lines 162-166 of DecreesHandler). This exercises parseBodyParams and the
     * merge-when-not-null branch.
     */
    public function testPostWithLocaleBodyParamReturnsDecreesIndex(): void
    {
        $resp = ( new DecreesHandler() )->handle(
            $this->requestFor('POST', '/decrees', [], ['locale' => 'en'])
        );
        self::assertSame(200, $resp->getStatusCode());
        $body = $this->decodeJsonBody($resp);
        self::assertArrayHasKey('litcal_decrees', $body);
    }

    /**
     * A PUT request with a JSON array body (not an object) causes parseBodyPayload()
     * to return a list<\stdClass>, which is `!instanceof \stdClass`.
     * The handler must throw ValidationException ('Invalid payload') at line 173.
     */
    public function testPutWithJsonArrayBodyIsRejected(): void
    {
        // Send a JSON array as the body — parseBodyPayload($req, false) returns list<\stdClass>
        // which is !instanceof \stdClass, triggering line 173.
        $arrayBody = json_encode([['decree_id' => 'StTest_Create']]);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/Invalid payload/');
        ( new DecreesHandler(['StTest_Create']) )->handle(
            $this->requestFor('PUT', '/decrees/StTest_Create', ['Accept-Language' => 'en', 'Content-Type' => 'application/json'], $arrayBody)
        );
    }
}
