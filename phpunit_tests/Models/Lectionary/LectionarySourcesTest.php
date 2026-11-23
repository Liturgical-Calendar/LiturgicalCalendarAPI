<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Lectionary;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Lectionary\LectionarySources;
use PHPUnit\Framework\TestCase;

/**
 * The resolver from an event to its readings page on a bishops' conference site (#1074).
 */
final class LectionarySourcesTest extends TestCase
{
    private const USCCB_DAY = 'https://bible.usccb.org/bible/readings/';
    private const CEI_DAY   = 'https://www.chiesacattolica.it/liturgia-del-giorno/?data-liturgia=';

    private static function sources(): LectionarySources
    {
        return LectionarySources::fromArray([
            'usccb' => [
                'day_page'    => 'https://bible.usccb.org/bible/readings/{MM}{DD}{YY}.cfm',
                'linked_page' => 'https://bible.usccb.org/bible/readings/{page}',
                'first_date'  => '2013-01-01',
                'last_date'   => '2027-12-01',
                'calendars'   => ['US'],
                'pages'       => [
                    'StGregoryGreat'   => '0903-memorial-gregory.cfm',
                    'Assumption_vigil' => '{day}-Vigil.cfm',
                ],
            ],
            'cei'   => [
                'day_page'    => 'https://www.chiesacattolica.it/liturgia-del-giorno/?data-liturgia={YYYY}{MM}{DD}',
                'linked_page' => 'https://www.chiesacattolica.it/liturgia-del-giorno/?data-liturgia={YYYY}{MM}{DD}#{page}',
                'first_date'  => '2016-01-01',
                'last_date'   => '2027-02-03',
                'calendars'   => ['IT'],
                'pages'       => [
                    'StBlase'     => ['I' => '607', 'II' => '119'],
                    'StAmbrose'   => ['II' => '29'],
                    'OrdSunday13' => ['A' => '847'],
                    'StAnsgar'    => ['II' => '120'],
                ],
            ],
        ]);
    }

    private static function d(string $ymd): \DateTimeImmutable
    {
        return new \DateTimeImmutable($ymd . 'T00:00:00+00:00');
    }

    public function testUsDayPage(): void
    {
        $this->assertSame(
            self::USCCB_DAY . '100625.cfm',
            self::sources()->urlFor('US', Rite::ROMAN, 'OrdWeekday27Monday', self::d('2025-10-06'), false)
        );
    }

    public function testUsMemorialPage(): void
    {
        $this->assertSame(
            self::USCCB_DAY . '0903-memorial-gregory.cfm',
            self::sources()->urlFor('US', Rite::ROMAN, 'StGregoryGreat', self::d('2025-09-03'), false)
        );
    }

    public function testItSectionFragmentByWeekdayCycle(): void
    {
        $this->assertSame(self::CEI_DAY . '20250203#607', self::sources()->urlFor('IT', Rite::ROMAN, 'StBlase', self::d('2025-02-03'), false));
        $this->assertSame(self::CEI_DAY . '20240203#119', self::sources()->urlFor('IT', Rite::ROMAN, 'StBlase', self::d('2024-02-03'), false));
    }

    public function testAdventDateUsesNextLiturgicalYear(): void
    {
        $this->assertSame(self::CEI_DAY . '20251207#29', self::sources()->urlFor('IT', Rite::ROMAN, 'StAmbrose', self::d('2025-12-07'), false));
        $this->assertSame(2026, LectionarySources::liturgicalYearOf(self::d('2025-12-07')));
        $this->assertSame(2025, LectionarySources::liturgicalYearOf(self::d('2025-11-29')));
        $this->assertSame(2026, LectionarySources::liturgicalYearOf(self::d('2025-11-30')));
        $this->assertSame(2023, LectionarySources::liturgicalYearOf(self::d('2022-11-27')));
        $this->assertSame(2022, LectionarySources::liturgicalYearOf(self::d('2022-11-26')));
    }

    public function testSundayCycle(): void
    {
        $this->assertSame(self::CEI_DAY . '20230702#847', self::sources()->urlFor('IT', Rite::ROMAN, 'OrdSunday13', self::d('2023-07-02'), false));
        $this->assertSame(self::CEI_DAY . '20240630', self::sources()->urlFor('IT', Rite::ROMAN, 'OrdSunday13', self::d('2024-06-30'), false));
    }

    public function testMissingCycleFallsBackToDayPage(): void
    {
        $this->assertSame(self::CEI_DAY . '20250203', self::sources()->urlFor('IT', Rite::ROMAN, 'StAnsgar', self::d('2025-02-03'), false));
    }

    public function testVigilWithoutPageHasNoLink(): void
    {
        $this->assertNull(self::sources()->urlFor('US', Rite::ROMAN, 'Christmas_vigil', self::d('2026-12-24'), true));
    }

    public function testVigilPageUsesNextDay(): void
    {
        $this->assertSame(
            self::USCCB_DAY . '081526-Vigil.cfm',
            self::sources()->urlFor('US', Rite::ROMAN, 'Assumption_vigil', self::d('2026-08-14'), true)
        );
    }

    public function testRangeIsInclusive(): void
    {
        $s = self::sources();
        $this->assertSame(self::USCCB_DAY . '120127.cfm', $s->urlFor('US', Rite::ROMAN, 'AdventWeekday1Wednesday', self::d('2027-12-01'), false));
        $this->assertNull($s->urlFor('US', Rite::ROMAN, 'AdventWeekday1Thursday', self::d('2027-12-02'), false));
        $this->assertSame(self::USCCB_DAY . '010113.cfm', $s->urlFor('US', Rite::ROMAN, 'MotherGod', self::d('2013-01-01'), false));
        $this->assertNull($s->urlFor('US', Rite::ROMAN, 'Christmas7', self::d('2012-12-31'), false));
    }

    public function testOtherNationOrRiteHasNoLink(): void
    {
        $this->assertNull(self::sources()->urlFor('CA', Rite::ROMAN, 'OrdWeekday27Monday', self::d('2025-10-06'), false));
        $this->assertNull(self::sources()->urlFor('IT', Rite::AMBROSIAN, 'StBlase', self::d('2025-02-03'), false));
    }

    /**
     * A memorial reduced to a commemoration (St John of God on a Lenten weekday) is celebrated
     * with the weekday's readings, which are on the day page, not on the memorial's own page.
     */
    public function testCommemorationLinksTheDayPage(): void
    {
        $this->assertSame(
            self::USCCB_DAY . '090323.cfm',
            self::sources()->urlFor('US', Rite::ROMAN, 'StGregoryGreat', self::d('2023-09-03'), false, true)
        );
    }

    public function testFromFileReadsTheRealSidecar(): void
    {
        $path = dirname(__DIR__, 3) . '/jsondata/sourcedata/rite/roman/lectionary_sources.json';
        $this->assertSame(
            self::USCCB_DAY . '0903-memorial-gregory.cfm',
            LectionarySources::fromFile($path)->urlFor('US', Rite::ROMAN, 'StGregoryGreat', self::d('2025-09-03'), false)
        );
    }

    public function testFromFileRejectsAMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        LectionarySources::fromFile('/nonexistent/lectionary_sources.json');
    }
}
