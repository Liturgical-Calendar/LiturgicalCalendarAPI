<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\LitLocale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LitLocale::class)]
final class LitLocaleTest extends TestCase
{
    public function testLatinConstantsAndDefaults(): void
    {
        self::assertSame('la_VA', LitLocale::LATIN);
        self::assertSame('la', LitLocale::LATIN_PRIMARY_LANGUAGE);
        self::assertContains('la', LitLocale::$values);
        self::assertContains('la_VA', LitLocale::$values);
    }

    public function testIsValidRecognisesLatin(): void
    {
        self::assertTrue(LitLocale::isValid('la'));
        self::assertTrue(LitLocale::isValid('la_VA'));
    }

    public function testIsValidRecognisesIcuLocaleAfterInit(): void
    {
        LitLocale::init();
        // 'en' should always be present in ICU data.
        self::assertTrue(LitLocale::isValid('en'));
        self::assertFalse(LitLocale::isValid('definitely-not-a-locale'));
    }

    public function testAreValid(): void
    {
        self::assertTrue(LitLocale::areValid(['la', 'la_VA']));
        self::assertFalse(LitLocale::areValid(['la', 'not-a-locale']));
    }

    public function testGetSupportedLocalesIncludesLatin(): void
    {
        LitLocale::init();
        $locales = LitLocale::getSupportedLocales();
        self::assertContains('la', $locales);
        self::assertContains('la_VA', $locales);
    }

    public function testInitFiltersOutPosix(): void
    {
        // Reset to force re-initialization.
        LitLocale::$AllAvailableLocales = [];
        LitLocale::init();
        foreach (LitLocale::$AllAvailableLocales as $locale) {
            self::assertStringNotContainsString('POSIX', $locale);
        }
    }
}
