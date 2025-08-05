<?php

namespace LiturgicalCalendar\Api\Models\CatholicDiocesesLatinRite;

use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;

final class CountryWithDiocesesItem extends AbstractJsonSrcData
{
    public readonly string $country_iso;
    public readonly string $country_name_english;
    /** @var DioceseItem[] */
    public readonly array $dioceses;

    /**
     * @param string $country_iso
     * @param string $country_name_english
     * @param DioceseItem[] $dioceses
     */
    private function __construct(
        string $country_iso,
        string $country_name_english,
        array $dioceses
    ) {
        $this->country_iso          = $country_iso;
        $this->country_name_english = $country_name_english;
        $this->dioceses             = $dioceses;
    }

    public function isValidDioceseId(string $dioceseId): bool
    {
        return in_array($dioceseId, array_map(fn (DioceseItem $diocese): string => $diocese->diocese_id, $this->dioceses), true);
    }

    /**
     * Creates a new CountryWithDioceses from an associative array.
     *
     * @param array{
     *     country_iso: string,
     *     country_name_english: string,
     *     dioceses: array<array{
     *         diocese_name: string,
     *         diocese_id: string,
     *         province?: string
     *     }>
     * } $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        return new static(
            $data['country_iso'],
            $data['country_name_english'],
            array_map(fn (array $diocese): DioceseItem => DioceseItem::fromArray($diocese), $data['dioceses'])
        );
    }

    /**
     * Creates a new CountryWithDioceses from an object.
     *
     * The object must have the following properties:
     * - country_iso (string): The ISO 3166-1 alpha-2 code of the country.
     * - country_name_english (string): The name of the country in English.
     * - dioceses (array<object>): The dioceses. Each object must have the same properties as DioceseItem.
     *
     * @param \stdClass $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        return new static(
            $data->country_iso,
            $data->country_name_english,
            array_map(fn (\stdClass $diocese): DioceseItem => DioceseItem::fromObject($diocese), $data->dioceses)
        );
    }
}
