<?php

namespace LiturgicalCalendar\Api\Models\Lectionary;

use LiturgicalCalendar\Api\Enum\JsonData;

/**
 * @phpstan-import-type ReadingsFerialArray from ReadingsMap
 * @phpstan-import-type ReadingsFestiveArray from ReadingsMap
 * @phpstan-import-type ReadingsPalmSundayArray from ReadingsMap
 * @phpstan-import-type ReadingsMultipleSchemasArray from ReadingsMap
 * @phpstan-import-type ReadingsChristmasArray from ReadingsMap
 * @phpstan-import-type ReadingsFestiveWithVigilArray from ReadingsMap
 * @phpstan-import-type ReadingsEasterVigilArray from ReadingsMap
 * @phpstan-import-type ReadingsWithEveningArray from ReadingsMap
 */
final class ReadingsGeneralRoman
{
    private ReadingsMap $sanctorale;
    private ReadingsMap $sundaysSolemnitiesCycleA;
    private ReadingsMap $sundaysSolemnitiesCycleB;
    private ReadingsMap $sundaysSolemnitiesCycleC;
    private ReadingsMap $weekdaysCycleI;
    private ReadingsMap $weekdaysCycleII;
    private ReadingsMap $weekdaysAdvent;
    private ReadingsMap $weekdaysChristmas;
    private ReadingsMap $weekdaysEaster;
    private ReadingsMap $weekdaysLent;
    private ReadingsMap $decreeReadings;

    public function __construct(string $locale)
    {
        $filesToLoad = [
            'sanctorale'               => strtr(
                JsonData::LECTIONARY_SAINTS_FILE,
                [ '{locale}' => $locale ]
            ),
            'sundaysSolemnitiesCycleA' => strtr(
                JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_A_FILE,
                [ '{locale}' => $locale ]
            ),
            'sundaysSolemnitiesCycleB' => strtr(
                JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_B_FILE,
                [ '{locale}' => $locale ]
            ),
            'sundaysSolemnitiesCycleC' => strtr(
                JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_C_FILE,
                [ '{locale}' => $locale ]
            ),
            'weekdaysCycleI'           => strtr(
                JsonData::LECTIONARY_WEEKDAYS_ORDINARY_I_FILE,
                [ '{locale}' => $locale ]
            ),
            'weekdaysCycleII'          => strtr(
                JsonData::LECTIONARY_WEEKDAYS_ORDINARY_II_FILE,
                [ '{locale}' => $locale ]
            ),
            'weekdaysAdvent'           => strtr(
                JsonData::LECTIONARY_WEEKDAYS_ADVENT_FILE,
                [ '{locale}' => $locale ]
            ),
            'weekdaysChristmas'        => strtr(
                JsonData::LECTIONARY_WEEKDAYS_CHRISTMAS_FILE,
                [ '{locale}' => $locale ]
            ),
            'weekdaysLent'             => strtr(
                JsonData::LECTIONARY_WEEKDAYS_LENT_FILE,
                [ '{locale}' => $locale ]
            ),
            'weekdaysEaster'           => strtr(
                JsonData::LECTIONARY_WEEKDAYS_EASTER_FILE,
                [ '{locale}' => $locale ]
            ),
            'decreeReadings'           => strtr(
                JsonData::LECTIONARY_DECREES_FILE,
                [ '{locale}' => $locale ]
            )
        ];

        foreach ($filesToLoad as $key => $file) {
            $this->{$key} = ReadingsMap::fromFile($file);
        }
    }

    /**
     * Retrieves the ReadingsMap for the specified liturgical cycle.
     *
     * The available cycles are:
     * - 'A': Sundays and solemnities for Cycle A.
     * - 'B': Sundays and solemnities for Cycle B.
     * - 'C': Sundays and solemnities for Cycle C.
     * - 'I': Weekdays for Cycle I.
     * - 'II': Weekdays for Cycle II.
     *
     * @param string $cycle The liturgical cycle identifier.
     * @return ReadingsMap The readings map for the specified cycle.
     * @throws \InvalidArgumentException If the cycle identifier is invalid.
     */
    public function getCycle(string $cycle): ReadingsMap
    {
        switch ($cycle) {
            case 'A':
                return $this->sundaysSolemnitiesCycleA;
            case 'B':
                return $this->sundaysSolemnitiesCycleB;
            case 'C':
                return $this->sundaysSolemnitiesCycleC;
            case 'I':
                return $this->weekdaysCycleI;
            case 'II':
                return $this->weekdaysCycleII;
            default:
                throw new \InvalidArgumentException("Invalid cycle: $cycle");
        }
    }

    /**
     * Retrieves the readings for the specified offset during weekdays of Advent.
     *
     * @param string $offset The offset to retrieve the readings for.
     * @return ReadingsAbstract The readings associated with the specified offset.
     */
    public function getWeekdaysAdventReadings(string $offset): ReadingsAbstract
    {
        $readings = $this->weekdaysAdvent->getReadings($offset);

        if (false === $readings instanceof ReadingsAbstract) {
            throw new \UnexpectedValueException('The readings for weekdays of Advent are expected to be an instance that extends ReadingsAbstract');
        }

        return $readings;
    }

