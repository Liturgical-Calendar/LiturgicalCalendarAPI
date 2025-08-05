<?php

namespace LiturgicalCalendar\Api\Models\MissalsPath;

/**
 * Represents a collection of missals.
 *
 * @implements \IteratorAggregate<string,MissalMetadata>
 */
final class MissalMetadataMap implements \IteratorAggregate, \JsonSerializable
{
    /** @var array<string,MissalMetadata> */
    private array $missals;

    private string $regionFilter;
    private int $yearFilter;

    public function __construct()
    {
        $this->missals = [];
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
     * @return array{litcal_missals:list<array{missal_id:string,name:string,region:string,locales:string[],api_path:string,year_published:int}>} An associative array containing the collection of missals as 'litcal_missals' key.
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
    public function getMissal(string $missal_id): ?MissalMetadata
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
     * @return array<string> An array of missal_ids.
     */
    public function getMissalIDs(): array
    {
        return array_keys($this->missals);
    }
}
