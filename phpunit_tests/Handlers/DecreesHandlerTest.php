<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Handlers\DecreesHandler;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Router;
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

    public function testGetSingleDecreeAggregatesAllTranslationsAndReadings(): void
    {
        // MaryMotherChurch_Create has translations in many locales beyond the
        // GRC-live set and readings in several; the single GET must return them
        // all as i18n/readings maps (write-body shape) regardless of the request locale.
        //
        // Served from a throwaway copy in which its German name is blank, so the
        // exclusion of empty translations is tested against a gap this test makes,
        // not one a translation sync can close.
        $root  = self::rootWithBlankDecreeName('de', 'MaryMotherChurch');
        $saved = Router::$apiFilePath;
        try {
            Router::$apiFilePath = $root;
            $handler             = new DecreesHandler(['MaryMotherChurch_Create']);
            $resp                = $handler->handle($this->requestFor('GET', '/decrees/MaryMotherChurch_Create', ['Accept-Language' => 'la']));
        } finally {
            Router::$apiFilePath = $saved;
            self::removeTree($root);
        }

        self::assertSame(200, $resp->getStatusCode());
        $body = $this->decodeJsonBody($resp);

        self::assertArrayHasKey('i18n', $body);
        self::assertIsArray($body['i18n']);
        // English + Italian + a non-GRC-live locale (Spanish) are all present…
        self::assertSame('Blessed Virgin Mary, Mother of the Church', $body['i18n']['en']);
        self::assertArrayHasKey('it', $body['i18n']);
        self::assertArrayHasKey('es', $body['i18n']);
        // …and empty translations (e.g. de) are excluded.
        self::assertArrayNotHasKey('de', $body['i18n']);

        self::assertArrayHasKey('readings', $body);
        self::assertIsArray($body['readings']);
        self::assertArrayHasKey('en', $body['readings']);
        self::assertArrayHasKey('first_reading', $body['readings']['en']);
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

    /** A throwaway root whose `$language` name for `$eventKey` is blank. */
    private static function rootWithBlankDecreeName(string $language, string $eventKey): string
    {
        $repo = dirname(__DIR__, 2) . '/';
        $root = sys_get_temp_dir() . '/litcal-decrees-' . bin2hex(random_bytes(6)) . '/';
        self::copyTree($repo . 'jsondata', $root . 'jsondata');
        symlink($repo . 'i18n', $root . 'i18n');

        $file = $root . "jsondata/sourcedata/rite/roman/decrees/i18n/{$language}.json";
        /** @var array<string, string> $names */
        $names            = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $names[$eventKey] = '';
        file_put_contents($file, json_encode($names, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $root;
    }

    private static function copyTree(string $from, string $to): void
    {
        mkdir($to, 0o755, true);
        /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $it */
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $target = $to . DIRECTORY_SEPARATOR . $it->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0o755, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            // No FOLLOW_SYMLINKS: i18n is a symlink into the repository.
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink() || !$item->isDir()) {
                unlink($item->getPathname());
                continue;
            }
            rmdir($item->getPathname());
        }
        rmdir($dir);
    }
}
