<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar;

use LiturgicalCalendar\Api\Enum\LitCommon;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Models\Calendar\LitCommons;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `LitCommons::fullTranslate()` must render wholly in Latin for either accepted Latin
 * form, never as a mixture (#865).
 *
 * The method builds one string from four locale-sensitive pieces: the `De Commune`
 * prefix, the common's own name via `i18n()`, its possessive via `getPossessive()`,
 * and the `vel` glue between multiple commons. Those four did not all ask the same
 * question — `i18n()` and `getPossessive()` accepted both `la` and `la_VA`, while the
 * prefix and the glue tested only `la`. Given `la_VA` the result was half Latin and
 * half English: "From the Common Martyrum: … ; or …".
 */
#[CoversClass(LitCommons::class)]
final class LitCommonsLatinFormsTest extends TestCase
{
    /**
     * Two commons, so the `De Commune` prefix and the `vel` glue are both exercised
     * alongside the name and possessive.
     */
    private function multipleCommons(): LitCommons
    {
        $commons = LitCommons::create([LitCommon::MARTYRUM, LitCommon::PASTORUM]);
        self::assertNotNull($commons);
        return $commons;
    }

    public function testBothLatinFormsProduceIdenticalOutput(): void
    {
        $commons = $this->multipleCommons();

        self::assertSame(
            $commons->fullTranslate(LitLocale::LATIN_PRIMARY_LANGUAGE),
            $commons->fullTranslate(LitLocale::LATIN),
            'fullTranslate() must not depend on which Latin form it is handed (#865).'
        );
    }

    public function testFullLocaleLatinFormIsNotAMixture(): void
    {
        $translated = $this->multipleCommons()->fullTranslate(LitLocale::LATIN);

        self::assertStringContainsString('De Commune', $translated, 'the prefix must be Latin');
        self::assertStringContainsString('vel', $translated, 'the glue between commons must be Latin');
        self::assertStringNotContainsString('From the Common', $translated);
        self::assertStringNotContainsString('; or ', $translated);
    }

    public function testPrimaryLanguageLatinFormIsNotAMixture(): void
    {
        $translated = $this->multipleCommons()->fullTranslate(LitLocale::LATIN_PRIMARY_LANGUAGE);

        self::assertStringContainsString('De Commune', $translated);
        self::assertStringContainsString('vel', $translated);
        self::assertStringNotContainsString('From the Common', $translated);
        self::assertStringNotContainsString('; or ', $translated);
    }

    /**
     * The complement: a non-Latin locale must still take the translated path, so the
     * widening above cannot be mistaken for "always Latin".
     */
    public function testNonLatinLocaleIsNotRenderedInLatin(): void
    {
        $translated = $this->multipleCommons()->fullTranslate('en_US');

        self::assertStringNotContainsString('De Commune', $translated);
        self::assertStringContainsString('From the Common', $translated);
    }
}
