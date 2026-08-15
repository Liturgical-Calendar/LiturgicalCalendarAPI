<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Rite;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rite resolvers decide which partition of the source tree `/data` reads and
 * writes (issue #786). Getting one wrong would silently send an Ambrosian write into
 * the Roman tree, so each is pinned to the case it must return.
 *
 * Only the diocesan tier has resolvers: there are no `AMBROSIAN_NATIONAL_*` or
 * `AMBROSIAN_WIDER_REGION_*` constants, because the Ambrosian rite has neither tier.
 */
#[CoversClass(JsonData::class)]
final class JsonDataRiteResolversTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: Rite, 2: JsonData}>
     */
    public static function resolverProvider(): array
    {
        return [
            'file / roman'            => ['diocesanCalendarFileFor', Rite::ROMAN, JsonData::DIOCESAN_CALENDAR_FILE],
            'file / ambrosian'        => ['diocesanCalendarFileFor', Rite::AMBROSIAN, JsonData::AMBROSIAN_DIOCESAN_CALENDAR_FILE],
            'folder / roman'          => ['diocesanCalendarsFolderFor', Rite::ROMAN, JsonData::DIOCESAN_CALENDARS_FOLDER],
            'folder / ambrosian'      => ['diocesanCalendarsFolderFor', Rite::AMBROSIAN, JsonData::AMBROSIAN_DIOCESAN_CALENDARS_FOLDER],
            'i18n folder / roman'     => ['diocesanCalendarI18nFolderFor', Rite::ROMAN, JsonData::DIOCESAN_CALENDAR_I18N_FOLDER],
            'i18n folder / ambrosian' => ['diocesanCalendarI18nFolderFor', Rite::AMBROSIAN, JsonData::AMBROSIAN_DIOCESAN_CALENDAR_I18N_FOLDER],
            'i18n file / roman'       => ['diocesanCalendarI18nFileFor', Rite::ROMAN, JsonData::DIOCESAN_CALENDAR_I18N_FILE],
            'i18n file / ambrosian'   => ['diocesanCalendarI18nFileFor', Rite::AMBROSIAN, JsonData::AMBROSIAN_DIOCESAN_CALENDAR_I18N_FILE],
        ];
    }

    #[DataProvider('resolverProvider')]
    public function testResolverReturnsTheRitesOwnCase(string $resolver, Rite $rite, JsonData $expected): void
    {
        self::assertSame($expected, JsonData::{$resolver}($rite));
    }

    public function testTheTwoRitesNeverResolveToTheSameCase(): void
    {
        foreach (
            [
                'diocesanCalendarFileFor',
                'diocesanCalendarsFolderFor',
                'diocesanCalendarI18nFolderFor',
                'diocesanCalendarI18nFileFor',
            ] as $resolver
        ) {
            self::assertNotSame(
                JsonData::{$resolver}(Rite::ROMAN),
                JsonData::{$resolver}(Rite::AMBROSIAN),
                "{$resolver}() must send each rite to its own partition"
            );
        }
    }

    public function testAmbrosianPathsLiveUnderTheAmbrosianRiteFolder(): void
    {
        self::assertStringContainsString('/rite/ambrosian/', JsonData::diocesanCalendarFileFor(Rite::AMBROSIAN)->value);
        self::assertStringContainsString('/rite/roman/', JsonData::diocesanCalendarFileFor(Rite::ROMAN)->value);
    }
}
