<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Metadata;

use LiturgicalCalendar\Api\Enum\Ascension;
use LiturgicalCalendar\Api\Enum\CorpusChristi;
use LiturgicalCalendar\Api\Enum\Epiphany;
use LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars;
use LiturgicalCalendar\Api\Models\Metadata\MetadataDiocesanCalendarItem;
use LiturgicalCalendar\Api\Models\Metadata\MetadataDiocesanGroupItem;
use LiturgicalCalendar\Api\Models\Metadata\MetadataNationalCalendarItem;
use LiturgicalCalendar\Api\Models\Metadata\MetadataNationalCalendarSettings;
use LiturgicalCalendar\Api\Models\Metadata\MetadataWiderRegionItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetadataCalendars::class)]
#[CoversClass(MetadataDiocesanCalendarItem::class)]
#[CoversClass(MetadataDiocesanGroupItem::class)]
#[CoversClass(MetadataNationalCalendarItem::class)]
#[CoversClass(MetadataNationalCalendarSettings::class)]
#[CoversClass(MetadataWiderRegionItem::class)]
final class MetadataCalendarsTest extends TestCase
{
    public function testNationalCalendarSettingsFromArray(): void
    {
        $settings = MetadataNationalCalendarSettings::fromArray([
            'epiphany'               => Epiphany::JAN6->value,
            'ascension'              => Ascension::THURSDAY->value,
            'corpus_christi'         => CorpusChristi::SUNDAY->value,
            'eternal_high_priest'    => false,
            'holydays_of_obligation' => ['Christmas' => true, 'Custom' => false],
        ]);

        self::assertSame(Epiphany::JAN6, $settings->epiphany);
        self::assertSame(Ascension::THURSDAY, $settings->ascension);
        self::assertSame(CorpusChristi::SUNDAY, $settings->corpus_christi);
        self::assertFalse($settings->eternal_high_priest);
        self::assertSame(false, $settings->holydays_of_obligation['Custom']);
    }

    public function testNationalCalendarSettingsRejectsInvalidHolydaysType(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('holydays_of_obligation');
        MetadataNationalCalendarSettings::fromArray([
            'epiphany'               => Epiphany::JAN6->value,
            'ascension'              => Ascension::THURSDAY->value,
            'corpus_christi'         => CorpusChristi::SUNDAY->value,
            'eternal_high_priest'    => false,
            'holydays_of_obligation' => 'not-an-array',
        ]);
    }

    public function testNationalCalendarSettingsRejectsNonBooleanHolyday(): void
    {
        $this->expectException(\ValueError::class);
        MetadataNationalCalendarSettings::fromArray([
            'epiphany'               => Epiphany::JAN6->value,
            'ascension'              => Ascension::THURSDAY->value,
            'corpus_christi'         => CorpusChristi::SUNDAY->value,
            'eternal_high_priest'    => false,
            'holydays_of_obligation' => ['Christmas' => 'yes'],
        ]);
    }

    public function testNationalCalendarSettingsFromObjectAndSerialize(): void
    {
        $settings = MetadataNationalCalendarSettings::fromObject((object) [
            'epiphany'               => Epiphany::SUNDAY_JAN2_JAN8->value,
            'ascension'              => Ascension::SUNDAY->value,
            'corpus_christi'         => CorpusChristi::SUNDAY->value,
            'eternal_high_priest'    => true,
            'holydays_of_obligation' => (object) ['Christmas' => true],
        ]);
        $arr      = $settings->jsonSerialize();
        self::assertSame(Epiphany::SUNDAY_JAN2_JAN8->value, $arr['epiphany']);
        self::assertTrue($arr['eternal_high_priest']);
    }

    public function testDiocesanGroupItemRoundTrip(): void
    {
        $group = MetadataDiocesanGroupItem::fromArray(['group_name' => 'CEI', 'dioceses' => ['rome', 'milan']]);
        self::assertSame('CEI', $group->group_name);
        self::assertSame(['rome', 'milan'], $group->dioceses);
        self::assertSame(
            ['group_name' => 'CEI', 'dioceses' => ['rome', 'milan']],
            $group->jsonSerialize()
        );

        $fromObj = MetadataDiocesanGroupItem::fromObject((object) ['group_name' => 'CCCB', 'dioceses' => ['toronto']]);
        self::assertSame('CCCB', $fromObj->group_name);
    }

