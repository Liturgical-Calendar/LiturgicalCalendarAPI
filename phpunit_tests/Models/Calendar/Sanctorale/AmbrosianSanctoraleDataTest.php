<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Sanctorale;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Models\PropriumDeSanctisMap;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Utilities;
use PHPUnit\Framework\TestCase;
use Swaggest\JsonSchema\Schema;

/**
 * Data test for the comune ambrosiano sanctorale (Plan 5, Task 4: January — the worked template).
 *
 * This test grows with each subsequent month (Tasks 5a/5b add Feb-Jun and Jul-Dec rows/keys): it
 * asserts that the data file loads into a PropriumDeSanctisMap, that every event_key present in the
 * data file has BOTH an Italian and a Latin name (and vice versa: no orphan i18n keys), and that
 * every row in the data file validates against the PropriumDeSanctis.json schema.
 */
final class AmbrosianSanctoraleDataTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // JsonData::path() and LitSchema::path() both concatenate Router::$apiFilePath; populate it.
        Router::getApiPaths();
    }

    /** @return array<string,string> */
    private function loadNames(string $locale): array
    {
        $file = strtr(JsonData::AMBROSIAN_SANCTORALE_I18N_FILE->path(), ['{locale}' => $locale]);
        /** @var array<string,string> $names */
        $names = Utilities::jsonFileToArray($file);
        return $names;
    }

    /** @return \stdClass[] */
    private function loadRawRows(): array
    {
        return Utilities::jsonFileToObjectArray(JsonData::AMBROSIAN_SANCTORALE_FILE->path());
    }

    /**
     * January sentinel keys, with their expected (grade, is_dominical) pair. `is_dominical` is
     * `null` when the source data omits the key (the model default), `true` for the two January
     * "Solennità dS" (of the Lord) entries.
     *
     * @return array<string,array{0:int,1:?bool}>
     */
    private static function januarySentinels(): array
    {
        return [
            'Circoncisione'            => [6, true],
            'StsBasilGregoryNazianzen' => [3, null],
            'Epiphany'                 => [6, true],
            'StRaymondOfPenyafort'     => [2, null],
            'StHilary'                 => [2, null],
            'StAnthonyAbbot'           => [3, null],
            'ChairStPeter'             => [4, null],
            'StFabianPope'             => [2, null],
            'StBassianoLodi'           => [2, null],
            'StSebastian'              => [3, null],
            'StAgnes'                  => [3, null],
            'StVincentDeacon'          => [2, null],
            'StBabylasCompanions'      => [2, null],
            'StFrancisDeSales'         => [3, null],
            'ConversionStPaul'         => [4, null],
            'StsTimothyTitus'          => [3, null],
            'StAngelaMerici'           => [2, null],
            'StThomasAquinas'          => [3, null],
            'StJohnBosco'              => [3, null],
        ];
    }

    public function testDataFileLoadsIntoMapWithItalianNames(): void
    {
        $raw   = $this->loadRawRows();
        $names = $this->loadNames('it');
        $map   = PropriumDeSanctisMap::fromObject($raw);
        $map->setNames($names);

        foreach (self::januarySentinels() as $key => $_expected) {
            $this->assertTrue($map->offsetExists($key), "Missing January sanctorale key: $key");
        }
    }

    public function testJanuarySentinelsHaveExpectedGradeAndIsDominical(): void
    {
        $raw   = $this->loadRawRows();
        $names = $this->loadNames('it');
        $map   = PropriumDeSanctisMap::fromObject($raw);
        $map->setNames($names);

        foreach (self::januarySentinels() as $key => [$expectedGrade, $expectedIsDominical]) {
            $this->assertTrue($map->offsetExists($key), "Missing January sanctorale key: $key");
            $event = $map[$key];
            $this->assertSame($expectedGrade, $event->grade->value, "Unexpected grade for $key");
            if ($expectedIsDominical === true) {
                $this->assertTrue($event->is_dominical, "Expected is_dominical === true for $key");
            } else {
                $this->assertNotTrue($event->is_dominical, "Expected is_dominical to not be true for $key");
            }
        }
    }

    public function testItalianAndLatinI18nCoverEveryDataKey(): void
    {
        $raw = $this->loadRawRows();
        $it  = $this->loadNames('it');
        $la  = $this->loadNames('la');

        $dataKeys = [];
        foreach ($raw as $event) {
            $key        = $event->event_key;
            $dataKeys[] = $key;
            $this->assertArrayHasKey($key, $it, "it.json missing name for $key");
            $this->assertArrayHasKey($key, $la, "la.json missing name for $key");
        }

        // Reverse direction: an i18n key with no corresponding data-file entry is an orphan
        // (e.g. a stale/renamed key) and must fail loudly rather than silently going unused.
        $itOrphans = array_diff(array_keys($it), $dataKeys);
        $laOrphans = array_diff(array_keys($la), $dataKeys);
        $this->assertSame([], array_values($itOrphans), 'it.json has orphan keys not present in the data file: ' . implode(', ', $itOrphans));
        $this->assertSame([], array_values($laOrphans), 'la.json has orphan keys not present in the data file: ' . implode(', ', $laOrphans));
    }

    public function testEveryRowValidatesAgainstThePropriumDeSanctisSchema(): void
    {
        $raw    = $this->loadRawRows();
        $schema = Schema::import(LitSchema::PROPRIUMDESANCTIS->path());
        $schema->in($raw);
        $this->addToAssertionCount(1);
    }

    /**
     * Every sentinel key asserted above must actually be present in the data file (guards against a
     * typo in the sentinel list silently never being exercised).
     */
    public function testSentinelListMatchesDataFileKeys(): void
    {
        $raw          = $this->loadRawRows();
        $dataKeys     = array_map(fn (\stdClass $event): string => $event->event_key, $raw);
        $sentinelKeys = array_keys(self::januarySentinels());

        $missingFromData     = array_diff($sentinelKeys, $dataKeys);
        $missingFromSentinel = array_diff($dataKeys, $sentinelKeys);
        $this->assertSame([], array_values($missingFromData), 'Sentinel keys missing from the data file: ' . implode(', ', $missingFromData));
        $this->assertSame([], array_values($missingFromSentinel), 'Data file keys missing from the sentinel list: ' . implode(', ', $missingFromSentinel));
    }
}
