<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeEvent;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeMap;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Utilities;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AmbrosianProprioDeTemporeDataTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // JsonData::path() concatenates Router::$apiFilePath; populate it.
        Router::getApiPaths();
    }

    /** @return array<string,string> */
    private function loadNames(string $locale): array
    {
        $file = strtr(JsonData::AMBROSIAN_TEMPORALE_I18N_FILE->path(), ['{locale}' => $locale]);
        /** @var array<string,string> $names */
        $names = Utilities::jsonFileToArray($file);
        return $names;
    }

    private function loadPropriumDeTemporeMap(): PropriumDeTemporeMap
    {
        $raw = Utilities::jsonFileToObjectArray(JsonData::AMBROSIAN_TEMPORALE_FILE->path());
        return PropriumDeTemporeMap::fromObject($raw);
    }

    public function testDataFileLoadsIntoMapWithItalianNames(): void
    {
        $raw   = Utilities::jsonFileToObjectArray(JsonData::AMBROSIAN_TEMPORALE_FILE->path());
        $names = $this->loadNames('it');
        $map   = PropriumDeTemporeMap::fromObject($raw);
        $map->setNames($names);

        // Sentinel keys the engine depends on:
        foreach (['Advent1', 'Advent6', 'Circoncisione', 'Lent5', 'AshesMonday', 'SabatoTradSymb', 'DedicationDuomo', 'ChristKing', 'Pentecost'] as $key) {
            $this->assertTrue($map->offsetExists($key), "Missing temporal key: $key");
        }
    }

    public function testItalianAndLatinI18nCoverEveryDataKey(): void
    {
        $raw = Utilities::jsonFileToObjectArray(JsonData::AMBROSIAN_TEMPORALE_FILE->path());
        $it  = $this->loadNames('it');
        $la  = $this->loadNames('la');

        $dataKeys = [];
        foreach ($raw as $event) {
            $key        = $event->event_key;
            $dataKeys[] = $key;
            $this->assertArrayHasKey($key, $it, "it.json missing name for $key");
            $this->assertArrayHasKey($key, $la, "la.json missing name for $key");
        }

        // Reverse direction: an i18n key with no corresponding data-file entry
        // is an orphan (e.g. a stale/renamed key) and must fail loudly rather
        // than silently going unused.
        $itOrphans = array_diff(array_keys($it), $dataKeys);
        $laOrphans = array_diff(array_keys($la), $dataKeys);
        $this->assertSame([], array_values($itOrphans), 'it.json has orphan keys not present in the data file: ' . implode(', ', $itOrphans));
        $this->assertSame([], array_values($laOrphans), 'la.json has orphan keys not present in the data file: ' . implode(', ', $laOrphans));
    }

    /**
     * Sundays and the solemnities/feasts "of the Lord" (Christological), as opposed to weekday ferie and the
     * Sacred Triduum keys (which are Christological too, but are not classified as "dominical" for the purposes
     * of the Ambrosian precedence resolver -- see AmbrosianLiturgicalDayRank's docblock).
     *
     * @return string[]
     */
    private static function dominicalKeys(): array
    {
        return [
            'Advent1',
            'Advent2',
            'Advent3',
            'Advent4',
            'Advent5',
            'Advent6',
            'Christmas',
            'Circoncisione',
            'Epiphany',
            'BaptismLord',
            'Lent1',
            'Lent2',
            'Lent3',
            'Lent4',
            'Lent5',
            'PalmSun',
            'Easter',
            'Easter2',
            'Easter3',
            'Easter4',
            'Easter5',
            'Easter6',
            'Easter7',
            'Ascension',
            'Pentecost',
            'Trinity',
            'CorpusChristi',
            'SacredHeart',
            'DedicationDuomo',
            'ChristKing',
        ];
    }

    /**
     * Weekday ferie and the Sacred Triduum keys: Christological in content, but not "dominical" in the
     * `is_dominical` sense used by the Ambrosian precedence resolver.
     *
     * @return string[]
     */
    private static function nonDominicalKeys(): array
    {
        return [
            'AshesMonday',
            'SabatoTradSymb',
            'HolyThurs',
            'GoodFri',
            'EasterVigil',
            'MonOctaveEaster',
            'TueOctaveEaster',
            'WedOctaveEaster',
            'ThuOctaveEaster',
            'FriOctaveEaster',
            'SatOctaveEaster',
            'MaryMotherChurch',
            'ImmaculateHeart',
        ];
    }

    /**
     * Proves the full data->model->LiturgicalEvent passthrough: `is_dominical` set in the JSON source data
     * must survive PropriumDeTemporeMap::fromObject() (building PropriumDeTemporeEvent instances) and
     * LiturgicalEvent::fromObject() (Task 1's passthrough), landing on the final LiturgicalEvent.
     */
    public function testDominicalKeysCarryIsDominicalThroughToLiturgicalEvent(): void
    {
        $raw   = Utilities::jsonFileToObjectArray(JsonData::AMBROSIAN_TEMPORALE_FILE->path());
        $names = $this->loadNames('it');
        $map   = PropriumDeTemporeMap::fromObject($raw);
        $map->setNames($names);

        foreach (self::dominicalKeys() as $key) {
            $this->assertTrue($map->offsetExists($key), "Missing temporal key: $key");
            $propriumDeTemporeEvent = $map[$key];
            $propriumDeTemporeEvent->setDate(DateTime::fromFormat('1-1-2026'));
            $litEvent = LiturgicalEvent::fromObject($propriumDeTemporeEvent);
            $this->assertTrue($litEvent->is_dominical === true, "Expected is_dominical === true for dominical key: $key");
        }
    }

    /**
     * Weekday ferie and the Sacred Triduum keys must NOT be marked dominical, and must not spuriously pick up
     * `is_dominical` through the same passthrough chain exercised above.
     */
    public function testNonDominicalKeysDoNotCarryIsDominical(): void
    {
        $raw   = Utilities::jsonFileToObjectArray(JsonData::AMBROSIAN_TEMPORALE_FILE->path());
        $names = $this->loadNames('it');
        $map   = PropriumDeTemporeMap::fromObject($raw);
        $map->setNames($names);

        foreach (self::nonDominicalKeys() as $key) {
            $this->assertTrue($map->offsetExists($key), "Missing temporal key: $key");
            $propriumDeTemporeEvent = $map[$key];
            $propriumDeTemporeEvent->setDate(DateTime::fromFormat('1-1-2026'));
            $litEvent = LiturgicalEvent::fromObject($propriumDeTemporeEvent);
            $this->assertNotTrue($litEvent->is_dominical, "Expected is_dominical to not be true for non-dominical key: $key");
        }
    }

    /**
     * Every key in the data file falls into exactly one of the dominical / non-dominical buckets asserted
     * above, so a future key addition can't silently go unclassified in this test.
     */
    public function testDominicalAndNonDominicalKeysPartitionTheDataFile(): void
    {
        $raw          = Utilities::jsonFileToObjectArray(JsonData::AMBROSIAN_TEMPORALE_FILE->path());
        $dataKeys     = array_map(fn (\stdClass $event): string => $event->event_key, $raw);
        $classified   = array_merge(self::dominicalKeys(), self::nonDominicalKeys());
        $unclassified = array_diff($dataKeys, $classified);
        $this->assertSame([], array_values($unclassified), 'Unclassified temporale keys (neither dominical nor non-dominical): ' . implode(', ', $unclassified));

        $overlap = array_intersect(self::dominicalKeys(), self::nonDominicalKeys());
        $this->assertSame([], array_values($overlap), 'Keys present in BOTH dominical and non-dominical lists: ' . implode(', ', $overlap));
    }

    public function testYearGatingFieldsDefaultToNull(): void
    {
        $event = PropriumDeTemporeEvent::fromObject((object) [
            'event_key' => 'TestEvent',
            'grade'     => 6,
            'type'      => 'mobile',
            'color'     => ['white'],
        ]);

        self::assertNull($event->since_year);
        self::assertNull($event->until_year);
    }

    public function testYearGatingFieldsAreParsedWhenPresent(): void
    {
        $event = PropriumDeTemporeEvent::fromObject((object) [
            'event_key'  => 'TestEvent',
            'grade'      => 3,
            'type'       => 'mobile',
            'color'      => ['white'],
            'since_year' => 2018,
            'until_year' => 2030,
        ]);

        self::assertSame(2018, $event->since_year);
        self::assertSame(2030, $event->until_year);
    }

    /** @return array<string,array{0:string,1:int,2:bool,3:int|null}> */
    public static function pentecostAnchoredCelebrations(): array
    {
        // key => [event_key, grade, is_dominical, since_year]
        return [
            'Mary Mother of the Church' => ['MaryMotherChurch', 3, false, 2018],
            'Most Holy Trinity'         => ['Trinity', 6, true, null],
            'Corpus Domini'             => ['CorpusChristi', 6, true, null],
            'Sacred Heart'              => ['SacredHeart', 6, true, null],
            'Immaculate Heart'          => ['ImmaculateHeart', 3, false, null],
        ];
    }

    #[DataProvider('pentecostAnchoredCelebrations')]
    public function testPentecostAnchoredCelebrationsArePresent(
        string $eventKey,
        int $grade,
        bool $isDominical,
        ?int $sinceYear
    ): void {
        $map = $this->loadPropriumDeTemporeMap();

        self::assertTrue($map->offsetExists($eventKey), "Missing Proprium de Tempore entry: $eventKey");

        $event = $map[$eventKey];
        self::assertSame($grade, $event->grade->value, "$eventKey grade");
        self::assertSame($isDominical, $event->is_dominical, "$eventKey is_dominical");
        self::assertSame($sinceYear, $event->since_year, "$eventKey since_year");
        self::assertSame('mobile', $event->type->value, "$eventKey type");
        self::assertSame(['white'], array_map(static fn ($c): string => $c->value, $event->color), "$eventKey color");
    }

    #[DataProvider('pentecostAnchoredCelebrations')]
    public function testPentecostAnchoredCelebrationsAreTranslatedInEveryShippedLocale(string $eventKey): void
    {
        foreach (['it', 'la'] as $locale) {
            $names = $this->loadNames($locale);
            self::assertArrayHasKey($eventKey, $names, "$eventKey missing from $locale.json");
            self::assertNotSame('', trim($names[$eventKey]), "$eventKey is empty in $locale.json");
        }
    }
}
