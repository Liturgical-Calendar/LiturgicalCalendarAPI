<?php

namespace LiturgicalCalendar\Api\Models\MissalsPath;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\RomanMissal;
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
    /** @var array<string,MissalMetadata> */
    private array $missals;
    /** @var array<string,MissalMetadata> */
    private array $allMissals;

    private string $regionFilter;
    private int $yearFilter;
    private bool $includeEmpty = false;

    public function __construct()
    {
        $this->missals    = [];
        $this->allMissals = [];
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

    public function buildIndex(): void
    {
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch('litcal_missals_index', $success);
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

                $this->missals    = $missals;
                $this->allMissals = $allMissals;
                return;
            }
        }

        if (false === is_readable(JsonData::MISSALS_FOLDER->path())) {
            $description = 'Unable to read the ' . JsonData::MISSALS_FOLDER->path() . ' directory';
            throw new ServiceUnavailableException($description);
        }

        $missalFolderPaths = glob(JsonData::MISSALS_FOLDER->path() . '/propriumdesanctis*', GLOB_ONLYDIR);
        if (false === $missalFolderPaths) {
            $description = 'Unable to read the ' . JsonData::MISSALS_FOLDER->path() . ' directory contents';
            throw new ServiceUnavailableException($description);
        }

        if (count($missalFolderPaths) === 0) {
            $description = 'No Missals found';
            throw new NotFoundException($description);
        }

        $missalFolderNames = array_map('basename', $missalFolderPaths);
        foreach ($missalFolderNames as $missalFolderName) {
            if (file_exists(JsonData::MISSALS_FOLDER->path() . "/$missalFolderName/$missalFolderName.json")) {
                $missal = [];

                if (preg_match('/^propriumdesanctis_([1-2][0-9][0-9][0-9])$/', $missalFolderName, $matches)) {
                    $missal['missal_id'] = "EDITIO_TYPICA_{$matches[1]}";
                    $missal['region']    = 'VA';
                } elseif (preg_match('/^propriumdesanctis_([A-Z]+)_([1-2][0-9][0-9][0-9])$/', $missalFolderName, $matches)) {
                    $missal['missal_id'] = "{$matches[1]}_{$matches[2]}";
                    $missal['region']    = $matches[1];
                } else {
                    $description = 'Unable to parse missal folder name: ' . $missalFolderName;
                    throw new ServiceUnavailableException($description);
                }

                if (is_readable(JsonData::MISSALS_FOLDER->path() . "/$missalFolderName/i18n")) {
                    $iterator = new \DirectoryIterator('glob://' . JsonData::MISSALS_FOLDER->path() . "/$missalFolderName/i18n/*.json");
                    $locales  = [];
                    foreach ($iterator as $f) {
                        $locales[] = $f->getBasename('.json');
                    }
                    $missal['locales'] = $locales;
                } else {
                    $missal['locales'] = null;
                }

                $missal['name']           = RomanMissal::getName($missal['missal_id']);
                $missal['year_limits']    = RomanMissal::getYearLimits($missal['missal_id']);
                $missal['year_published'] = $missal['year_limits']['since_year'];
                $missal['api_path']       = Router::$apiPath . "/missals/{$missal['missal_id']}";
                $this->addMissal(MissalMetadata::fromArray($missal));
            }
        }

        /** @var array<string,MissalMetadata> $allMissals */
        $allMissals       = RomanMissal::produceMetadata();
        $this->allMissals = $allMissals;

        apcu_store('litcal_missals_index', [
            'missals'    => $this->missals,
            'allMissals' => $this->allMissals
        ], 600);
    }
}
