<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Params;

use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Params\EasterParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EasterParams::class)]
final class EasterParamsTest extends TestCase
{
    public function testEmptyParamsLeavesLocaleNull(): void
    {
        $params = new EasterParams([]);

        self::assertNull($params->Locale);
    }

    public function testValidLocalePopulatesBothLocaleAndBaseLocale(): void
    {
        $params = new EasterParams(['locale' => 'en_US']);

        self::assertSame('en_US', $params->Locale);
        self::assertSame('en', $params->baseLocale);
    }

    public function testLatinLocaleIsAccepted(): void
    {
        $params = new EasterParams(['locale' => 'la_VA']);

        self::assertSame('la_VA', $params->Locale);
        self::assertSame('la', $params->baseLocale);
    }

    public function testCanonicalizationNormalisesHyphenAndCase(): void
    {
        $params = new EasterParams(['locale' => 'fr-fr']);

        self::assertSame('fr_FR', $params->Locale);
        self::assertSame('fr', $params->baseLocale);
    }

    public function testUnsupportedLocaleThrowsValidationException(): void
    {
        // \Locale::canonicalize is permissive and rarely returns null; it
        // normalises 'not-a-locale' into 'not@a=locale' which then trips the
        // LitLocale::isValid branch.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('for param `locale`');

        new EasterParams(['locale' => 'not-a-locale']);
    }

    public function testUnknownLocaleValueThrowsValidationException(): void
    {
        // Canonicalizes successfully but is not in the supported set.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('xx_ZZ');

        new EasterParams(['locale' => 'xx_ZZ']);
    }

    public function testUnrelatedParamsAreIgnored(): void
    {
        $params = new EasterParams(['ignored' => 'value']);

        self::assertNull($params->Locale);
    }
}
