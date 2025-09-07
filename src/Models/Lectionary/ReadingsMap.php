<?php

namespace LiturgicalCalendar\Api\Models\Lectionary;

use LiturgicalCalendar\Api\Utilities;

/**
 * @phpstan-type ReadingsFerialArray array{
 *     first_reading:string,
 *     responsorial_psalm:string,
 *     gospel_acclamation:string,
 *     gospel:string
 * }
 *
 * @phpstan-type ReadingsFestiveArray array{
 *     first_reading:string,
 *     responsorial_psalm:string,
 *     second_reading:string,
 *     gospel_acclamation:string,
 *     gospel:string
 * }
 *
 * @phpstan-type ReadingsPalmSundayArray array{
 *     palm_gospel:string,
 *     first_reading:string,
 *     second_reading:string,
 *     responsorial_psalm:string,
 *     gospel_acclamation:string,
 *     gospel:string
 * }
 *
 * @phpstan-type ReadingsEasterVigilArray array{
 *     first_reading:string,
 *     responsorial_psalm:string,
 *     second_reading:string,
 *     responsorial_psalm_2:string,
 *     third_reading:string,
 *     responsorial_psalm_3:string,
 *     fourth_reading:string,
 *     responsorial_psalm_4:string,
 *     fifth_reading:string,
 *     responsorial_psalm_5:string,
 *     sixth_reading:string,
 *     responsorial_psalm_6:string,
 *     seventh_reading:string,
 *     responsorial_psalm_7:string,
 *     epistle:string,
 *     responsorial_psalm_epistle:string,
 *     gospel_acclamation:string,
 *     gospel:string
 * }
 *
 * @phpstan-type ReadingsFestiveWithVigilArray array{
 *     vigil:ReadingsFestiveArray,
 *     day:ReadingsFestiveArray
 * }
 *
 * @phpstan-type ReadingsChristmasArray array{
 *     vigil:ReadingsFestiveArray,
 *     night:ReadingsFestiveArray,
 *     dawn:ReadingsFestiveArray,
 *     day:ReadingsFestiveArray
 * }
 *
 * @phpstan-type ReadingsMultipleSchemasArray array{
 *     schema_one:ReadingsFestiveArray,
 *     schema_two:ReadingsFestiveArray,
 *     schema_three:ReadingsFestiveArray
 * }
 * Example: AllSouls
 *
 * @phpstan-type ReadingsWithEveningArray array{
 *     day:ReadingsFestiveArray,
 *     evening:ReadingsFestiveArray
 * }
 * Example: Easter Sunday
 *
 * @phpstan-type ReadingsSeasonalArray array{
 *     easter_season:ReadingsFerialArray,
 *     outside_easter_season:ReadingsFerialArray
 * }
 * Example: certain sanctorale Masses
 *
 * @implements \ArrayAccess<string,ReadingsMultipleSchemas|ReadingsChristmas|ReadingsFestiveWithVigil|ReadingsEasterVigil|ReadingsPalmSunday|ReadingsFestive|ReadingsFerial|ReadingsWithEvening|ReadingsSeasonal>
 */
final class ReadingsMap implements \ArrayAccess
{
    /** @var array<string,ReadingsMultipleSchemas|ReadingsChristmas|ReadingsFestiveWithVigil|ReadingsEasterVigil|ReadingsPalmSunday|ReadingsFestive|ReadingsFerial|ReadingsWithEvening|ReadingsSeasonal> */
    private array $readings;

    /**
     * @var array{0:'first_reading',1:'responsorial_psalm',2:'second_reading',3:'responsorial_psalm_2',4:'third_reading',5:'responsorial_psalm_3',6:'fourth_reading',7:'responsorial_psalm_4',8:'fifth_reading',9:'responsorial_psalm_5',10:'sixth_reading',11:'responsorial_psalm_6',12:'seventh_reading',13:'responsorial_psalm_7',14:'epistle',15:'responsorial_psalm_epistle',16:'gospel_acclamation',17:'gospel'}
     */
    public const EASTER_VIGIL_KEYS = [
        'first_reading',
        'responsorial_psalm',
        'second_reading',
        'responsorial_psalm_2',
        'third_reading',
        'responsorial_psalm_3',
        'fourth_reading',
        'responsorial_psalm_4',
        'fifth_reading',
        'responsorial_psalm_5',
        'sixth_reading',
        'responsorial_psalm_6',
        'seventh_reading',
        'responsorial_psalm_7',
        'epistle',
        'responsorial_psalm_epistle',
        'gospel_acclamation',
        'gospel'
    ];

