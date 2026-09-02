<?php

namespace LiturgicalCalendar\Api\Models\MissalsPath;

use LiturgicalCalendar\Api\ApcuCache;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\MissalCatalog;
use LiturgicalCalendar\Api\Enum\MissalSource;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Router;

/**
 * Represents a collection of missals.
 *
 * @implements \IteratorAggregate<string,MissalMetadata>
 */
final class MissalMetadataMap implements \IteratorAggregate, \JsonSerializable
{
    private const string CACHE_KEY_PREFIX = 'litcal_missals_index';

    private readonly Rite $rite;

    /** @var array<string,MissalMetadata> */
    private array $missals;
    /** @var array<string,MissalMetadata> */
    private array $allMissals;

    private string $regionFilter;
    private int $yearFilter;
    private bool $includeEmpty = false;

    public function __construct(Rite $rite = Rite::ROMAN)
    {
        $this->rite       = $rite;
        $this->missals    = [];
        $this->allMissals = [];
    }

    /**
     * The APCu key for this rite's index.
     *
     * Per rite, deliberately. A single key let whichever rite built the index first serve the
     * other for the whole 600-second TTL (#953).
     */
    private function cacheKey(): string
    {
        return self::CACHE_KEY_PREFIX . '_' . $this->rite->value;
    }

    /**
     * @return \Traversable<string,MissalMetadata> An iterator for the items in the collection.
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->missals);
    }

    /**
     * Converts the collection of missals into an associative array that can be safely serialized into JSON.
     *
     * The resulting array will have a single key, 'litcal_missals', which will contain an array of MissalMetadata objects.
     *
     * @return array{litcal_missals:list<array{missal_id:string,name:string,region:string,locales:string[],api_path:?string,year_published:int}>} An associative array containing the collection of missals as 'litcal_missals' key.
     */
    public function jsonSerialize(): array
    {
        $missals = array_values($this->missals);

        if (isset($this->regionFilter)) {
            $missals = array_values(array_filter(
                $missals,
                fn (MissalMetadata $missal) => $missal->region === $this->regionFilter
            ));
        }

        if (isset($this->yearFilter)) {
            $missals = array_values(array_filter(
                $missals,
                fn (MissalMetadata $missal) => $missal->year_published === $this->yearFilter
            ));
        }

        return [
            'litcal_missals' => array_map(fn (MissalMetadata $missal) => $missal->jsonSerialize(), $missals)
        ];
    }

    /**
     * Adds a MissalMetadata object to the collection.
     *
     * @param MissalMetadata $missal The MissalMetadata object to add.
     * @return void
     */
    public function addMissal(MissalMetadata $missal): void
    {
        $this->missals[$missal->missal_id] = $missal;
    }

    /**
     * Checks if a MissalMetadata object exists in the collection with the given missal_id.
     *
     * @param string $missal_id The missal_id to check.
     * @return bool True if the MissalMetadata object exists, false otherwise.
     */
    public function hasMissal(string $missal_id): bool
    {
        return array_key_exists($missal_id, $this->missals);
    }

    /**
     * Retrieves a MissalMetadata object from the collection based on the given missal_id.
     *
     * @param string $missal_id The identifier of the MissalMetadata to retrieve.
     * @return ?MissalMetadata The MissalMetadata object if found, or null if it does not exist.
     */
    public function getMissalMetadata(string $missal_id): ?MissalMetadata
    {
        return $this->missals[$missal_id] ?? null;
    }


    public function setRegionFilter(string $region): void
    {
        $this->regionFilter = $region;
    }

    public function setYearFilter(int $year): void
    {
        $this->yearFilter = $year;
    }

    /**
     * Retrieves the collection of MissalMetadata objects.
     *
     * @return array<MissalMetadata> An array of MissalMetadata objects.
     */
    public function getMissals(): array
    {
        return array_values($this->missals);
    }

    /**
     * Retrieves an array of missal_ids of the MissalMetadata objects in the collection.
     *
     * @return string[] An array of missal_ids.
     */
    public function getMissalIDs(): array
    {
        return array_keys($this->missals);
    }

    /**
     * Retrieves an array of the regions of the MissalMetadata objects in the collection.
     *
     * @return string[] An array of regions.
     */
    public function getMissalRegions(): array
    {
        $source  = $this->includeEmpty ? $this->allMissals : $this->missals;
        $regions = array_map(
            fn (MissalMetadata $missal) => $missal->region,
            $source
        );

        return array_values(array_unique($regions));
    }

