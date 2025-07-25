<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;

final class NationalMetadata extends AbstractJsonSrcData
{
    public readonly string $nation;

    /** @var string[] */
    public readonly array $locales;

    public readonly string $wider_region;

    /** @var string[] */
    public readonly array $missals;

    /**
     * Constructs a new instance of NationalMetadata.
     *
     * @param string $nation A two-letter country ISO code (capital letters).
     * @param string[] $locales An array of valid locale codes, must not be empty.
     * @param string $wider_region One of `Americas`, `Europe`, `Asia`, `Africa`, `Oceania`, `Middle East`, or `Antarctica`.
     * @param string[] $missals An array of valid Roman Missal identifiers.
     *
     * @throws \ValueError If any parameter does not meet the specified criteria.
     */
    private function __construct(string $nation, array $locales, string $wider_region, array $missals)
    {

        if (preg_match('/^[A-Z]{2}$/', $nation) !== 1) {
            throw new \ValueError('`metadata.nation` parameter must be a two letter country ISO code (capital letters)');
        }

        if (0 === count($locales)) {
            throw new \ValueError('`metadata.locales` parameter must be an array and must not be empty');
        }

        foreach ($locales as $locale) {
            if (false === is_string($locale)) {
                throw new \ValueError('`metadata.locales` parameter must be an array of strings, an item of a different type was detected');
            }
            if (false === LitLocale::isValid($locale)) {
                throw new \ValueError('`metadata.locales` parameter must be an array of valid locale codes');
            }
        }

        if (1 !== preg_match('/^(Americas|Europe|Asia|Africa|Oceania|Middle East|Antarctica)$/', $wider_region)) {
            throw new \ValueError('`metadata.wider_region` parameter must be one of `Americas`, `Europe`, `Asia`, `Africa`, `Oceania`, `Middle East`, or `Antarctica`');
        }

        foreach ($missals as $missal) {
            if (false === is_string($missal)) {
                throw new \ValueError('`metadata.missals` parameter must be an array of strings, an item of a different type was detected');
            }

            if (1 !== preg_match('/^[A-Z]{2}_[0-9]{4}$/', $missal)) {
                throw new \ValueError('`metadata.missals` parameter must be an array of valid Roman Missal identifiers, an item with a different value was detected');
            }
        }

        sort($locales);

        $this->nation       = $nation;
        $this->locales      = $locales;
        $this->wider_region = $wider_region;
        $this->missals      = $missals;
    }


    /**
     * Creates an instance of NationalMetadata from an associative array.
     *
     * The array must have the following keys:
     * - nation (string): A two-letter country ISO code (capital letters).
     * - locales (array<string>): An array of valid locale codes.
     * - wider_region (string): One of 'Americas', 'Europe', 'Asia', 'Africa', 'Oceania', 'Middle East', or 'Antarctica'.
     * - missals (array<string>): An array of valid Roman Missal identifiers.
     *
     * @param array{nation:string,locales:array<string>,wider_region:string,missals:array<string>} $data
     * @return static
     * @throws \ValueError If any parameter does not meet the specified criteria.
     */
    protected static function fromArrayInternal(array $data): static
    {
        return new static($data['nation'], $data['locales'], $data['wider_region'], $data['missals']);
    }

    /**
     * Creates an instance of NationalMetadata from a stdClass object.
     *
     * The object must have the following properties:
     * - nation (string): A two-letter country ISO code (capital letters).
     * - locales (array<string>): An array of valid locale codes.
     * - wider_region (string): One of 'Americas', 'Europe', 'Asia', 'Africa', 'Oceania', 'Middle East', or 'Antarctica'.
     * - missals (array<string>): An array of valid Roman Missal identifiers.
     *
     * @param \stdClass $data The object containing the properties of NationalMetadata.
     * @return static A new instance of NationalMetadata initialized with the provided data.
     * @throws \ValueError If any property does not meet the specified criteria.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        return new static($data->nation, $data->locales, $data->wider_region, $data->missals);
    }
}
