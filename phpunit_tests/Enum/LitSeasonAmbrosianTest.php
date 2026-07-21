<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\LitSeason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LitSeason::class)]
final class LitSeasonAmbrosianTest extends TestCase
{
    public function testAfterEpiphanyAndAfterPentecostCasesExist(): void
    {
        self::assertSame('AFTER_EPIPHANY', LitSeason::AFTER_EPIPHANY->value);
        self::assertSame('AFTER_PENTECOST', LitSeason::AFTER_PENTECOST->value);
    }

    public function testAfterEpiphanyAndAfterPentecostAreValidFromValue(): void
    {
        self::assertSame(LitSeason::AFTER_EPIPHANY, LitSeason::from('AFTER_EPIPHANY'));
        self::assertSame(LitSeason::AFTER_PENTECOST, LitSeason::from('AFTER_PENTECOST'));
    }

    public function testForEventKeyDedicationDuomoMapsToAfterPentecost(): void
    {
        self::assertSame(LitSeason::AFTER_PENTECOST, LitSeason::forEventKey('DedicationDuomo'));
    }

    public function testForEventKeyAmbrosianAfterEpiphanyKeysMapToAfterEpiphany(): void
    {
        self::assertSame(LitSeason::AFTER_EPIPHANY, LitSeason::forEventKey('AfterEpiphany2'));
        self::assertSame(LitSeason::AFTER_EPIPHANY, LitSeason::forEventKey('AfterEpiphanyWeekday3'));
    }

    public function testForEventKeyAmbrosianAfterPentecostKeysMapToAfterPentecost(): void
    {
        self::assertSame(LitSeason::AFTER_PENTECOST, LitSeason::forEventKey('AfterPentecost4'));
        self::assertSame(LitSeason::AFTER_PENTECOST, LitSeason::forEventKey('AfterPentecostWeekday1'));
    }

    public function testExistingRomanKeysAreUnchanged(): void
    {
        self::assertSame(LitSeason::ADVENT, LitSeason::forEventKey('Advent1'));
        self::assertSame(LitSeason::LENT, LitSeason::forEventKey('Lent1'));
        self::assertSame(LitSeason::EASTER, LitSeason::forEventKey('Easter2'));
        self::assertSame(LitSeason::ORDINARY_TIME, LitSeason::forEventKey('OrdSunday2'));

        // Near-miss Roman keys that share a textual root with the new Ambrosian
        // patterns: guard against a future pattern-reorder stealing these matches.
        self::assertSame(LitSeason::CHRISTMAS, LitSeason::forEventKey('Epiphany'));
        self::assertSame(LitSeason::EASTER, LitSeason::forEventKey('Pentecost'));
        self::assertSame(LitSeason::CHRISTMAS, LitSeason::forEventKey('DayAfterEpiphanyJan7'));
    }

    public function testI18nLatin(): void
    {
        self::assertSame('Tempus post Epiphaniam', LitSeason::AFTER_EPIPHANY->i18n(LitLocale::LATIN));
        self::assertSame('Tempus post Pentecosten', LitSeason::AFTER_PENTECOST->i18n(LitLocale::LATIN));
    }
}
