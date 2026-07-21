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
 * Data test for the comune ambrosiano sanctorale (Plan 5: Task 4 authored January as the worked
 * template; Task 5a extends it with February-June).
 *
 * This test grows with each subsequent month (Task 5b still has to add Jul-Dec rows/keys): it
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

    /**
     * February-June sentinel keys (Task 5a), same (grade, is_dominical) shape as {@see januarySentinels()}.
     * `is_dominical` is `true` only for the fixed-date "Festa/Solennità dS" entries (the Presentation of
     * the Lord, the Annunciation, and the Visitation); the several mobile "dS" entries in this range
     * (Sunday/Thursday-after-Pentecost feasts, Monday-after-Pentecost, the Sacred/Immaculate Hearts) are
     * intentionally NOT authored as sanctorale rows since they are computed from the temporale, not fixed
     * dates.
     *
     * @return array<string,array{0:int,1:?bool}>
     */
    private static function februaryToJuneSentinels(): array
    {
        return [
            'BlAndreaCarloFerrari'                  => [3, null],
            'PresentationOfTheLord'                 => [4, true],
            'StBlaise'                              => [2, null],
            'StOscar'                               => [2, null],
            'StAgatha'                              => [3, null],
            'StPaulMikiCompanions'                  => [3, null],
            'StsPerpetuaFelicity'                   => [3, null],
            'StJeromeEmiliani'                      => [3, null],
            'StJosephineBakhita'                    => [2, null],
            'StScholastica'                         => [3, null],
            'OurLadyOfLourdes'                      => [2, null],
            'StsCyrilMethodius'                     => [4, null],
            'SevenHolyFoundersServites'             => [2, null],
            'StPatritius'                           => [2, null],
            'StTuribiusMogrovejo'                   => [2, null],
            'StPeterDamian'                         => [2, null],
            'StPolycarp'                            => [3, null],
            'StGregoryOfNarek'                      => [2, null],
            'StJosephSpouseBVM'                     => [6, null],
            'Annunciation'                          => [6, true],
            'StFrancisOfPaola'                      => [2, null],
            'StIsidoreOfSeville'                    => [2, null],
            'StVincentFerrer'                       => [2, null],
            'StJohnBaptistDeLaSalle'                => [3, null],
            'StFrancesOfRome'                       => [2, null],
            'StCyrilOfJerusalem'                    => [2, null],
            'StStanislaus'                          => [3, null],
            'StZenoOfVerona'                        => [2, null],
            'StMartinIPope'                         => [2, null],
            'StGaldino'                             => [3, null],
            'StAnselm'                              => [2, null],
            'StGeorge'                              => [2, null],
            'StAdalbert'                            => [2, null],
            'StFidelisOfSigmaringen'                => [2, null],
            'StMarkEvangelist'                      => [4, null],
            'StLouisMarieDeMontfort'                => [2, null],
            'StPeterChanel'                         => [2, null],
            'BlsCaterinaGiulianaVarese'             => [3, null],
            'StGiannaBerettaMolla'                  => [3, null],
            'StCatherineOfSiena'                    => [4, null],
            'StPiusV'                               => [2, null],
            'StJosephCottolengo'                    => [2, null],
            'StRichardPampuri'                      => [2, null],
            'StJosephTheWorker'                     => [3, null],
            'StAthanasius'                          => [3, null],
            'StsPhilipJames'                        => [4, null],
            'StVictorMartyr'                        => [3, null],
            'StMaddalenaOfCanossa'                  => [2, null],
            'StJohnOfAvila'                         => [2, null],
            'StsNereusAchilleus'                    => [2, null],
            'StPancras'                             => [2, null],
            'OurLadyOfFatima'                       => [2, null],
            'StMatthiasApostle'                     => [4, null],
            'StLuigiOrione'                         => [2, null],
            'StJohnIPope'                           => [2, null],
            'StsBartolomeaVincenza'                 => [2, null],
            'StBernardineOfSiena'                   => [2, null],
            'StsCristoforoMagallanesCompanions'     => [2, null],
            'StRitaOfCascia'                        => [2, null],
            'StBedeVenerabilis'                     => [2, null],
            'StMariaMaddalenaDePazzi'               => [2, null],
            'StGregoryVIIPope'                      => [2, null],
            'StDionysius'                           => [3, null],
            'StPhilipNeri'                          => [3, null],
            'StAugustineOfCanterbury'               => [2, null],
            'StsSisiniusMartiriusAlexanderVigilius' => [3, null],
            'StPaulVIPope'                          => [3, null],
            'VisitationBVM'                         => [4, true],
            'StJustinMartyr'                        => [3, null],
            'StsMarcellinusPeter'                   => [2, null],
            'StCharlesLwangaCompanions'             => [3, null],
            'StBonifaceMartyr'                      => [3, null],
            'StNorbert'                             => [2, null],
            'StEphremDeacon'                        => [2, null],
            'StBarnabasApostle'                     => [4, null],
            'StAnthonyOfPadua'                      => [3, null],
            'StRomualdAbbot'                        => [2, null],
            'StsProtaseGervase'                     => [4, null],
            'StAloysiusGonzaga'                     => [3, null],
            'StPaulinusOfNola'                      => [2, null],
            'StsJohnFisherThomasMore'               => [2, null],
            'NativityStJohnBaptist'                 => [6, null],
            'StCyrilOfAlexandria'                   => [2, null],
            'StIrenaeus'                            => [3, null],
            'StsPeterPaulApostles'                  => [6, null],
            'FirstMartyrsRomanChurch'               => [2, null],
        ];
    }

    /**
     * Combined sentinel list for all months authored so far (January + February-June). Task 5b will
     * extend this further with a `julyToDecemberSentinels()` counterpart.
     *
     * @return array<string,array{0:int,1:?bool}>
     */
    private static function allSentinels(): array
    {
        return array_merge(self::januarySentinels(), self::februaryToJuneSentinels());
    }

    public function testDataFileLoadsIntoMapWithItalianNames(): void
    {
        $raw   = $this->loadRawRows();
        $names = $this->loadNames('it');
        $map   = PropriumDeSanctisMap::fromObject($raw);
        $map->setNames($names);

        foreach (array_keys(self::allSentinels()) as $key) {
            $this->assertTrue($map->offsetExists($key), "Missing sanctorale key: $key");
        }
    }

    public function testSentinelsHaveExpectedGradeAndIsDominical(): void
    {
        $raw   = $this->loadRawRows();
        $names = $this->loadNames('it');
        $map   = PropriumDeSanctisMap::fromObject($raw);
        $map->setNames($names);

        foreach (self::allSentinels() as $key => [$expectedGrade, $expectedIsDominical]) {
            $this->assertTrue($map->offsetExists($key), "Missing sanctorale key: $key");
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
        $sentinelKeys = array_keys(self::allSentinels());

        $missingFromData     = array_diff($sentinelKeys, $dataKeys);
        $missingFromSentinel = array_diff($dataKeys, $sentinelKeys);
        $this->assertSame([], array_values($missingFromData), 'Sentinel keys missing from the data file: ' . implode(', ', $missingFromData));
        $this->assertSame([], array_values($missingFromSentinel), 'Data file keys missing from the sentinel list: ' . implode(', ', $missingFromSentinel));
    }
}
