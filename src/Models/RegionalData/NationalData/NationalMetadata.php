<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;
use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionName;

/**
  * @phpstan-type NationalMetadataObject \stdClass&object{
  *     nation:string,
  *     locales:string[],
  *     wider_region?:string,
  *     wider_regions?:string[],
  *     missals:string[]
  * }
  * @phpstan-type NationalMetadataArray array{
  *     nation:string,
  *     locales:string[],
  *     wider_region?:string,
  *     wider_regions?:string[],
  *     missals:string[]
  * }
 */
final class NationalMetadata extends AbstractJsonSrcData
{
    public readonly string $nation;

    /** @var string[] */
    public readonly array $locales;

    /** @var list<string> The wider regions, most general first; the order is the order their layers apply. */
    public readonly array $wider_regions;

    /** Whether the regions were read from the deprecated `wider_region` string (for a deprecation warning). */
    public readonly bool $usedLegacyWiderRegion;

    /** @var string[] */
    public readonly array $missals;

    /**
     * Constructs a new instance of NationalMetadata.
     *
     * @param string $nation A two-letter country ISO code (capital letters).
     * @param string[] $locales An array of valid locale codes, must not be empty.
     * @param list<string> $wider_regions Wider region names, most general first.
     * @param string[] $missals An array of valid Roman Missal identifiers.
     * @param bool $usedLegacyWiderRegion Whether they came from the deprecated string field.
     *
     * @throws \ValueError If any parameter does not meet the specified criteria.
     */
    private function __construct(string $nation, array $locales, array $wider_regions, array $missals, bool $usedLegacyWiderRegion = false)
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

        foreach ($wider_regions as $region) {
            if (false === is_string($region) || false === WiderRegionName::isValid($region)) {
                throw new \ValueError('`metadata.wider_regions` must be a list of wider region names such as `Europe` or `Middle East`');
            }
        }
        if (count(array_unique($wider_regions)) !== count($wider_regions)) {
            throw new \ValueError('`metadata.wider_regions` must not name a wider region more than once');
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

        $this->nation                = $nation;
        $this->locales               = $locales;
        $this->wider_regions         = array_values($wider_regions);
        $this->usedLegacyWiderRegion = $usedLegacyWiderRegion;
        $this->missals               = $missals;
    }

    /**
     * Resolves the two spellings of a nation's wider regions (#1005).
     *
     * `wider_regions` is the list; the deprecated `wider_region` string is read as a one-element list (`""` as none).
     * Both may be present only when they agree, which is what a client echoing a `GET /data/nation/{nation}` response
     * sends during the transition.
     *
     * @return array{0: list<string>, 1: bool} The regions, and whether the deprecated string was the source.
     * @throws \ValueError When a field has the wrong type, or the two fields disagree.
     */
    private static function readWiderRegions(mixed $list, mixed $legacy): array
    {
        if (null !== $legacy && false === is_string($legacy)) {
            throw new \ValueError('`metadata.wider_region` (deprecated) must be a string; send `metadata.wider_regions` instead');
        }
        if (null !== $list && false === is_array($list)) {
            throw new \ValueError('`metadata.wider_regions` must be a list of wider region names');
        }

        if (null === $list) {
            return [null === $legacy || '' === $legacy ? [] : [$legacy], null !== $legacy];
        }

        $list = array_values($list);
        if (null !== $legacy && $list !== ( '' === $legacy ? [] : [$legacy] )) {
            throw new \ValueError('`metadata.wider_region` (deprecated) and `metadata.wider_regions` disagree; send `metadata.wider_regions` alone');
        }

        /** @var list<string> $list validated item by item in the constructor */
        return [$list, false];
    }

    /**
     * Creates an instance of NationalMetadata from an associative array.
     *
     * The array must have the following keys:
     * - nation (string): A two-letter country ISO code (capital letters).
     * - locales (string[]): An array of valid locale codes.
     *
     * The array may have the following keys:
     * - wider_regions (string[]): A list of wider region names, most general first.
     * - wider_region (string, deprecated): A single wider region name, read as a one-element list.
     * - missals (string[]): An array of valid Roman Missal identifiers.
     *
     * @param NationalMetadataArray $data
     * @return static
     * @throws \ValueError If any parameter does not meet the specified criteria.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (array_key_exists('calendar_id', $data)) {
            throw new \RuntimeException('Perhaps you meant to use \LiturgicalCalendar\Api\Models\Metadata\MetadataNationalCalendarItem::fromArray?');
        }

        [$regions, $legacy] = self::readWiderRegions($data['wider_regions'] ?? null, $data['wider_region'] ?? null);

        return new static(
            $data['nation'],
            $data['locales'],
            $regions,
            isset($data['missals']) ? $data['missals'] : [],
            $legacy
        );
    }

    /**
     * Creates an instance of NationalMetadata from a stdClass object.
     *
     * The object must have the following properties:
     * - nation (string): A two-letter country ISO code (capital letters).
     * - locales (string[]): An array of valid locale codes.
     * The object may have the following properties:
     * - wider_regions (string[]): A list of wider region names, most general first.
     * - wider_region (string, deprecated): A single wider region name, read as a one-element list.
     * - missals (string[]): An array of valid Roman Missal identifiers.
     *
     * @param NationalMetadataObject $data The object containing the properties of NationalMetadata.
     * @return static A new instance of NationalMetadata initialized with the provided data.
     * @throws \ValueError If any property does not meet the specified criteria.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (property_exists($data, 'calendar_id')) {
            throw new \RuntimeException('Perhaps you meant to use \LiturgicalCalendar\Api\Models\Metadata\MetadataNationalCalendarItem::fromObject?');
        }

        [$regions, $legacy] = self::readWiderRegions($data->wider_regions ?? null, $data->wider_region ?? null);

        return new static(
            $data->nation,
            $data->locales,
            $regions,
            isset($data->missals) ? $data->missals : [],
            $legacy
        );
    }
}
