<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LectionaryCategory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LectionaryCategory::class)]
final class LectionaryCategoryTest extends TestCase
{
    public function testForEventKeyAdvent(): void
    {
        self::assertSame(LectionaryCategory::WEEKDAYS_ADVENT, LectionaryCategory::forEventKey('AdventWeekday1Mon'));
        self::assertSame(LectionaryCategory::WEEKDAYS_ADVENT, LectionaryCategory::forEventKey('AdventWeekdayDec18'));
    }

    public function testForEventKeyChristmas(): void
    {
        self::assertSame(LectionaryCategory::WEEKDAYS_CHRISTMAS, LectionaryCategory::forEventKey('ChristmasWeekdayDec29'));
        self::assertSame(LectionaryCategory::WEEKDAYS_CHRISTMAS, LectionaryCategory::forEventKey('DayAfterEpiphany'));
    }

    public function testForEventKeyLent(): void
    {
        self::assertSame(LectionaryCategory::WEEKDAYS_LENT, LectionaryCategory::forEventKey('AshWednesday'));
        self::assertSame(LectionaryCategory::WEEKDAYS_LENT, LectionaryCategory::forEventKey('LentWeekday3Mon'));
        self::assertSame(LectionaryCategory::WEEKDAYS_LENT, LectionaryCategory::forEventKey('MonHolyWeek'));
        self::assertSame(LectionaryCategory::WEEKDAYS_LENT, LectionaryCategory::forEventKey('FridayAfterAshWednesday'));
    }

    public function testForEventKeyEaster(): void
    {
        self::assertSame(LectionaryCategory::WEEKDAYS_EASTER, LectionaryCategory::forEventKey('MonOctaveEaster'));
        self::assertSame(LectionaryCategory::WEEKDAYS_EASTER, LectionaryCategory::forEventKey('EasterWeekday3'));
    }

    public function testForEventKeyOrdinary(): void
    {
        self::assertSame(LectionaryCategory::WEEKDAYS_ORDINARY, LectionaryCategory::forEventKey('OrdWeekday5Tue'));
    }

    public function testForEventKeySanctorum(): void
    {
        self::assertSame(LectionaryCategory::SANCTORUM, LectionaryCategory::forEventKey('ImmaculateHeart'));
    }

    public function testForEventKeyDefault(): void
    {
        self::assertSame(LectionaryCategory::SUNDAYS_SOLEMNITIES, LectionaryCategory::forEventKey('Easter'));
        self::assertSame(LectionaryCategory::SUNDAYS_SOLEMNITIES, LectionaryCategory::forEventKey('OrdSunday7'));
    }

    public function testHasYearCycle(): void
    {
        self::assertTrue(LectionaryCategory::SUNDAYS_SOLEMNITIES->hasYearCycle());
        self::assertFalse(LectionaryCategory::WEEKDAYS_ADVENT->hasYearCycle());
        self::assertFalse(LectionaryCategory::WEEKDAYS_ORDINARY->hasYearCycle());
    }

    public function testHasTwoYearCycle(): void
    {
        self::assertTrue(LectionaryCategory::WEEKDAYS_ORDINARY->hasTwoYearCycle());
        self::assertFalse(LectionaryCategory::SUNDAYS_SOLEMNITIES->hasTwoYearCycle());
    }

    public function testFolderAndFile(): void
    {
        self::assertSame(JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_A_FOLDER, LectionaryCategory::SUNDAYS_SOLEMNITIES->folder());
        self::assertSame(JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_A_FILE, LectionaryCategory::SUNDAYS_SOLEMNITIES->file());
        self::assertSame(JsonData::LECTIONARY_WEEKDAYS_ORDINARY_I_FOLDER, LectionaryCategory::WEEKDAYS_ORDINARY->folder());
        self::assertSame(JsonData::LECTIONARY_WEEKDAYS_ORDINARY_I_FILE, LectionaryCategory::WEEKDAYS_ORDINARY->file());
        self::assertSame(JsonData::LECTIONARY_WEEKDAYS_ADVENT_FOLDER, LectionaryCategory::WEEKDAYS_ADVENT->folder());
        self::assertSame(JsonData::LECTIONARY_WEEKDAYS_CHRISTMAS_FILE, LectionaryCategory::WEEKDAYS_CHRISTMAS->file());
        self::assertSame(JsonData::LECTIONARY_SAINTS_FOLDER, LectionaryCategory::SANCTORUM->folder());
    }

    public function testLiturgicalColor(): void
    {
        self::assertSame(['purple'], LectionaryCategory::WEEKDAYS_ADVENT->liturgicalColor());
        self::assertSame(['purple'], LectionaryCategory::WEEKDAYS_LENT->liturgicalColor());
        self::assertSame(['white'], LectionaryCategory::WEEKDAYS_CHRISTMAS->liturgicalColor());
        self::assertSame(['white'], LectionaryCategory::WEEKDAYS_EASTER->liturgicalColor());
        self::assertSame(['green'], LectionaryCategory::WEEKDAYS_ORDINARY->liturgicalColor());
        self::assertSame([], LectionaryCategory::SUNDAYS_SOLEMNITIES->liturgicalColor());
        self::assertSame([], LectionaryCategory::SANCTORUM->liturgicalColor());
    }

