<?php

namespace LiturgicalCalendar\Api\Models\CatholicDiocesesLatinRite;

use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;

final class CatholicDiocesesMap extends AbstractJsonSrcData
{
    /** @var array<string,CountryWithDiocesesItem> */
    public readonly array $diocesesByCountry;

    /**
     * @param array<string,CountryWithDiocesesItem> $diocesesByCountry
     */
    private function __construct(array $diocesesByCountry)
    {
        $this->diocesesByCountry = $diocesesByCountry;
    }

    public function hasKey(string $countryIso): bool
    {
        $countryIso = strtolower($countryIso);
        return array_key_exists($countryIso, $this->diocesesByCountry);
    }

    /**
     * @return string[] A list of ISO 3166-1 alpha-2 country codes that are keys in the $diocesesByCountry array.
     *                 The list is sorted alphabetically.
     */
    public function getKeys(): array
    {
        return array_map('strtoupper', array_keys($this->diocesesByCountry));
    }

    public function getCountryWithDiocesesItem(string $countryIso): ?CountryWithDiocesesItem
    {
        $countryIso = strtolower($countryIso);
        return $this->diocesesByCountry[$countryIso] ?? null;
    }

    public function isValidDioceseIdForCountry(string $countryIso, string $dioceseId): bool
    {
        $countryWithDiocesesItem = $this->getCountryWithDiocesesItem($countryIso);
        if ($countryWithDiocesesItem === null) {
            return false;
        }
        return $countryWithDiocesesItem->isValidDioceseId($dioceseId);
    }

    /**
     * @param string $countryIso The ISO 3166-1 alpha-2 code of the country.
     * @return string[] A list of valid diocese IDs for the specified country.
     *                 An empty array is returned if the country is not found.
     */
    public function getValidDioceseIdsForCountry(string $countryIso): array
    {
        $countryWithDiocesesItem = $this->getCountryWithDiocesesItem($countryIso);
        if ($countryWithDiocesesItem === null) {
            return [];
        }
        return array_map(fn (DioceseItem $diocese): string => $diocese->diocese_id, $countryWithDiocesesItem->dioceses);
    }

    /**
     * Returns the name of a diocese given its ID and country code.
     *
     * @param string $countryIso The ISO 3166-1 alpha-2 code of the country.
     * @param string $dioceseId The ID of the diocese.
     * @return string|null The name of the diocese or null if the country or diocese ID is not found.
     */
    public function dioceseNameFromId(string $countryIso, string $dioceseId): ?string
    {
        $countryWithDiocesesItem = $this->getCountryWithDiocesesItem($countryIso);
        if ($countryWithDiocesesItem === null) {
            return null;
        }
        return $countryWithDiocesesItem->dioceseNameFromId($dioceseId);
    }

    /**
     * Given a diocese ID, returns an associative array with the keys 'country_iso' and 'diocese_name',
     * or null if the diocese ID is not found.
     * The value of 'country_iso' is the ISO 3166-1 alpha-2 code of the country where the diocese is located,
     * and the value of 'diocese_name' is the name of the diocese.
     *
     * @param string $dioceseId The diocese ID.
     * @return array{country_iso: string, diocese_name: string}|null
     */
    public function dioceseNameAndNationFromId(string $dioceseId): ?array
    {
        foreach ($this->diocesesByCountry as $countryIso => $countryWithDiocesesItem) {
            if ($countryWithDiocesesItem->isValidDioceseId($dioceseId)) {
                $dioceseName = $countryWithDiocesesItem->dioceseNameFromId($dioceseId);
                if (null === $dioceseName) {
                    throw new \InvalidArgumentException('Missing diocese name for diocese id: ' . $dioceseId . ' in country: ' . $countryIso . '.');
                }
                return ['country_iso' => $countryIso, 'diocese_name' => $dioceseName];
            }
        }
        return null;
    }

    /**
     * Creates a new CatholicDiocesesCollection from an associative array.
     *
     * The array must have the following key:
     * - catholic_dioceses_latin_rite (array<array>): The dioceses. Each array must have the same keys as CountryWithDioceses.
     *
     * @param array{
     *      catholic_dioceses_latin_rite: array<array{
     *          country_iso: string,
     *          country_name_english: string,
     *          dioceses: array<array{
     *              diocese_name: string,
     *              diocese_id: string,
     *              province?: string
     *          }>
     *      }>
     * } $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        $countries    = array_column($data['catholic_dioceses_latin_rite'], 'country_iso');
        $countryItems = array_values(array_map(fn (array $countryItem): CountryWithDiocesesItem => CountryWithDiocesesItem::fromArray($countryItem), $data['catholic_dioceses_latin_rite']));
        return new static(array_combine($countries, $countryItems));
    }

    /**
     * Creates a new CatholicDiocesesCollection from an object.
     *
     * The object must have the following property:
     * - catholic_dioceses_latin_rite (array<object>): The dioceses. Each object must have the same properties as CountryWithDioceses.
     *
     * @param \stdClass&object{catholic_dioceses_latin_rite:\stdClass[]} $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        $countries    = array_map(
            fn(\stdClass $item) => $item->country_iso,
            $data->catholic_dioceses_latin_rite
        );
        $countryItems = array_map(fn (\stdClass $countryItem): CountryWithDiocesesItem => CountryWithDiocesesItem::fromObject($countryItem), $data->catholic_dioceses_latin_rite);
        return new static(array_combine($countries, $countryItems));
    }
}