    /**
     * Retrieves an array of the publication years of the MissalMetadata objects in the collection.
     *
     * @return int[] An array of publication years, sorted in ascending order.
     */
    public function getMissalYears(): array
    {
        $source = $this->includeEmpty ? $this->allMissals : $this->missals;
        $years  = array_map(
            fn (MissalMetadata $missal) => $missal->year_published,
            $source
        );

        sort($years, SORT_NUMERIC);

        return array_values(array_unique($years));
    }

    public function isEmpty(): bool
    {
        return empty($this->missals);
    }

    public function setIncludeEmpty(bool $includeEmpty): void
    {
        $this->includeEmpty = $includeEmpty;
    }

    /**
     * Order a rite's missals by what the data says about them, not by where somebody put them in an array.
     *
     * The listing used to come out in `MissalSource::getMissalIds()` order, and for the Roman rite that
     * looked right by convention rather than by construction: `RomanMissal::$values` simply happens to be
     * declared roughly chronologically. `AmbrosianMissal::$values` is not — #959 appended the older
     * `EDITIO_TYPICA_1976` after the existing 2024 edition — so once 1976 ships sanctorale data the
     * default listing would have shown the second edition before the first, with nothing to say the order
     * was arbitrary (#964).
     *
     * Three keys, in order:
     *
     * 1. **`isEditioTypica()` descending.** Typical editions first, keeping the Roman response's existing
     *    two-block shape (the five typical editions, then the national ones) rather than interleaving
     *    `NL_1978` and `IT_1983` among them. Asked of the source, never `str_starts_with('EDITIO_TYPICA_')`
     *    — see {@see MissalSource::isEditioTypica()} on why the prefix is a naming convention, not a tier.
     * 2. **`year_limits['since_year']` ascending.** Chronological within each block, which is the intent
     *    the Roman declaration order was approximating.
     * 3. **`missal_id` ascending.** An explicit tie-break, not cosmetics: `US_2011` and `CA_2011` share a
     *    `since_year`, and leaning on PHP's stable sort to keep them in declaration order would reintroduce
     *    exactly the "correct by convention" fragility this method exists to remove.
     *
     * Note that this DOES visibly reorder the existing Roman national block, from
     * `US_2011, IT_1983, IT_2020, NL_1978, CA_2011, CA_2016` to
     * `NL_1978, IT_1983, CA_2011, US_2011, CA_2016, IT_2020`. That is accepted: the previous order was a
     * property of the enum's edit history, not of the missals. The typical-edition block is unchanged.
     *
     * `uasort` rather than `usort`: both maps are keyed by `missal_id`, and {@see self::hasMissal()} and
     * {@see self::getMissalMetadata()} are key lookups.
     *
     * @param array<string,MissalMetadata> $missals
     * @return array<string,MissalMetadata>
     */
    private static function sortByEdition(array $missals, MissalSource $source): array
    {
        uasort($missals, static function (MissalMetadata $a, MissalMetadata $b) use ($source): int {
            $tier = $source->isEditioTypica($b->missal_id) <=> $source->isEditioTypica($a->missal_id);
            if (0 !== $tier) {
                return $tier;
            }

            $year = $a->year_limits->since_year <=> $b->year_limits->since_year;
            if (0 !== $year) {
                return $year;
            }

            return strcmp($a->missal_id, $b->missal_id);
        });

        return $missals;
    }

