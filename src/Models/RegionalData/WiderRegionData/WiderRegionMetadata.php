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
    public function __construct(array $locales, string $wider_region)
    {
        $this->locales      = $locales;
        $this->wider_region = $wider_region;
    }

    public function jsonSerialize(): mixed
    {
        return get_object_vars($this);
    }

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
