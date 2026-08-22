<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\LikelySubtags;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the shared CLDR likelySubtags reader extracted in #749 from
 * CalendarParams::maximizeLocale() and LocaleConfigurator::likelyRegion().
 *
 * Both former readers had contracts the extraction must preserve exactly:
 * maximize() returns the canonicalized full tag (script included) or the input
 * unchanged on a miss; regionFor() returns the bare region subtag or '' on a miss.
 */
#[CoversClass(LikelySubtags::class)]
final class LikelySubtagsTest extends TestCase
{
    private string $savedApiFilePath = '';

    protected function setUp(): void
    {
        // JsonData::FOLDER->path() prefixes Router::$apiFilePath; point it at the repo root.
        $this->savedApiFilePath = isset(Router::$apiFilePath) ? Router::$apiFilePath : '';
        Router::$apiFilePath    = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    }

    protected function tearDown(): void
    {
        Router::$apiFilePath = $this->savedApiFilePath;
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function maximizedLanguages(): array
    {
        return [
            'English maximizes to the US variant'        => ['en', 'en_Latn_US'],
            'French maximizes to the FR variant'         => ['fr', 'fr_Latn_FR'],
            'Portuguese maximizes to the Brazil variant' => ['pt', 'pt_Latn_BR'],
        ];
    }

    #[DataProvider('maximizedLanguages')]
    public function testMaximizeReturnsTheCanonicalizedFullTag(string $language, string $expected): void
    {
        self::assertSame($expected, LikelySubtags::maximize($language));
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function likelyRegions(): array
    {
        return [
            'English → US'    => ['en', 'US'],
            'French → FR'     => ['fr', 'FR'],
            'Portuguese → BR' => ['pt', 'BR'],
        ];
    }

    #[DataProvider('likelyRegions')]
    public function testRegionForReturnsTheBareRegionSubtag(string $language, string $expected): void
    {
        self::assertSame($expected, LikelySubtags::regionFor($language));
    }

    public function testMaximizeReturnsTheInputUnchangedForAnUnknownLanguage(): void
    {
        self::assertSame('qqq', LikelySubtags::maximize('qqq'));
    }

    public function testRegionForReturnsAnEmptyStringForAnUnknownLanguage(): void
    {
        self::assertSame('', LikelySubtags::regionFor('qqq'));
    }

    /**
     * The region subtag must never carry the script along: glibc rejects locale
     * names such as "en_Latn_US", so LocaleConfigurator builds "en_US" from
     * language + region and depends on regionFor() returning the region alone.
     */
    public function testRegionForStripsTheScriptSubtag(): void
    {
        $maximized = LikelySubtags::maximize('en');
        self::assertStringContainsString('Latn', $maximized, 'Precondition: the maximized tag carries a script subtag.');
        self::assertSame('US', LikelySubtags::regionFor('en'));
    }

    /**
     * Both shapes are served from one cached map; repeated calls must be stable.
     */
    public function testRepeatedCallsAreStable(): void
    {
        self::assertSame(LikelySubtags::maximize('de'), LikelySubtags::maximize('de'));
        self::assertSame(LikelySubtags::regionFor('de'), LikelySubtags::regionFor('de'));
    }
}