    public function testWiderRegionItemRoundTrip(): void
    {
        $wr = MetadataWiderRegionItem::fromArray([
            'name'     => 'Europa',
            'locales'  => ['it', 'la'],
            'api_path' => 'https://api.example/wider/Europa',
        ]);
        self::assertSame('Europa', $wr->name);
        self::assertSame(['it', 'la'], $wr->locales);
        self::assertSame('https://api.example/wider/Europa', $wr->api_path);
        self::assertSame(
            ['name' => 'Europa', 'locales' => ['it', 'la'], 'api_path' => 'https://api.example/wider/Europa'],
            $wr->jsonSerialize()
        );

        $wrObj = MetadataWiderRegionItem::fromObject((object) [
            'name'     => 'LatinAmerica',
            'locales'  => ['es'],
            'api_path' => 'https://api.example/wider/LatinAmerica',
        ]);
        self::assertSame('LatinAmerica', $wrObj->name);
    }

    public function testDiocesanCalendarItemRoundTrip(): void
    {
        $dc = MetadataDiocesanCalendarItem::fromArray([
            'calendar_id' => 'romadi_it',
            'diocese'     => 'Roma',
            'nation'      => 'IT',
            'locales'     => ['it'],
            'timezone'    => 'Europe/Vatican',
            'group'       => 'CEI',
        ]);
        self::assertSame('romadi_it', $dc->calendar_id);
        self::assertSame('Roma', $dc->diocese);
        self::assertSame('CEI', $dc->group);
        self::assertNull($dc->settings);

        $serialized = $dc->jsonSerialize();
        self::assertSame('CEI', $serialized['group']);
        self::assertArrayNotHasKey('settings', $serialized);
    }

    public function testDiocesanCalendarItemRejectsDiocesalDataShape(): void
    {
        // diocese_id is a sentinel used by DiocesanMetadata, not by MetadataDiocesanCalendarItem.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DiocesanMetadata');
        MetadataDiocesanCalendarItem::fromArray([
            'diocese_id'  => 'rome',
            'calendar_id' => 'romadi_it',
            'diocese'     => 'Roma',
            'nation'      => 'IT',
            'locales'     => ['it'],
            'timezone'    => 'Europe/Vatican',
        ]);
    }

    public function testDiocesanCalendarItemFromObjectRejectsDiocesalDataShape(): void
    {
        $this->expectException(\RuntimeException::class);
        MetadataDiocesanCalendarItem::fromObject((object) [
            'diocese_id'  => 'rome',
            'calendar_id' => 'romadi_it',
            'diocese'     => 'Roma',
            'nation'      => 'IT',
            'locales'     => ['it'],
            'timezone'    => 'Europe/Vatican',
        ]);
    }

    public function testMetadataCalendarsBuiltFromEmpty(): void
    {
        $mc = new MetadataCalendars();
        self::assertSame([], $mc->national_calendars);
        self::assertSame([], $mc->locales);
        $arr = $mc->jsonSerialize();
        self::assertSame([], $arr['national_calendars']);
        self::assertSame([], $arr['locales']);
    }

    public function testMetadataCalendarsPushAndSerialize(): void
    {
        $mc     = new MetadataCalendars();
        $nation = new MetadataNationalCalendarItem(
            'IT',
            ['it'],
            ['IT_2020'],
            MetadataNationalCalendarSettings::fromArray([
                'epiphany'            => Epiphany::JAN6->value,
                'ascension'           => Ascension::SUNDAY->value,
                'corpus_christi'      => CorpusChristi::SUNDAY->value,
                'eternal_high_priest' => true,
            ]),
            null,
            []
        );
        $mc->pushNationalCalendarMetadata($nation);
        self::assertSame(['IT'], $mc->national_calendars_keys);

        $diocese = new MetadataDiocesanCalendarItem(
            calendar_id: 'romadi_it',
            diocese: 'Roma',
            nation: 'IT',
            locales: ['it'],
            timezone: 'Europe/Vatican',
            group: 'CEI'
        );
        $mc->pushDiocesanCalendarMetadata($diocese);
        self::assertSame(['romadi_it'], $mc->diocesan_calendars_keys);
        self::assertCount(1, $mc->diocesan_groups);
        self::assertSame('CEI', $mc->diocesan_groups[0]->group_name);
        // The IT national calendar should have romadi_it pushed into its dioceses list.
        self::assertContains('romadi_it', $mc->national_calendars[0]->dioceses);

        // Push a second diocese into the same group — should append to existing group.
        $milano = new MetadataDiocesanCalendarItem(
            calendar_id: 'milanodi_it',
            diocese: 'Milano',
            nation: 'IT',
            locales: ['it'],
            timezone: 'Europe/Vatican',
            group: 'CEI'
        );
        $mc->pushDiocesanCalendarMetadata($milano);
        self::assertCount(1, $mc->diocesan_groups);
        self::assertContains('milanodi_it', $mc->diocesan_groups[0]->dioceses);

        // Wider region push.
        $wr = MetadataWiderRegionItem::fromArray(['name' => 'EU', 'locales' => ['it'], 'api_path' => 'p']);
        $mc->pushWiderRegionMetadata($wr);
        self::assertSame(['EU'], $mc->wider_regions_keys);
    }
}
