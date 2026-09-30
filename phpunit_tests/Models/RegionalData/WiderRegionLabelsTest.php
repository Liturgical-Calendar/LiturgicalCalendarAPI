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

    public function testResolveWithoutEnglishHumanizesTheId(): void
    {
        self::assertSame('German Language Area', WiderRegionLabels::resolve(['de' => 'Deutsches Sprachgebiet'], 'fr_FR', 'german-language-area'));
        self::assertSame('German Language Area', WiderRegionLabels::resolve([], 'de_DE', 'german-language-area'));
    }
}
