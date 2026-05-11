<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar;

use LiturgicalCalendar\Api\Enum\LitCommon;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\LitMassVariousNeeds;
use LiturgicalCalendar\Api\Models\Calendar\LitCommons;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LitCommons::class)]
final class LitCommonsTest extends TestCase
{
    public function testCreateEmptyArrayReturnsNoneSentinel(): void
    {
        $commons = LitCommons::create([]);
        self::assertNotNull($commons);
        self::assertTrue($commons->has(LitCommon::NONE));
        self::assertSame([], $commons->jsonSerialize());
    }

    public function testCreateWithSingleProprioStringReturnsProper(): void
    {
        $commons = LitCommons::create([LitCommon::PROPRIO->value]);
        self::assertNotNull($commons);
        self::assertTrue($commons->has(LitCommon::PROPRIO));
    }

    public function testCreateWithSingleProprioEnumReturnsProper(): void
    {
        $commons = LitCommons::create([LitCommon::PROPRIO]);
        self::assertNotNull($commons);
        self::assertTrue($commons->has(LitCommon::PROPRIO));
    }

    public function testCreateFromLitCommonEnumArray(): void
    {
        $commons = LitCommons::create([LitCommon::MARTYRUM, LitCommon::PASTORUM]);
        self::assertNotNull($commons);
        self::assertTrue($commons->has(LitCommon::MARTYRUM));
        self::assertTrue($commons->has(LitCommon::PASTORUM));
    }

    public function testCreateFromStringArrayWithGeneralAndSpecific(): void
    {
        // 'Martyrs:For One Martyr' splits on colon into general:specific.
        $commons = LitCommons::create(['Martyrs:For One Martyr']);
        self::assertNotNull($commons);
        self::assertTrue($commons->has(LitCommon::MARTYRUM));
        self::assertTrue($commons->has(LitCommon::PRO_UNO_MARTYRE));
    }

    public function testCreateFromUnknownStringReturnsNull(): void
    {
        // Unknown common label: returns null (signalling LitMassVariousNeeds path).
        $commons = LitCommons::create(['NotARealCommon']);
        self::assertNull($commons);
    }

    public function testCreateFromMixedTypesRejects(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('same type');
        LitCommons::create([LitCommon::MARTYRUM, 'Pastors']);
    }

    public function testCreateFromLitMassVariousNeedsReturnsNull(): void
    {
        $commons = LitCommons::create([LitMassVariousNeeds::PRO_PAPA]);
        self::assertNull($commons);
    }

    public function testFullTranslateProper(): void
    {
        $commons = LitCommons::create([LitCommon::PROPRIO]);
        self::assertNotNull($commons);
        $translated = $commons->fullTranslate(LitLocale::LATIN_PRIMARY_LANGUAGE);
        // Latin translation of Proprio.
        self::assertSame('Proprio', $translated);
    }

    public function testFullTranslateNoneReturnsEmptyString(): void
    {
        $commons = LitCommons::create([]);
        self::assertNotNull($commons);
        self::assertSame('', $commons->fullTranslate(LitLocale::LATIN_PRIMARY_LANGUAGE));
    }

    public function testFullTranslateCommonBuildsFromTheCommonPhrase(): void
    {
        $commons = LitCommons::create([LitCommon::MARTYRUM]);
        self::assertNotNull($commons);
        $latin = $commons->fullTranslate(LitLocale::LATIN_PRIMARY_LANGUAGE);
        self::assertStringContainsString('De Commune', $latin);
        self::assertStringContainsString('Martyrum', $latin);
    }
}
