<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\LitSeason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LitSeason::class)]
final class LitSeasonTest extends TestCase
{
    public function testForEventKeyAdvent(): void
    {
        self::assertSame(LitSeason::ADVENT, LitSeason::forEventKey('Advent1'));
        self::assertSame(LitSeason::ADVENT, LitSeason::forEventKey('AdventWeekday1'));
    }

    public function testForEventKeyChristmas(): void
    {
        self::assertSame(LitSeason::CHRISTMAS, LitSeason::forEventKey('Christmas'));
        self::assertSame(LitSeason::CHRISTMAS, LitSeason::forEventKey('Epiphany'));
        self::assertSame(LitSeason::CHRISTMAS, LitSeason::forEventKey('BaptismLord'));
        self::assertSame(LitSeason::CHRISTMAS, LitSeason::forEventKey('HolyFamily'));
        self::assertSame(LitSeason::CHRISTMAS, LitSeason::forEventKey('MaryMotherOfGod'));
        self::assertSame(LitSeason::CHRISTMAS, LitSeason::forEventKey('DayAfterEpiphanyJan7'));
    }

    public function testForEventKeyLent(): void
    {
        self::assertSame(LitSeason::LENT, LitSeason::forEventKey('AshWednesday'));
        self::assertSame(LitSeason::LENT, LitSeason::forEventKey('Lent3'));
        self::assertSame(LitSeason::LENT, LitSeason::forEventKey('LentWeekday2Mon'));
        self::assertSame(LitSeason::LENT, LitSeason::forEventKey('PalmSun'));
        self::assertSame(LitSeason::LENT, LitSeason::forEventKey('MonHolyWeek'));
        self::assertSame(LitSeason::LENT, LitSeason::forEventKey('HolyThursChrism'));
        self::assertSame(LitSeason::LENT, LitSeason::forEventKey('FridayAfterAshWednesday'));
    }

    public function testForEventKeyEasterTriduum(): void
    {
        self::assertSame(LitSeason::EASTER_TRIDUUM, LitSeason::forEventKey('HolyThurs'));
        self::assertSame(LitSeason::EASTER_TRIDUUM, LitSeason::forEventKey('GoodFri'));
        self::assertSame(LitSeason::EASTER_TRIDUUM, LitSeason::forEventKey('EasterVigil'));
    }

    public function testForEventKeyEaster(): void
    {
        self::assertSame(LitSeason::EASTER, LitSeason::forEventKey('Easter'));
        self::assertSame(LitSeason::EASTER, LitSeason::forEventKey('Easter2'));
        self::assertSame(LitSeason::EASTER, LitSeason::forEventKey('MonOctaveEaster'));
        self::assertSame(LitSeason::EASTER, LitSeason::forEventKey('EasterWeekday3Tue'));
        self::assertSame(LitSeason::EASTER, LitSeason::forEventKey('Ascension'));
        self::assertSame(LitSeason::EASTER, LitSeason::forEventKey('Pentecost'));
    }

    public function testForEventKeyDefaultsToOrdinaryTime(): void
    {
        self::assertSame(LitSeason::ORDINARY_TIME, LitSeason::forEventKey('OrdSunday5'));
        self::assertSame(LitSeason::ORDINARY_TIME, LitSeason::forEventKey('CorpusChristi'));
        self::assertSame(LitSeason::ORDINARY_TIME, LitSeason::forEventKey('Trinity'));
    }

    public function testI18nLatin(): void
    {
        self::assertSame('Tempus Adventus', LitSeason::ADVENT->i18n(LitLocale::LATIN));
        self::assertSame('Tempus Nativitatis', LitSeason::CHRISTMAS->i18n(LitLocale::LATIN));
        self::assertSame('Tempus Quadragesima', LitSeason::LENT->i18n(LitLocale::LATIN));
        self::assertSame('Triduum Paschale', LitSeason::EASTER_TRIDUUM->i18n(LitLocale::LATIN));
        self::assertSame('Tempus Paschale', LitSeason::EASTER->i18n(LitLocale::LATIN));
        self::assertSame('Tempus per annum', LitSeason::ORDINARY_TIME->i18n(LitLocale::LATIN));
    }

    public function testI18nNonLatinReturnsTranslatedString(): void
    {
        // No catalog loaded → falls back to msgid.
        self::assertSame('Advent', LitSeason::ADVENT->i18n('en_US'));
        self::assertSame('Christmas', LitSeason::CHRISTMAS->i18n('en_US'));
    }
}