    /**
     * @var array{0:'first_reading',1:'responsorial_psalm',2:'gospel_acclamation',3:'gospel'}
     */
    public const FERIAL_KEYS = [
        'first_reading',
        'responsorial_psalm',
        'gospel_acclamation',
        'gospel'
    ];

    /**
     * @var array{0:'first_reading',1:'responsorial_psalm',2:'gospel_acclamation',3:'gospel',4:'second_reading'}
     */
    public const FESTIVE_KEYS = [
        ...self::FERIAL_KEYS,
        'second_reading'
    ];

    /**
     * @var array{0:'first_reading',1:'responsorial_psalm',2:'gospel_acclamation',3:'gospel',4:'second_reading',5:'palm_gospel'}
     */
    public const PALM_SUNDAY_KEYS = [
        ...self::FESTIVE_KEYS,
        'palm_gospel'
    ];

    /**
     * @var array{0:'vigil',1:'day'}
     */
    public const READINGS_WITH_VIGIL_KEYS = [
        'vigil',
        'day'
    ];

    /**
     * @var array{0:'vigil',1:'night',2:'dawn',3:'day'}
     */
    public const READINGS_CHRISTMAS_KEYS = [
        'vigil',
        'night',
        'dawn',
        'day'
    ];

    /**
     * @var array{0:'schema_one',1:'schema_two',2:'schema_three'}
     */
    public const READINGS_MULTIPLE_SCHEMAS_KEYS = [
        'schema_one',
        'schema_two',
        'schema_three'
    ];

    /**
     * @var array{0:'day',1:'evening'}
     */
    public const READINGS_WITH_EVENING_MASS_KEYS = [
        'day',
        'evening'
    ];

    /**
     * @var array{0:'easter_season',1:'outside_easter_season'}
     */
    public const READINGS_SEASONAL_KEYS = [
        'easter_season',
        'outside_easter_season'
    ];

    /**
     * ReadingsMap constructor.
     *
     * Initializes an empty $readings array property.
     */
    public function __construct()
    {
        $this->readings = [];
    }