    /**
     * Retrieves the readings for the specified offset during weekdays of Christmas.
     *
     * @param string $offset The offset to retrieve the readings for.
     * @return ReadingsAbstract The readings associated with the specified offset.
     */
    public function getWeekdaysChristmasReadings(string $offset): ReadingsAbstract
    {
        $readings = $this->weekdaysChristmas->getReadings($offset);
        if (false === $readings instanceof ReadingsAbstract) {
            throw new \UnexpectedValueException('The readings for weekdays of Christmas are expected to be an instance that extends ReadingsAbstract');
        }
        return $readings;
    }

    /**
     * Retrieves the readings for the specified offset during weekdays of Lent.
     *
     * @param string $offset The offset to retrieve the readings for.
     * @return ReadingsAbstract The readings associated with the specified offset.
     */
    public function getWeekdaysLentReadings(string $offset): ReadingsAbstract
    {
        $readings = $this->weekdaysLent->getReadings($offset);
        if (false === $readings instanceof ReadingsAbstract) {
            throw new \UnexpectedValueException('The readings for weekdays of Lent are expected to be an instance that extends ReadingsAbstract');
        }
        return $readings;
    }

    /**
     * Retrieves the readings for the specified offset during weekdays of Easter.
     *
     * @param string $offset The offset to retrieve the readings for.
     * @return ReadingsAbstract The readings associated with the specified offset.
     */
    public function getWeekdaysEasterReadings(string $offset): ReadingsAbstract
    {
        $readings = $this->weekdaysEaster->getReadings($offset);
        if (false === $readings instanceof ReadingsAbstract) {
            throw new \UnexpectedValueException('The readings for weekdays of Easter are expected to be an instance that extends ReadingsAbstract');
        }
        return $readings;
    }

    /**
     * Retrieves the list of keys for the sanctorale readings.
     *
     * Returns an array of strings, where each string is a key that can be used
     * to retrieve the corresponding readings from the sanctorale using the
     * getSanctoraleReadings() method.
     *
     * @return string[] List of keys for the sanctorale readings.
     */
    public function getSanctoraleKeys(): array
    {
        return $this->sanctorale->keys();
    }

    /**
     * Retrieves the readings for the specified sanctorale offset.
     *
     * Pretty much all of the readings in the Sanctorale Lectionary are of type ReadingsAbstract and its subclasses.
     * The only exception is All Souls Day, or commemoration of the Faithful Departed, which offers three schemas
     * and is of type ReadingsMultipleSchemas.
     *
     * @param string $offset The offset to retrieve the readings for.
     * @return ReadingsAbstract|ReadingsSeasonal|ReadingsMultipleSchemas The readings for the specified sanctorale offset.
     */
    public function getSanctoraleReadings(string $offset): ReadingsAbstract|ReadingsSeasonal|ReadingsMultipleSchemas
    {
        $readings = $this->sanctorale->getReadings($offset);

        if (
            false === $readings instanceof ReadingsAbstract
            && false === $readings instanceof ReadingsSeasonal
            && false === $readings instanceof ReadingsMultipleSchemas
        ) {
            throw new \UnexpectedValueException('The readings for the sanctorale are expected to be an instance of ReadingsSeasonal, of ReadingsMultipleSchemas, or an instance that extends ReadingsAbstract');
        }

        return $readings;
    }

    /**
     * Checks if the sanctorale readings contain the specified offset.
     *
     * @param string $offset The offset to check for.
     * @return bool True if the sanctorale readings contain the specified offset, false otherwise.
     */
    public function hasSanctoraleReadings(string $offset): bool
    {
        return $this->sanctorale->offsetExists($offset);
    }

    /**
     * @param array<string,ReadingsFerialArray|ReadingsFestiveArray|ReadingsFestiveWithVigilArray|ReadingsSeasonalArray|ReadingsMultipleSchemasArray> $readings
     */
    public function addSanctoraleReadings(array $readings): void
    {
        $this->sanctorale->addFromArray($readings);
    }

    /**
     * Adds the readings from the specified file to the sanctorale.
     *
     * @param string $file The file to read the readings from.
     */
    public function addSanctoraleReadingsFromFile(string $file): void
    {
        $this->sanctorale->addFromFile($file);
    }

    /**
     * Retrieves the readings for a decreed event.
     *
     * @param string $offset The offset to retrieve the readings for.
     * @return ReadingsAbstract The readings for the specified decreed event.
     */
    public function getReadingsForDecreedEvent(string $offset): ReadingsAbstract
    {
        $readings = $this->decreeReadings->getReadings($offset);

        if (false === $readings instanceof ReadingsAbstract) {
            throw new \UnexpectedValueException('The readings for decreed events are expected to be an instance that extends ReadingsAbstract');
        }

        return $readings;
    }
}