    public function testIsFerial(): void
    {
        self::assertTrue(LectionaryCategory::WEEKDAYS_ADVENT->isFerial());
        self::assertTrue(LectionaryCategory::WEEKDAYS_ORDINARY->isFerial());
        self::assertFalse(LectionaryCategory::SUNDAYS_SOLEMNITIES->isFerial());
        self::assertFalse(LectionaryCategory::SANCTORUM->isFerial());
    }

    public function testFolderForYear(): void
    {
        self::assertSame(
            JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_A_FOLDER,
            LectionaryCategory::SUNDAYS_SOLEMNITIES->folderForYear('A')
        );
        self::assertSame(
            JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_B_FOLDER,
            LectionaryCategory::SUNDAYS_SOLEMNITIES->folderForYear('b')
        );
        self::assertSame(
            JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_C_FOLDER,
            LectionaryCategory::SUNDAYS_SOLEMNITIES->folderForYear('C')
        );
    }

    public function testFolderForYearRejectsInvalidYear(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LectionaryCategory::SUNDAYS_SOLEMNITIES->folderForYear('D');
    }

    public function testFolderForYearRejectsCategoryWithoutYearCycle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not have year cycles');
        LectionaryCategory::WEEKDAYS_ADVENT->folderForYear('A');
    }

    public function testFileForYear(): void
    {
        self::assertSame(
            JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_A_FILE,
            LectionaryCategory::SUNDAYS_SOLEMNITIES->fileForYear('A')
        );
        self::assertSame(
            JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_C_FILE,
            LectionaryCategory::SUNDAYS_SOLEMNITIES->fileForYear('c')
        );
    }

    public function testFileForYearRejectsInvalidYear(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LectionaryCategory::SUNDAYS_SOLEMNITIES->fileForYear('Z');
    }

    public function testFileForYearRejectsCategoryWithoutYearCycle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LectionaryCategory::WEEKDAYS_LENT->fileForYear('A');
    }

    public function testFolderAndFileForTwoYearCycle(): void
    {
        self::assertSame(
            JsonData::LECTIONARY_WEEKDAYS_ORDINARY_I_FOLDER,
            LectionaryCategory::WEEKDAYS_ORDINARY->folderForTwoYearCycle('I')
        );
        self::assertSame(
            JsonData::LECTIONARY_WEEKDAYS_ORDINARY_II_FOLDER,
            LectionaryCategory::WEEKDAYS_ORDINARY->folderForTwoYearCycle('ii')
        );
        self::assertSame(
            JsonData::LECTIONARY_WEEKDAYS_ORDINARY_I_FILE,
            LectionaryCategory::WEEKDAYS_ORDINARY->fileForTwoYearCycle('i')
        );
        self::assertSame(
            JsonData::LECTIONARY_WEEKDAYS_ORDINARY_II_FILE,
            LectionaryCategory::WEEKDAYS_ORDINARY->fileForTwoYearCycle('II')
        );
    }

    public function testFolderForTwoYearCycleRejectsInvalidYear(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LectionaryCategory::WEEKDAYS_ORDINARY->folderForTwoYearCycle('III');
    }

    public function testFolderForTwoYearCycleRejectsCategoryWithoutTwoYearCycle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LectionaryCategory::WEEKDAYS_ADVENT->folderForTwoYearCycle('I');
    }

    public function testFileForTwoYearCycleRejectsInvalidYear(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LectionaryCategory::WEEKDAYS_ORDINARY->fileForTwoYearCycle('III');
    }

    public function testFileForTwoYearCycleRejectsCategoryWithoutTwoYearCycle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LectionaryCategory::WEEKDAYS_LENT->fileForTwoYearCycle('I');
    }

    public function testGetPatterns(): void
    {
        self::assertIsArray(LectionaryCategory::WEEKDAYS_ADVENT->getPatterns());
        self::assertIsArray(LectionaryCategory::WEEKDAYS_ORDINARY->getPatterns());
        self::assertNull(LectionaryCategory::SUNDAYS_SOLEMNITIES->getPatterns());
        self::assertNull(LectionaryCategory::SANCTORUM->getPatterns());
    }

    public function testEventKeys(): void
    {
        self::assertSame(['ImmaculateHeart'], LectionaryCategory::SANCTORUM->eventKeys());
        self::assertNull(LectionaryCategory::WEEKDAYS_ADVENT->eventKeys());
        self::assertNull(LectionaryCategory::SUNDAYS_SOLEMNITIES->eventKeys());
    }

    public function testSpecialEventKeysAndFerialCategories(): void
    {
        self::assertSame(['ImmaculateHeart'], LectionaryCategory::specialEventKeys());
        $ferial = LectionaryCategory::ferialCategories();
        self::assertCount(5, $ferial);
        self::assertContains(LectionaryCategory::WEEKDAYS_ADVENT, $ferial);
        self::assertContains(LectionaryCategory::WEEKDAYS_ORDINARY, $ferial);
        self::assertNotContains(LectionaryCategory::SANCTORUM, $ferial);
        self::assertNotContains(LectionaryCategory::SUNDAYS_SOLEMNITIES, $ferial);
    }

    public function testAll(): void
    {
        self::assertSame(LectionaryCategory::cases(), LectionaryCategory::all());
    }
}
