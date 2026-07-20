<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\LocaleDateFormatter;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\AmbrosianTemporale;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\TemporaleContext;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeMap;
use LiturgicalCalendar\Api\Params\CalendarParams;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Utilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Engine unit tests for the Ambrosian temporale skeleton + Advent block.
 * Tasks 5-8 will extend this class with the remaining seasons.
 */
#[CoversClass(AmbrosianTemporale::class)]
final class AmbrosianTemporaleTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // JsonData::path() concatenates Router::$apiFilePath; populate it.
        Router::getApiPaths();
    }

    /**
     * Builds a TemporaleContext wired to the Ambrosian Proprium de Tempore for a
     * given civil year, mirroring how CalendarHandler wires RomanTemporale.
     *
     * @param array<string> $messages
     */
    private function buildContext(int $year, array &$messages): TemporaleContext
    {
        // Force the runtime primary language so LocaleDateFormatter + i18n load deterministically.
        LitLocale::$PRIMARY_LANGUAGE = 'it';
        LitLocale::$RUNTIME_LOCALE   = 'it_IT';

        $dataFile = strtr(
            JsonData::AMBROSIAN_TEMPORALE_FILE->path(),
            []
        );
        $i18nFile = strtr(
            JsonData::AMBROSIAN_TEMPORALE_I18N_FILE->path(),
            ['{locale}' => 'it']
        );

        $rawEvents = Utilities::jsonFileToObjectArray($dataFile);
        /** @var array<string,string> $names */
        $names = Utilities::jsonFileToArray($i18nFile);

        $map = PropriumDeTemporeMap::fromObject($rawEvents);
        $map->setNames($names);

        $params = new CalendarParams();
        $params->setParams(['year' => $year]);
        $params->setRite(Rite::AMBROSIAN);

        $cal = new LiturgicalEventCollection($params);

        return new TemporaleContext(
            $cal,
            $params,
            $map,
            new LocaleDateFormatter(LitLocale::$RUNTIME_LOCALE),
            $messages
        );
    }

    /** @return array<string,string> map of event_key => 'Y-m-d' after buildTemporale */
    private function runEngine(int $year): array
    {
        $messages = [];
        $ctx      = $this->buildContext($year, $messages);
        ( new AmbrosianTemporale() )->buildTemporale($ctx);

        $dates = [];
        foreach ($ctx->cal->getLiturgicalEvents()->getKeys() as $key) {
            $event = $ctx->cal->getLiturgicalEvent($key);
            self::assertNotNull($event, "Expected a LiturgicalEvent for key $key");
            $dates[$key] = $event->date->format('Y-m-d');
        }
        return $dates;
    }

    public function testAdvent2025(): void
    {
        $d = $this->runEngine(2025);
        $this->assertSame('2025-11-16', $d['Advent1']);
        $this->assertSame('2025-11-23', $d['Advent2']);
        $this->assertSame('2025-11-30', $d['Advent3']);
        $this->assertSame('2025-12-07', $d['Advent4']);
        $this->assertSame('2025-12-14', $d['Advent5']);
        $this->assertSame('2025-12-21', $d['Advent6']);
    }

    public function testAdvent2024(): void
    {
        $d = $this->runEngine(2024);
        $this->assertSame('2024-11-17', $d['Advent1']);
        $this->assertSame('2024-12-22', $d['Advent6']);
    }

    public function testChristmasEpiphany2025(): void
    {
        $d = $this->runEngine(2025);
        $this->assertSame('2025-12-25', $d['Christmas']);
        $this->assertSame('2025-01-01', $d['Circoncisione']);
        $this->assertSame('2025-01-06', $d['Epiphany']);
        $this->assertSame('2025-01-12', $d['BaptismLord']);
    }

    public function testBaptism2024(): void
    {
        $d = $this->runEngine(2024);
        $this->assertSame('2024-01-07', $d['BaptismLord']);
    }

    public function testLent2025(): void
    {
        $d = $this->runEngine(2025);
        $this->assertSame('2025-03-09', $d['Lent1']);
        $this->assertSame('2025-03-16', $d['Lent2']);
        $this->assertSame('2025-03-23', $d['Lent3']);
        $this->assertSame('2025-03-30', $d['Lent4']);
        $this->assertSame('2025-04-06', $d['Lent5']);
        $this->assertSame('2025-03-10', $d['AshesMonday']);
        $this->assertSame('2025-04-13', $d['PalmSun']);
        $this->assertSame('2025-04-12', $d['SabatoTradSymb']);
    }

    public function testNoAshWednesday2025(): void
    {
        $d = $this->runEngine(2025);
        $this->assertArrayNotHasKey('AshWednesday', $d);
    }

    public function testLent2024(): void
    {
        $d = $this->runEngine(2024);
        $this->assertSame('2024-02-18', $d['Lent1']);
        $this->assertSame('2024-02-19', $d['AshesMonday']);
        $this->assertSame('2024-03-23', $d['SabatoTradSymb']);
    }

    public function testEasterCycle2025(): void
    {
        $d = $this->runEngine(2025);
        $this->assertSame('2025-04-17', $d['HolyThurs']);
        $this->assertSame('2025-04-18', $d['GoodFri']);
        $this->assertSame('2025-04-19', $d['EasterVigil']);
        $this->assertSame('2025-04-20', $d['Easter']);
        $this->assertSame('2025-04-21', $d['MonOctaveEaster']);
        $this->assertSame('2025-04-26', $d['SatOctaveEaster']);
        $this->assertSame('2025-04-27', $d['Easter2']);
        $this->assertSame('2025-06-01', $d['Easter7']);
        $this->assertSame('2025-05-29', $d['Ascension']);
        $this->assertSame('2025-06-08', $d['Pentecost']);
    }

    public function testAfterPentecostAnchors2025(): void
    {
        $d = $this->runEngine(2025);
        $this->assertSame('2025-10-19', $d['DedicationDuomo']);
        $this->assertSame('2025-11-09', $d['ChristKing']);
    }

    public function testAfterPentecostAnchors2024(): void
    {
        $d = $this->runEngine(2024);
        $this->assertSame('2024-10-20', $d['DedicationDuomo']);
        $this->assertSame('2024-11-10', $d['ChristKing']);
    }

    public function testChristKingIsSundayBeforeAdvent1(): void
    {
        foreach ([2024, 2025] as $year) {
            $d  = $this->runEngine($year);
            $ck = new \DateTimeImmutable($d['ChristKing']);
            $a1 = new \DateTimeImmutable($d['Advent1']);
            $this->assertSame(7, (int) $ck->format('N'), "Christ the King must be a Sunday ($year)");
            $this->assertSame($a1->modify('-7 days')->format('Y-m-d'), $d['ChristKing']);
        }
    }
}