    public function buildIndex(): void
    {
        // #836: both the decision and the calls belong to ApcuCache, which lives in
        // `LiturgicalCalendar\Api`. An unqualified `apcu_store()` written *here* would resolve against
        // `LiturgicalCalendar\Api\Models\MissalsPath` first and so could reach a different function
        // than any usability check made elsewhere — which is exactly what used to happen.
        // `fetch()` reports both a miss and an unusable backend through `$success`, so an
        // `exists()` first would only add a second round trip (#836).
        $cached = ApcuCache::fetch($this->cacheKey(), $success);
        if (
            $success
            && is_array($cached)
            && isset($cached['missals'])
            && is_array($cached['missals'])
            && array_all($cached['missals'], fn ($item, $key): bool => is_string($key) && $item instanceof MissalMetadata)
            && isset($cached['allMissals'])
            && is_array($cached['allMissals'])
            && array_all($cached['allMissals'], fn ($item, $key): bool => is_string($key) && $item instanceof MissalMetadata)
        ) {
            /** @var array<string,MissalMetadata> $missals */
            $missals = $cached['missals'];
            /** @var array<string,MissalMetadata> $allMissals */
            $allMissals = $cached['allMissals'];

            // Sorted again on read rather than trusted from the entry (#964). The key
            // (`litcal_missals_index_{rite}`) is unversioned, so an entry written by a revision whose
            // ordering differed — pre-#964 code, most immediately — comes back in whatever order it was
            // stored in, and this early return never reaches the sort on the miss path below. Versioning
            // the key instead would fix only the entries alive at one deploy and would rely on somebody
            // remembering to bump a constant whenever the ordering changes: the "correct by convention"
            // failure #964 exists to remove. Sorting on read cannot be forgotten, and a `uasort` over
            // eleven items is negligible next to the guarantee that sorted order is a postcondition of
            // `buildIndex()` no matter who wrote the entry or when.
            $cachedSource = MissalCatalog::for($this->rite);

            $this->missals    = self::sortByEdition($missals, $cachedSource);
            $this->allMissals = self::sortByEdition($allMissals, $cachedSource);
            return;
        }

        $source      = MissalCatalog::for($this->rite);
        $missalsPath = JsonData::missalsFolderFor($this->rite)->path();

        if (false === is_readable($missalsPath)) {
            throw new ServiceUnavailableException('Unable to read the ' . $missalsPath . ' directory');
        }

        $missalFolderPaths = glob($missalsPath . '/propriumdesanctis*', GLOB_ONLYDIR);
        if (false === $missalFolderPaths) {
            throw new ServiceUnavailableException('Unable to read the ' . $missalsPath . ' directory contents');
        }

        if (count($missalFolderPaths) === 0) {
            throw new NotFoundException('No Missals found');
        }

        // The folder scan answers only "which editions are present on disk". Identity — id, region,
        // name, year limits — comes from the rite's MissalSource. Deriving the id from the folder
        // NAME is what made `propriumdesanctis_2024` read with region VA (#953) — the wrong region
        // for what MissalSource declares as an AMBROSIAN edition. The Ambrosian typical edition's
        // id was later renamed to EDITIO_TYPICA_2024 (#953 round 1), which happens to be the exact
        // string the old, since-removed folder-name derivation produced; region is what still
        // tells the two apart, since the derivation defaulted to VA and MissalSource says
        // AMBROSIAN.
        foreach ($source->getMissalIds() as $missalId) {
            $structureFile = $source->getSanctoraleFileName($missalId);
            if (false === $structureFile || false === file_exists($structureFile)) {
                continue;
            }

            $missal = [
                'missal_id' => $missalId,
                'region'    => $source->regionFor($missalId),
            ];

            $i18nPath = $source->getSanctoraleI18nFilePath($missalId);
            if (is_string($i18nPath) && is_readable($i18nPath)) {
                $locales = [];
                foreach (new \DirectoryIterator('glob://' . rtrim($i18nPath, '/\\') . '/*.json') as $f) {
                    $locales[] = $f->getBasename('.json');
                }
                sort($locales);
                $missal['locales'] = $locales;
            } else {
                // `MissalMetadata::$locales` is `array` (non-nullable): `RomanMissal::produceMetadata()`
                // uses the same empty-array convention for "no locale files", never null — `api_path`
                // is the field that already carries the "no sanctorale data at all" signal (it goes
                // null, not `locales`), so this stays consistent with that split rather than inventing
                // a second one. A missal with a structure file but no `i18n/` folder (reachable once
                // #957 lands a rite with sparser data) would otherwise hit a TypeError here.
                $missal['locales'] = [];
            }

            $missal['name']           = $source->getName($missalId);
            $missal['year_limits']    = $source->getYearLimits($missalId);
            $missal['year_published'] = $missal['year_limits']['since_year'];
            $missal['api_path']       = Router::$apiPath . '/missals/' . $this->rite->value . '/' . $missalId;
            $this->addMissal(MissalMetadata::fromArray($missal));
        }

        // Identity comes from the source, the same as every other fact derived in this loop —
        // NOT from a rite conditional, which would silently fall through to the Roman catalogue
        // for a rite this file has not been taught about yet.
        $this->allMissals = $source->produceMetadata();

        // Both maps are ordered here rather than left in the order the loop above happened to visit
        // them, which is `getMissalIds()`'s declaration order (#964).
        $this->missals    = self::sortByEdition($this->missals, $source);
        $this->allMissals = self::sortByEdition($this->allMissals, $source);

        ApcuCache::store($this->cacheKey(), [
            'missals'    => $this->missals,
            'allMissals' => $this->allMissals
        ], 600);
    }
}
