<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\SupportedLocales;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SupportedLocales::class)]
final class SupportedLocalesTest extends TestCase
{
    protected function setUp(): void
    {
        SupportedLocales::reset();
    }

    protected function tearDown(): void
    {
        SupportedLocales::reset();
    }

    public function testTheCuratedResourceIsReadable(): void
    {
        $official = SupportedLocales::official();

        self::assertNotEmpty($official);
        self::assertContainsOnlyString($official);
    }

    /**
     * Pins the list the API has always advertised. Changing it is a deliberate
     * governance decision, so it should require editing this test too.
     */
    public function testTheOfficialListMatchesWhatTheApiAdvertises(): void
    {
        self::assertSame(['en', 'fr', 'it', 'la', 'nl'], SupportedLocales::official());
    }

    #[DataProvider('officialLocales')]
    public function testOfficialLocalesAreRecognised(string $locale): void
    {
        self::assertTrue(SupportedLocales::isOfficial($locale));
    }

    /** @return array<string, array{string}> */
    public static function officialLocales(): array
    {
        return [
            'bare language'  => ['en'],
            'full locale'    => ['en_US'],
            'latin bare'     => ['la'],
            'latin vatican'  => ['la_VA'],
            'dutch regional' => ['nl_NL'],
        ];
    }

    #[DataProvider('unofficialLocales')]
    public function testUnofficialLocalesAreNotRecognised(string $locale): void
    {
        self::assertFalse(SupportedLocales::isOfficial($locale));
    }

    /** @return array<string, array{string}> */
    public static function unofficialLocales(): array
    {
        return [
            'croatian' => ['hr'],
            'spanish'  => ['es'],
            'german'   => ['de'],
            'empty'    => [''],
            'nonsense' => ['zz_ZZ'],
        ];
    }

    public function testCroatianIsNotYetOfficial(): void
    {
        // Croatian has a complete lectionary across all ten corpora and a gettext
        // catalogue, but incomplete decreed-event names. Promoting it is tracked
        // in the resource's `candidates` block; until then it must degrade, not throw.
        self::assertFalse(SupportedLocales::isOfficial('hr'));
    }

    public function testTheFallbackMatchesTheHistoricalConstant(): void
    {
        self::assertSame(['en', 'fr', 'it', 'la', 'nl'], SupportedLocales::FALLBACK);
    }
}
