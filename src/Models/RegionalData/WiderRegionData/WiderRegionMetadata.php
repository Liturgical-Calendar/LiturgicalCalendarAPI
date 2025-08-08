<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\WiderRegionData;

use LiturgicalCalendar\Api\Models\AbstractJsonRepresentation;

final class WiderRegionMetadata extends AbstractJsonRepresentation
{
    /** @var string[] */
    public readonly array $locales;

    /** @var string */
    public string $wider_region;

    /**
     * @param string[] $locales The locales supported by the Wider region.
     * @param string $wider_region The identifier of the Wider region.
     */
    private function __construct(array $locales, string $wider_region)
    {
        $this->locales      = $locales;
        $this->wider_region = $wider_region;
    }

    public function jsonSerialize(): mixed
    {
        return get_object_vars($this);
    }

    /**
     * Creates an instance of WiderRegionMetadata from an associative array.
     *
     * Validates the structure of the provided array to ensure that it contains
     * the required keys: 'locales' and 'wider_region'. Each of these keys must
     * map to a non-empty array and a string, respectively. If any key is missing
     * or does not meet the criteria, an appropriate error is thrown.
     *
     * @param array{locales:string[],wider_region:string} $data The associative array containing the data for the
     *                     WiderRegionMetadata instance. It must include:
     *                     - 'locales': An array of locales supported by the Wider region.
     *                     - 'wider_region': A string representing the identifier of the Wider region.
     *
     * @return static A new instance of WiderRegionMetadata initialized with the
     *                provided data.
     *
     * @throws \ValueError If any of the required keys ('locales', 'wider_region') are not present.
     * @throws \TypeError If 'locales' is not an array or is empty, or if 'wider_region' is not a string.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (!isset($data['locales']) || !isset($data['wider_region'])) {
            throw new \ValueError('locales and wider_region parameters are required');
        }

        if (false === is_array($data['locales']) || 0 === count($data['locales'])) {
            throw new \TypeError('locales parameter must be an array and must not be empty');
        }

        if (false === is_string($data['wider_region'])) {
            throw new \TypeError('wider_region parameter must be a string');
        }

        return new static(
            $data['locales'],
            $data['wider_region']
        );
    }

    /**
     * Creates an instance of WiderRegionMetadata from a stdClass object.
     *
     * Validates the structure of the provided object to ensure that it contains
     * the required properties: 'locales' and 'wider_region'. Each of these
     * properties must map to a non-empty array and a string, respectively. If
     * any property is missing or does not meet the criteria, an appropriate
     * error is thrown.
     *
     * @param \stdClass&object{locales:string[],wider_region:string} $data The object containing the data for the
     *                     WiderRegionMetadata instance. It must include:
     *                     - 'locales': An array of locales supported by the Wider region.
     *                     - 'wider_region': A string representing the identifier of the Wider region.
     *
     * @return static A new instance of WiderRegionMetadata initialized with the
     *                provided data.
     *
     * @throws \ValueError If any of the required properties ('locales', 'wider_region') are not present.
     * @throws \TypeError If 'locales' is not an array or is empty, or if 'wider_region' is not a string.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (!isset($data->locales) || !isset($data->wider_region)) {
            throw new \ValueError('locales and wider_region parameters are required');
        }

        if (false === is_array($data->locales) || 0 === count($data->locales)) {
            throw new \TypeError('locales parameter must be an array and must not be empty');
        }

        if (false === is_string($data->wider_region)) {
            throw new \TypeError('wider_region parameter must be a string');
        }

        return new static(
            $data->locales,
            $data->wider_region
        );
    }
}
