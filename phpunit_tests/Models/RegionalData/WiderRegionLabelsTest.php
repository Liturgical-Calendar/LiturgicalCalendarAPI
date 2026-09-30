<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\RegionalData;

use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionLabels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(WiderRegionLabels::class)]
final class WiderRegionLabelsTest extends TestCase
{
    public function testAllowedKeysAreEnglishPlusEachDeclaredLanguageAndScript(): void
    {
        self::assertSame(
            ['en', 'it', 'de', 'zh', 'zh_Hans'],
            WiderRegionLabels::allowedKeys(['it_IT', 'it_CH', 'de_AT', 'zh_Hans_CN'])
        );
    }

    public function testValidateAcceptsObjectsAndArraysAndTrims(): void
    {
        $locales = ['it_IT', 'de_DE'];
        self::assertSame(
            ['en' => 'Europe', 'it' => 'Europa'],
            WiderRegionLabels::validate((object) ['en' => 'Europe', 'it' => ' Europa '], $locales)
        );
        self::assertSame(['de' => 'Europa'], WiderRegionLabels::validate(['de' => 'Europa'], $locales));
        self::assertSame([], WiderRegionLabels::validate(null, $locales));
    }

    /** @return array<string, array{mixed}> */
    public static function badLabels(): array
    {
        return [
            'not a map'           => ['Europe'],
            'undeclared language' => [['sw' => 'Ulaya']],
            'full locale as key'  => [['it_IT' => 'Europa']],
            'empty value'         => [['it' => '  ']],
            'non-string value'    => [['it' => 42]],
            'list instead of map' => [['Europa']],
            'trailing newline'    => [["it\n" => 'x']],
        ];
    }

    #[DataProvider('badLabels')]
    public function testValidateRefuses(mixed $labels): void
    {
        $this->expectException(\ValueError::class);
        WiderRegionLabels::validate($labels, ['it_IT', 'de_DE']);
    }

    /** @return array<string, array{?string, string}> */
    public static function resolutions(): array
    {
        return [
            'exact language'                => ['it_IT', 'Europa (it)'],
            'lowercase negotiated tag'      => ['it_it', 'Europa (it)'],
            'script beats bare language'    => ['zh_hans_cn', '欧洲'],
            'other script falls to english' => ['zh_Hant_TW', 'Europe'],
            'undeclared falls to english'   => ['sw_KE', 'Europe'],
            'no locale falls to english'    => [null, 'Europe'],
        ];
    }

    #[DataProvider('resolutions')]
    public function testResolve(?string $locale, string $expected): void
    {
        $labels = ['en' => 'Europe', 'it' => 'Europa (it)', 'zh_Hans' => '欧洲'];
        self::assertSame($expected, WiderRegionLabels::resolve($labels, $locale, 'europe'));
    }

    /** @return array<string, array{string, string}> */
    public static function asiaResolutions(): array
    {
        return [
            'traditional script tag'           => ['zh_Hant_TW', 'Asia'],
            'lowercase traditional script tag' => ['zh_hant_tw', 'Asia'],
            'taiwan implies traditional'       => ['zh_TW', 'Asia'],
            'hong kong implies traditional'    => ['zh_HK', 'Asia'],
            'mainland implies simplified'      => ['zh_CN', '亚洲'],
            'simplified script tag'            => ['zh_Hans_CN', '亚洲'],
            'bare chinese implies simplified'  => ['zh', '亚洲'],
        ];
    }

    /**
     * A request for a script the labels do not cover gets English, never the other script's text through the bare
     * language key: asia.json labels `zh` and `zh_Hans` alike, both Simplified.
     */
    #[DataProvider('asiaResolutions')]
    public function testResolveNeverServesTheOtherScriptThroughTheBareLanguage(string $locale, string $expected): void
    {
        $path = dirname(__DIR__, 3) . '/jsondata/sourcedata/rite/roman/calendars/wider_regions/asia/asia.json';
        $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertIsArray($json['metadata']);
        /** @var array<string, string> $labels */
        $labels = $json['metadata']['labels'];
        self::assertSame($expected, WiderRegionLabels::resolve($labels, $locale, 'asia'));
    }

    public function testResolveWithoutEnglishHumanizesTheId(): void
    {
        self::assertSame('German Language Area', WiderRegionLabels::resolve(['de' => 'Deutsches Sprachgebiet'], 'fr_FR', 'german-language-area'));
        self::assertSame('German Language Area', WiderRegionLabels::resolve([], 'de_DE', 'german-language-area'));
    }
}
