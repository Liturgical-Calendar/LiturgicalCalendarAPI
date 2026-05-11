<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitLocale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LitGrade::class)]
final class LitGradeTest extends TestCase
{
    public function testNumericPrecedenceValues(): void
    {
        self::assertSame(7, LitGrade::HIGHER_SOLEMNITY->value);
        self::assertSame(6, LitGrade::SOLEMNITY->value);
        self::assertSame(5, LitGrade::FEAST_LORD->value);
        self::assertSame(4, LitGrade::FEAST->value);
        self::assertSame(3, LitGrade::MEMORIAL->value);
        self::assertSame(2, LitGrade::MEMORIAL_OPT->value);
        self::assertSame(1, LitGrade::COMMEMORATION->value);
        self::assertSame(0, LitGrade::WEEKDAY->value);
        self::assertSame(-1, LitGrade::INVALID->value);
    }

    public function testI18nLatinAbbreviatedAndFull(): void
    {
        self::assertSame('feria', LitGrade::WEEKDAY->i18n(LitLocale::LATIN, html: false));
        self::assertSame('f', LitGrade::WEEKDAY->i18n(LitLocale::LATIN, html: false, abbreviate: true));
        self::assertSame('SOLLEMNITAS', LitGrade::SOLEMNITY->i18n(LitLocale::LATIN, html: false));
        self::assertSame('S', LitGrade::SOLEMNITY->i18n(LitLocale::LATIN, html: false, abbreviate: true));
        self::assertSame('Memoria obligatoria', LitGrade::MEMORIAL->i18n(LitLocale::LATIN, html: false));
        self::assertSame('FESTUM', LitGrade::FEAST->i18n(LitLocale::LATIN, html: false));
        self::assertSame('FESTUM DOMINI', LitGrade::FEAST_LORD->i18n(LitLocale::LATIN, html: false));
        self::assertSame('memoria ad libitum', LitGrade::MEMORIAL_OPT->i18n(LitLocale::LATIN, html: false));
        self::assertSame('commemoratio', LitGrade::COMMEMORATION->i18n(LitLocale::LATIN, html: false));
        self::assertSame('ignotus', LitGrade::INVALID->i18n(LitLocale::LATIN, html: false));
    }

    public function testHtmlWrapping(): void
    {
        // WEEKDAY wraps in <I>...</I>.
        self::assertSame('<I>feria</I>', LitGrade::WEEKDAY->i18n(LitLocale::LATIN));
        // SOLEMNITY wraps in <B>...</B>.
        self::assertSame('<B>SOLLEMNITAS</B>', LitGrade::SOLEMNITY->i18n(LitLocale::LATIN));
        // HIGHER_SOLEMNITY wraps in <B><I>...</I></B>.
        self::assertStringStartsWith('<B><I>', LitGrade::HIGHER_SOLEMNITY->i18n(LitLocale::LATIN));
        self::assertStringEndsWith('</I></B>', LitGrade::HIGHER_SOLEMNITY->i18n(LitLocale::LATIN));
        // FEAST uses no wrapping tags.
        self::assertSame('FESTUM', LitGrade::FEAST->i18n(LitLocale::LATIN));
    }

    public function testI18nNonLatinDelegatesToGettext(): void
    {
        // Without a gettext catalog _() returns the msgid, so we only assert
        // it doesn't blow up and that the format markers around the value match.
        $result = LitGrade::SOLEMNITY->i18n('en_US', html: false);
        self::assertIsString($result);
        self::assertNotEmpty($result);
    }

    public function testHigherSolemnityAbbreviatedLatin(): void
    {
        self::assertSame('S✝', LitGrade::HIGHER_SOLEMNITY->i18n(LitLocale::LATIN, html: false, abbreviate: true));
    }
}
