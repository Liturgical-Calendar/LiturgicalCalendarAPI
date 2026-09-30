<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\RegionalData;

use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(WiderRegionId::class)]
final class WiderRegionIdTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function validIds(): array
    {
        return ['one word' => ['europe'], 'several words' => ['german-language-area'], 'two letters' => ['ab']];
    }

    #[DataProvider('validIds')]
    public function testValidIds(string $id): void
    {
        self::assertTrue(WiderRegionId::isValid($id));
        self::assertSame([$id, false], WiderRegionId::normalize($id));
    }

    /** @return array<string, array{string}> */
    public static function invalidInputs(): array
    {
        return [
            'empty'                   => [''],
            'space'                   => ['middle east'],
            'leading hyphen'          => ['-europe'],
            'double hyphen'           => ['middle--east'],
            'trailing hyphen'         => ['europe-'],
            'digit'                   => ['region1'],
            'underscore'              => ['middle_east'],
            'mixed case kebab'        => ['Middle-East'],
            'legacy with space'       => ['Europe '],
            'diacritic'               => ['européen'],
            'trailing newline'        => ["europe\n"],
            'legacy trailing newline' => ["Europe\n"],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputsAreNeitherShape(string $input): void
    {
        self::assertFalse(WiderRegionId::isValid($input));
        self::assertNull(WiderRegionId::normalize($input));
    }

    /** @return array<string, array{string, string}> */
    public static function legacyNames(): array
    {
        return [
            'one word'      => ['Europe', 'europe'],
            'two words'     => ['Middle East', 'middle-east'],
            'camel in word' => ['EastIndies', 'eastindies'],
        ];
    }

    #[DataProvider('legacyNames')]
    public function testLegacyNamesMapToIds(string $legacy, string $id): void
    {
        self::assertTrue(WiderRegionId::isLegacy($legacy));
        self::assertFalse(WiderRegionId::isValid($legacy));
        self::assertSame([$id, true], WiderRegionId::normalize($legacy));
    }

    public function testHumanize(): void
    {
        self::assertSame('German Language Area', WiderRegionId::humanize('german-language-area'));
        self::assertSame('Europe', WiderRegionId::humanize('europe'));
    }

    public function testIdsFromNormalizesDeduplicatesAndSkips(): void
    {
        self::assertSame(
            ['europe', 'middle-east'],
            WiderRegionId::idsFrom(['Europe', 'middle-east', 'europe', 'Middle East', 'bad_value', 7, null])
        );
        self::assertSame([], WiderRegionId::idsFrom([]));
    }
}