    /**
     * @param array<string,ReadingsFerialArray|ReadingsFestiveArray|ReadingsPalmSundayArray|ReadingsEasterVigilArray|ReadingsFestiveWithVigilArray|ReadingsChristmasArray|ReadingsMultipleSchemasArray|ReadingsWithEveningArray|ReadingsSeasonalArray> $readings
     */
    public function addFromArray(array $readings): void
    {
        /**
         * @var array<string,ReadingsFerial|ReadingsFestive|ReadingsPalmSunday|ReadingsEasterVigil|ReadingsFestiveWithVigil|ReadingsChristmas|ReadingsMultipleSchemas|ReadingsWithEvening|ReadingsSeasonal> $readingsMapped
         */
        $readingsMapped = array_map(function ($item) {
            /** @var string[] $itemKeys */
            $itemKeys     = array_keys($item);
            $itemKeyCount = count($itemKeys);

            // First we test for the two dimensional readings instances,
            // starting from the most complex to the most simple.
            // This is important to calculate correctly the diff between the keys!
            // e.g. READINGS_CHRISTMAS_KEYS has more values than READINGS_WITH_VIGIL_KEYS

            if ($itemKeyCount === count(self::READINGS_MULTIPLE_SCHEMAS_KEYS) && array_diff(self::READINGS_MULTIPLE_SCHEMAS_KEYS, $itemKeys) === []) {
                /**
                 * @var ReadingsMultipleSchemasArray $item
                 */
                return ReadingsMultipleSchemas::fromArray($item);
            }

            if ($itemKeyCount === count(self::READINGS_CHRISTMAS_KEYS) && array_diff(self::READINGS_CHRISTMAS_KEYS, $itemKeys) === []) {
                /**
                 * @var ReadingsChristmasArray $item
                 */
                return ReadingsChristmas::fromArray($item);
            }

            if ($itemKeyCount === count(self::READINGS_WITH_VIGIL_KEYS) && array_diff(self::READINGS_WITH_VIGIL_KEYS, $itemKeys) === []) {
                /**
                 * @var ReadingsFestiveWithVigilArray $item
                 */
                return ReadingsFestiveWithVigil::fromArray($item);
            }

            if ($itemKeyCount === count(self::READINGS_WITH_EVENING_MASS_KEYS) && array_diff(self::READINGS_WITH_EVENING_MASS_KEYS, $itemKeys) === []) {
                /**
                 * @var ReadingsWithEveningArray $item
                 */
                return ReadingsWithEvening::fromArray($item);
            }

            if ($itemKeyCount === count(self::READINGS_SEASONAL_KEYS) && array_diff(self::READINGS_SEASONAL_KEYS, $itemKeys) === []) {
                /**
                 * @var ReadingsSeasonalArray $item
                 */
                return ReadingsSeasonal::fromArray($item);
            }

            // Then we test for the one dimensional readings instances,
            // starting from the most complex to the most simple.
            // This is important to calculate correctly the diff between the keys!
            // e.g. EASTER_VIGIL_KEYS has more values than PALM_SUNDAY_KEYS, and so on.

            if ($itemKeyCount === count(self::EASTER_VIGIL_KEYS) && array_diff(self::EASTER_VIGIL_KEYS, $itemKeys) === []) {
                /**
                 * @var ReadingsEasterVigilArray $item
                 */
                return ReadingsEasterVigil::fromArray($item);
            }
            if ($itemKeyCount === count(self::PALM_SUNDAY_KEYS) && array_diff(self::PALM_SUNDAY_KEYS, $itemKeys) === []) {
                /**
                 * @var ReadingsPalmSundayArray $item
                 */
                return ReadingsPalmSunday::fromArray($item);
            }
            if ($itemKeyCount === count(self::FESTIVE_KEYS) && array_diff(self::FESTIVE_KEYS, $itemKeys) === []) {
                /**
                 * @var ReadingsFestiveArray $item
                 */
                return ReadingsFestive::fromArray($item);
            }
            if ($itemKeyCount === count(self::FERIAL_KEYS) && array_diff(self::FERIAL_KEYS, $itemKeys) === []) {
                /**
                 * @var ReadingsFerialArray $item
                 */
                return ReadingsFerial::fromArray($item);
            }
            throw new \InvalidArgumentException("Invalid readings array, unknown schema:\n" . json_encode($item, JSON_PRETTY_PRINT));
        }, $readings);
        $this->readings = array_merge($this->readings, $readingsMapped);
    }

    /**
     * Adds the readings from the specified file to the collection.
     *
     * @param string $path The path to the file to read the readings from.
     *
     * @throws \InvalidArgumentException If the file does not exist, is not readable, or the JSON could not be decoded.
     */
    public function addFromFile(string $path): void
    {
        $rawContents = Utilities::rawContentsFromFile($path);

        /**
         * @var array<string,ReadingsFerialArray|ReadingsFestiveArray|ReadingsPalmSundayArray|ReadingsEasterVigilArray|ReadingsFestiveWithVigilArray|ReadingsChristmasArray|ReadingsMultipleSchemasArray|ReadingsWithEveningArray|ReadingsSeasonalArray> $readings
         */
        $readings = json_decode($rawContents, true, 512, JSON_THROW_ON_ERROR);

        $this->addFromArray($readings);
    }

    /**
     * Retrieves the readings at the specified offset from the collection.
     *
     * @param string $offset The offset to retrieve the readings from.
     * @return ReadingsMultipleSchemas|ReadingsChristmas|ReadingsFestiveWithVigil|ReadingsEasterVigil|ReadingsPalmSunday|ReadingsFestive|ReadingsFerial|ReadingsWithEvening|ReadingsSeasonal The readings associated with the given offset.
     */
    public function offsetGet($offset): ReadingsMultipleSchemas|ReadingsChristmas|ReadingsFestiveWithVigil|ReadingsEasterVigil|ReadingsPalmSunday|ReadingsFestive|ReadingsFerial|ReadingsWithEvening|ReadingsSeasonal
    {
        return $this->readings[$offset];
    }

