<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Decrees;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemMakeDoctorMetadata;
use LiturgicalCalendar\Api\Models\Decrees\UrlsLangs;
use LiturgicalCalendar\Api\Models\RegionalData\UrlLangMap;
use PHPUnit\Framework\TestCase;

/**
 * Covers the `urls_langs` per-language override and its interaction with the
 * `url` + `url_lang_map` template.
 *
 * The motivating case is the decree declaring John Henry Newman a Doctor of the
 * Church: six languages share one template, while the Latin text is published as
 * an annex to the Italian page and so differs in both path segment and filename.
 */
final class DecreeUrlsLangsOverrideTest extends TestCase
{
    private const NEWMAN_TEMPLATE = 'https://www.vatican.va/content/romancuria/%s/dicasteri/dicastero-culto-divino-e-disciplina-sacramenti/documenti/20251109-decreto-iscrizione-newman.html';
    private const NEWMAN_LATIN    = 'https://www.vatican.va/content/romancuria/it/dicasteri/dicastero-culto-divino-e-disciplina-sacramenti/documenti/20251109-annesso-decreto-iscrizione-newman-la.html';

    private static string $originalPrimaryLanguage;

    public static function setUpBeforeClass(): void
    {
        self::$originalPrimaryLanguage = LitLocale::$PRIMARY_LANGUAGE;
    }

    protected function tearDown(): void
    {
        LitLocale::$PRIMARY_LANGUAGE = self::$originalPrimaryLanguage;
    }

    /** @param array<string,string> $urlsLangs */
    private static function newmanMetadata(array $urlsLangs = []): DecreeItemMakeDoctorMetadata
    {
        $data = [
            'action'       => 'makeDoctor',
            'since_year'   => 2026,
            'url'          => self::NEWMAN_TEMPLATE,
            'url_lang_map' => ['de' => 'de', 'en' => 'en', 'es' => 'es', 'fr' => 'fr', 'it' => 'it', 'pt' => 'pt'],
        ];
        if ([] !== $urlsLangs) {
            $data['urls_langs'] = $urlsLangs;
        }
        return DecreeItemMakeDoctorMetadata::fromArray($data);
    }

    private static function hrefOf(string $html): string
    {
        self::assertSame(1, preg_match('/href="([^"]+)"/', $html, $m), 'expected a single href in the rendered link');
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    public function testOverrideWinsForTheLanguageItNames(): void
    {
        LitLocale::$PRIMARY_LANGUAGE = 'la';
        $metadata                    = self::newmanMetadata(['la' => self::NEWMAN_LATIN]);

        self::assertSame(self::NEWMAN_LATIN, self::hrefOf($metadata->getUrl()));
    }

    public function testLanguagesWithoutAnOverrideStillUseTheTemplate(): void
    {
        $metadata = self::newmanMetadata(['la' => self::NEWMAN_LATIN]);

        foreach (['de', 'en', 'es', 'fr', 'it', 'pt'] as $lang) {
            LitLocale::$PRIMARY_LANGUAGE = $lang;
            self::assertSame(
                sprintf(self::NEWMAN_TEMPLATE, $lang),
                self::hrefOf($metadata->getUrl()),
                "language `{$lang}` should resolve through the template, not the override"
            );
        }
    }

    public function testWithoutAnyOverrideBehaviourIsUnchanged(): void
    {
        LitLocale::$PRIMARY_LANGUAGE = 'it';
        $metadata                    = self::newmanMetadata();

        self::assertSame(sprintf(self::NEWMAN_TEMPLATE, 'it'), self::hrefOf($metadata->getUrl()));
    }

    /**
     * A language absent from a non-empty override map must fall through to the template
     * rather than borrowing another language's document — the reason UrlsLangs::get()
     * deliberately has no la/en fallback.
     */
    public function testAbsentLanguageDoesNotBorrowAnotherLanguagesOverride(): void
    {
        LitLocale::$PRIMARY_LANGUAGE = 'en';
        $metadata                    = self::newmanMetadata(['la' => self::NEWMAN_LATIN]);

        $href = self::hrefOf($metadata->getUrl());
        self::assertNotSame(self::NEWMAN_LATIN, $href);
        self::assertSame(sprintf(self::NEWMAN_TEMPLATE, 'en'), $href);
    }

    public function testOverrideIsSerialisedSoClientsCanSeeIt(): void
    {
        $serialised = self::newmanMetadata(['la' => self::NEWMAN_LATIN])->jsonSerialize();

        self::assertArrayHasKey('urls_langs', $serialised);
        self::assertSame(['la' => self::NEWMAN_LATIN], $serialised['urls_langs']);
    }

    public function testAbsentOverrideIsOmittedFromSerialisation(): void
    {
        self::assertArrayNotHasKey('urls_langs', self::newmanMetadata()->jsonSerialize());
    }

    public function testGetReducesARegionalTagToItsPrimarySubtag(): void
    {
        $urlsLangs = UrlsLangs::fromArray(['en' => 'https://www.vatican.va/content/example-en.html']);

        self::assertSame('https://www.vatican.va/content/example-en.html', $urlsLangs->get('en-US'));
        self::assertNull($urlsLangs->get('de-DE'));
    }

    public function testEmptyOverrideMapIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UrlsLangs::fromArray([]);
    }

    /**
     * Regression: getBestLangFromMap() tested the primary subtag but indexed the map with the
     * full tag, so a regional tag matched the guard and then read a key that does not exist.
     */
    public function testUrlLangMapResolvesARegionalTagToItsPrimarySubtag(): void
    {
        $map = UrlLangMap::fromArray(['de' => 'ge', 'en' => 'en', 'la' => 'la']);

        self::assertSame('ge', $map->getBestLangFromMap('de-DE'));
        self::assertSame('en', $map->getBestLangFromMap('en-GB'));
    }
}
