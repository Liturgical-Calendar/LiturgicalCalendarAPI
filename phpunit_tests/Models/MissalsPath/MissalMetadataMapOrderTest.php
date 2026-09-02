<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\MissalsPath;

use LiturgicalCalendar\Api\ApcuCache;
use LiturgicalCalendar\Api\ApcuShimStore;
use LiturgicalCalendar\Api\Enum\MissalCatalog;
use LiturgicalCalendar\Api\Enum\MissalSource;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\MissalsPath\MissalMetadata;
use LiturgicalCalendar\Api\Models\MissalsPath\MissalMetadataMap;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The order missals come out of the index in (#964).
 *
 * `buildIndex()` used to iterate `MissalSource::getMissalIds()` and preserve that order all the way into
 * the response, so the listing was a property of how somebody happened to edit an array. For the Roman
 * rite that looked right by accident — `RomanMissal::$values` is declared roughly chronologically. For the
 * Ambrosian rite it is not: #959 appended the older `EDITIO_TYPICA_1976` after the existing 2024 edition,
 * so declaration order is reverse-chronological and the divergence became live.
 *
 * These tests pin the order as a function of the DATA — tier, then `since_year`, then `missal_id` — which
 * is what makes it survive somebody adding an edition in the middle of an array.
 */
#[CoversClass(MissalMetadataMap::class)]
final class MissalMetadataMapOrderTest extends TestCase
{
    private static string $savedApiPath;
    private static string $savedApiFilePath;

    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
        self::$savedApiPath     = Router::$apiPath;
        self::$savedApiFilePath = Router::$apiFilePath;
    }

    public static function tearDownAfterClass(): void
    {
        Router::$apiPath     = self::$savedApiPath;
        Router::$apiFilePath = self::$savedApiFilePath;
    }

    /**
     * The full catalogue a rite declares, in the order the index holds it.
     *
     * `$allMissals` is what `include_empty` reaches, and it is the only place the Ambrosian divergence is
     * visible today, since `buildIndex()` skips the data-less 1976 edition from the built index while
     * `produceMetadata()` keeps it. No public accessor returns it in order — `getMissalRegions()` collapses
     * it to unique regions and `getMissalYears()` sorts numerically — so reflection is the only way to ask
     * the question this class exists to answer.
     *
     * @return string[]
     */
    private static function catalogueOrder(MissalMetadataMap $map): array
    {
        $property = new \ReflectionProperty(MissalMetadataMap::class, 'allMissals');
        /** @var array<string,MissalMetadata> $all */
        $all = $property->getValue($map);

        return array_keys($all);
    }

    /**
     * Chronological within the tier, typical editions first — and NOT the enum's declaration order, which
     * for this rite is reverse-chronological. That inequality is the whole point: with only one Ambrosian
     * edition shipping sanctorale data, this catalogue is the only surface on which a data-derived order
     * and a declaration-derived one give different answers today.
     */
    public function testTheAmbrosianCatalogueIsChronologicalNotInDeclarationOrder(): void
    {
        $map = new MissalMetadataMap(Rite::AMBROSIAN);
        $map->buildIndex();

        $declared  = MissalCatalog::for(Rite::AMBROSIAN)->getMissalIds();
        $catalogue = self::catalogueOrder($map);

        self::assertSame(['EDITIO_TYPICA_1976', 'EDITIO_TYPICA_2024'], $catalogue);
        self::assertNotSame(
            $declared,
            $catalogue,
            'declaration order and listing order must differ here, or this test proves nothing'
        );
    }

    /**
     * The Roman listing, spelled out. This DOES differ from what the API returned before #964 — the
     * national block was `US_2011, IT_1983, IT_2020, NL_1978, CA_2011, CA_2016`, which was
     * `RomanMissal::$values`' edit history and nothing more. The typical-edition block is unchanged.
     *
     * `CA_2011` precedes `US_2011` because they share a `since_year` and the third key is `missal_id`
     * ascending — an explicit tie-break rather than a reliance on PHP's sort being stable, which would
     * have quietly restored declaration order for exactly that pair.
     */
    public function testTheRomanCatalogueIsOrderedByTierThenYearThenId(): void
    {
        $map = new MissalMetadataMap(Rite::ROMAN);
        $map->buildIndex();

        self::assertSame(
            [
                'EDITIO_TYPICA_1970',
                'EDITIO_TYPICA_1971',
                'EDITIO_TYPICA_1975',
                'EDITIO_TYPICA_2002',
                'EDITIO_TYPICA_2008',
                'NL_1978',
                'IT_1983',
                'CA_2011',
                'US_2011',
                'CA_2016',
                'IT_2020'
            ],
            self::catalogueOrder($map)
        );

        // The built index is the same order with the editions that ship no sanctorale data removed.
        self::assertSame(
            ['EDITIO_TYPICA_1970', 'EDITIO_TYPICA_2002', 'EDITIO_TYPICA_2008', 'IT_1983', 'US_2011'],
            $map->getMissalIDs()
        );
    }

    /** @return array<string,array{Rite}> */
    public static function riteProvider(): array
    {
        return [
            'roman'     => [Rite::ROMAN],
            'ambrosian' => [Rite::AMBROSIAN]
        ];
    }

    /**
     * The invariant behind both spellings above, asserted pairwise so a rite added later is covered by
     * adding one row to the provider rather than by hand-listing its editions.
     *
     * A missal declared anywhere in its rite's `$values` lands where its tier and year put it; nothing in
     * the sequence may depend on where it was written down.
     *
     * @param Rite $rite the rite whose catalogue is under test
     */
    #[DataProvider('riteProvider')]
    public function testEveryAdjacentPairIsOrderedByTheDeclaredKeys(Rite $rite): void
    {
        $map = new MissalMetadataMap($rite);
        $map->buildIndex();

        $source    = MissalCatalog::for($rite);
        $catalogue = self::catalogueOrder($map);
        self::assertGreaterThan(1, count($catalogue), 'a single-edition rite cannot exercise an ordering');

        $property = new \ReflectionProperty(MissalMetadataMap::class, 'allMissals');
        /** @var array<string,MissalMetadata> $all */
        $all = $property->getValue($map);

        for ($i = 1; $i < count($catalogue); ++$i) {
            $previous = $all[$catalogue[$i - 1]];
            $current  = $all[$catalogue[$i]];

            self::assertLessThanOrEqual(
                0,
                self::rank($source, $previous) <=> self::rank($source, $current),
                "{$previous->missal_id} must not follow {$current->missal_id}"
            );
        }
    }

    /**
     * A cache hit is sorted too, rather than trusted.
     *
     * `buildIndex()` returns early on an APCu hit, before the `sortByEdition()` calls on the build path.
     * The key `litcal_missals_index_{rite}` is unversioned, so an entry written by pre-#964 code comes
     * back in declaration order and the whole fix would be inert until the 600-second TTL expired — and
     * more importantly the ordering guarantee would hold only for the path that happens to build the
     * index, which is a structural gap rather than a post-deploy window.
     *
     * The cache is primed by REVERSING a freshly built index, which for both maps is precisely the
     * declaration-order shape a stale entry carries. `assertNotSame` on the primed order is the
     * precondition: without it a sort-on-read that never ran would still pass.
     *
     * ext-apcu is absent under CLI here, so this drives `phpunit_tests/Support/ApcuShim.php` — the same
     * namespaced stand-in `ApcuCacheDetectionTest` uses, and the reason `ApcuCache` lives in
     * `LiturgicalCalendar\Api` at all. The binding probe below is not ceremony: PHP caches the resolved
     * function in each call site's run-time cache, so on a host WITH a real ext-apcu an earlier test can
     * bind `ApcuCache`'s unqualified `apcu_*` calls to the global functions for the life of the process,
     * and the primed entry would then simply never be seen.
     */
    public function testACacheHitIsSortedRatherThanTrusted(): void
    {
        require_once dirname(__DIR__, 2) . '/Support/ApcuShim.php';

        $usableProperty = new \ReflectionProperty(ApcuCache::class, 'usable');
        $usableBefore   = $usableProperty->getValue();
        $key            = 'litcal_missals_index_' . Rite::ROMAN->value;
        $probeKey       = $key . '_binding_probe';

        $usableProperty->setValue(null, true);

        try {
            ApcuShimStore::store($probeKey, 'bound', 10);
            $probe = ApcuCache::fetch($probeKey, $found);
            ApcuShimStore::delete($probeKey);
            if (true !== $found || 'bound' !== $probe) {
                self::markTestSkipped('ApcuCache is not bound to phpunit_tests/Support/ApcuShim.php in this process');
            }

            ApcuShimStore::delete($key);
            $fresh = new MissalMetadataMap(Rite::ROMAN);
            $fresh->buildIndex();

            $missalsProperty = new \ReflectionProperty(MissalMetadataMap::class, 'missals');
            $allProperty     = new \ReflectionProperty(MissalMetadataMap::class, 'allMissals');

            /** @var array<string,MissalMetadata> $builtIndex */
            $builtIndex = $missalsProperty->getValue($fresh);
            /** @var array<string,MissalMetadata> $builtCatalogue */
            $builtCatalogue = $allProperty->getValue($fresh);

            $expectedIndex     = array_keys($builtIndex);
            $expectedCatalogue = array_keys($builtCatalogue);

            $staleIndex     = array_reverse($builtIndex, true);
            $staleCatalogue = array_reverse($builtCatalogue, true);

            self::assertNotSame($expectedIndex, array_keys($staleIndex), 'the primed entry must be mis-ordered, or this test proves nothing');
            self::assertNotSame($expectedCatalogue, array_keys($staleCatalogue));

            ApcuShimStore::store($key, ['missals' => $staleIndex, 'allMissals' => $staleCatalogue], 600);

            $fromCache = new MissalMetadataMap(Rite::ROMAN);
            $fromCache->buildIndex();

            self::assertSame($expectedIndex, $fromCache->getMissalIDs(), 'a cached index must come back sorted, not in whatever order it was stored');
            /** @var array<string,MissalMetadata> $cachedCatalogue */
            $cachedCatalogue = $allProperty->getValue($fromCache);
            self::assertSame($expectedCatalogue, array_keys($cachedCatalogue), 'the cached full catalogue must be sorted on read too');
        } finally {
            ApcuShimStore::delete($key);
            ApcuShimStore::delete($probeKey);
            $usableProperty->setValue(null, $usableBefore);
        }
    }

    /**
     * The sort key, restated as data so the assertion above compares something rather than re-running the
     * implementation's comparator. `isEditioTypica()` is negated because typical editions come FIRST.
     *
     * @return array{int,int,string}
     */
    private static function rank(MissalSource $source, MissalMetadata $missal): array
    {
        return [
            $source->isEditioTypica($missal->missal_id) ? 0 : 1,
            $missal->year_limits->since_year,
            $missal->missal_id
        ];
    }
}
