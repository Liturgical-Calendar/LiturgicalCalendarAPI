<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\LitLocale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers LitLocale::isLatin(), the single Latin-locale test introduced in #865 to
 * replace four different ad-hoc spellings across src/.
 *
 * Both accepted forms must answer true: `la_VA` is what the request layer settles on
 * (CalendarParams, EventsParams), while `la` is what LocaleConfigurator resolves at
 * runtime, since Latin has no installable system locale. Recognising only one of them
 * is what made the /events Masses for Various Needs commons render in English (#749).
 */
#[CoversClass(LitLocale::class)]
final class LitLocaleIsLatinTest extends TestCase
{
    /**
     * @return array<string,array{0:string}>
     */
    public static function latinForms(): array
    {
        return [
            'primary language subtag' => [LitLocale::LATIN_PRIMARY_LANGUAGE],
            'full locale'             => [LitLocale::LATIN],
            'literal la'              => ['la'],
            'literal la_VA'           => ['la_VA'],
        ];
    }

    #[DataProvider('latinForms')]
    public function testBothAcceptedLatinFormsAreRecognized(string $locale): void
    {
        self::assertTrue(LitLocale::isLatin($locale));
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function nonLatinLocales(): array
    {
        return [
            'Italian'                  => ['it_IT'],
            'Italian primary language' => ['it'],
            'English'                  => ['en_US'],
            'Latin American Spanish'   => ['es_419'],
            'empty string'             => [''],
            // Deliberately not Latin: the CLDR-maximized form is never what reaches the
            // rendering code, and treating it as Latin would paper over a params-layer
            // regression rather than surface it.
            'CLDR-maximized Latin'     => ['la_Latn_VA'],
            'hyphenated Latin'         => ['la-VA'],
            'lowercased region'        => ['la_va'],
        ];
    }

    #[DataProvider('nonLatinLocales')]
    public function testOtherLocalesAreNotLatin(string $locale): void
    {
        self::assertFalse(LitLocale::isLatin($locale));
    }
}