    /**
     * Adds the given readings at the specified offset to the collection.
     *
     * @param string $offset The offset to add the readings to.
     * @param ReadingsMultipleSchemas|ReadingsChristmas|ReadingsFestiveWithVigil|ReadingsEasterVigil|ReadingsPalmSunday|ReadingsFestive|ReadingsFerial|ReadingsWithEvening|ReadingsSeasonal $value The readings to add.
     */
    public function offsetSet($offset, $value): void
    {
        $this->readings[$offset] = $value;
    }

    /**
     * Removes the readings at the specified offset from the collection.
     *
     * @param string $offset The offset to remove the readings from.
     */
    public function offsetUnset($offset): void
    {
        unset($this->readings[$offset]);
    }

    /**
     * Checks if the given offset exists in the collection.
     *
     * @param string $offset The offset to check.
     * @return bool True if the offset exists, false otherwise.
     */
    public function offsetExists($offset): bool
    {
        return isset($this->readings[$offset]);
    }

    /**
     * Retrieves the vigil readings for the specified offset.
     *
     * If the readings object at the offset is an instance of ReadingsFestiveWithVigil
     * or ReadingsChristmas, it returns the vigil readings. If it is an instance of
     * ReadingsFestive, it returns the readings object itself. If the offset does not
     * correspond to any of these types, it returns null.
     *
     * @param string $offset The offset to retrieve the vigil readings from.
     * @return ReadingsFestive|null The vigil readings for the specified offset, or null if not applicable.
     */
    public function getVigilReadings(string $offset): ?ReadingsFestive
    {
        $readingsObject = $this->readings[$offset];

        if ($readingsObject instanceof ReadingsFestiveWithVigil || $readingsObject instanceof ReadingsChristmas) {
            return $readingsObject->vigil;
        }

        if ($readingsObject instanceof ReadingsFestive) {
            return $readingsObject;
        }
        return null;
    }

    /**
     * Retrieves the readings for the specified offset.
     *
     * If the offset points to a ReadingsFestiveWithVigil, it returns the day readings.
     * If the offset points to any other type of readings, it returns the readings object as is.
     * If the offset does not exist, it returns null.
     *
     * @param string $offset The offset to retrieve the readings from.
     * @return ReadingsMultipleSchemas|ReadingsChristmas|ReadingsEasterVigil|ReadingsPalmSunday|ReadingsFestive|ReadingsFerial|ReadingsWithEvening|ReadingsSeasonal The readings for the specified offset.
     */
    public function getReadings(string $offset): ReadingsMultipleSchemas|ReadingsChristmas|ReadingsEasterVigil|ReadingsPalmSunday|ReadingsFestive|ReadingsFerial|ReadingsWithEvening|ReadingsSeasonal
    {
        $readingsObject = $this->readings[$offset] ?? null;

        if (null === $readingsObject) {
            throw new \InvalidArgumentException("No readings found for offset: $offset, available offsets: " . implode(', ', array_keys($this->readings)));
        }

        if ($readingsObject instanceof ReadingsFestiveWithVigil) {
            return $readingsObject->day;
        }

        return $readingsObject;
    }

    /**
     * Instantiates a ReadingsMap from a JSON file.
     *
     * @param string $file The path to the JSON file containing the readings.
     * @return ReadingsMap The readings loaded from the file.
     * @throws \InvalidArgumentException If the file does not exist, is not readable, or contains invalid JSON.
     */
    public static function fromFile(string $file): ReadingsMap
    {
        $dataRaw = Utilities::rawContentsFromFile($file);

        /**
         * @var array<string,ReadingsFerialArray|ReadingsFestiveArray|ReadingsPalmSundayArray|ReadingsEasterVigilArray|ReadingsFestiveWithVigilArray|ReadingsChristmasArray|ReadingsMultipleSchemasArray|ReadingsWithEveningArray|ReadingsSeasonalArray> $dataJson
         */
        $dataJson = json_decode($dataRaw, true, 512, JSON_THROW_ON_ERROR);

        $readingsMap = new self();
        $readingsMap->addFromArray($dataJson);
        return $readingsMap;
    }

    /**
     * Returns an array of keys from the readings array.
     *
     * @return string[] The keys of the readings.
     */
    public function keys(): array
    {
        return array_keys($this->readings);
    }
}
