<?php

namespace LiturgicalCalendar\Api\Test;

use LiturgicalCalendar\Api\Test\AssertionCollection;

/**
 * @phpstan-type AppliesToOrExcludes object{
 *      national_calendars?: string[],
 *      diocesan_calendars?: string[],
 *      national_calendar?: string,
 *      diocesan_calendar?: string
 * }
 */
class TestItem
{
    public string $name;
    public string $event_key;
    public string $description;
    public string $test_type;
    public ?int $year_since = null;
    public ?int $year_until = null;

    /** @phpstan-var AppliesToOrExcludes|null */ public ?object $applies_to = null;
    /** @phpstan-var AppliesToOrExcludes|null */ public ?object $excludes   = null;
    public AssertionCollection $assertions;

    private const REQUIRED_PROPERTIES = [
        'name',
        'event_key',
        'description',
        'test_type',
        'assertions'
    ];

    private const STRING_PROPERTIES = [
        'name',
        'event_key',
        'description',
        'test_type'
    ];

    private const APPLIES_TO_OR_EXCLUDES_PROPERTIES = [
        'national_calendars',
        'diocesan_calendars',
        'national_calendar',
        'diocesan_calendar'
    ];

    public function __construct(\stdClass $testObject)
    {
        foreach (self::REQUIRED_PROPERTIES as $property) {
            if (!property_exists($testObject, $property)) {
                throw new \InvalidArgumentException(__METHOD__ . ": Missing required property: $property");
            }
        }

        foreach (self::STRING_PROPERTIES as $property) {
            if (!is_string($testObject->{$property})) {
                throw new \InvalidArgumentException(__METHOD__ . ": Property `$property` must be a string");
            }
        }

        $this->name        = $testObject->name;
        $this->event_key   = $testObject->event_key;
        $this->description = $testObject->description;
        $this->test_type   = $testObject->test_type;
        $this->assertions  = new AssertionCollection($testObject->assertions);

        if (property_exists($testObject, 'year_since')) {
            if (false === is_int($testObject->year_since)) {
                throw new \InvalidArgumentException(__METHOD__ . ': Property `year_since` must be an integer');
            }
            $this->year_since = $testObject->year_since;
        }

        if (property_exists($testObject, 'year_until')) {
            if (false === is_int($testObject->year_until)) {
                throw new \InvalidArgumentException(__METHOD__ . ': Property `year_until` must be an integer');
            }
            $this->year_until = $testObject->year_until;
        }

        if (property_exists($testObject, 'applies_to')) {
            if (false === is_object($testObject->applies_to)) {
                throw new \InvalidArgumentException(__METHOD__ . ': Property `applies_to` must be an object');
            }
            self::checkAppliesToExcludesConditions($testObject->applies_to);
            $this->applies_to = $testObject->applies_to;
        }

        if (property_exists($testObject, 'excludes')) {
            if (false === is_object($testObject->excludes)) {
                throw new \InvalidArgumentException(__METHOD__ . ': Property `excludes` must be an object');
            }
            self::checkAppliesToExcludesConditions($testObject->excludes);
            $this->excludes = $testObject->excludes;
        }
    }

    /**
     * @param object $appliedsToOrExcludes
     *
     * @throws \InvalidArgumentException
     */
    private static function checkAppliesToExcludesConditions(object $appliedsToOrExcludes): void
    {
        $appliesToOrExcludesArr = (array) $appliedsToOrExcludes;

        if (false === count(array_intersect_key($appliesToOrExcludesArr, array_flip(self::APPLIES_TO_OR_EXCLUDES_PROPERTIES))) > 0) {
            throw new \InvalidArgumentException(__METHOD__ . ': Property `applies_to` must have at least one of the properties: ' . implode(', ', self::APPLIES_TO_OR_EXCLUDES_PROPERTIES));
        }

        if (array_key_exists('national_calendar', $appliesToOrExcludesArr)) {
            if (false === is_string($appliesToOrExcludesArr['national_calendar'])) {
                throw new \InvalidArgumentException(__METHOD__ . ': Property `national_calendar` must have a string value');
            }
        }

        if (array_key_exists('diocesan_calendar', $appliesToOrExcludesArr)) {
            if (false === is_string($appliesToOrExcludesArr['diocesan_calendar'])) {
                throw new \InvalidArgumentException(__METHOD__ . ': Property `diocesan_calendar` must have a string value');
            }
        }

        if (array_key_exists('national_calendars', $appliesToOrExcludesArr)) {
            if (false === is_array($appliesToOrExcludesArr['national_calendars'])) {
                throw new \InvalidArgumentException(__METHOD__ . ': Property `national_calendars` must have an array value');
            }

            foreach ($appliesToOrExcludesArr['national_calendars'] as $calendar) {
                if (false === is_string($calendar)) {
                    throw new \InvalidArgumentException(__METHOD__ . ': Property `national_calendars` must have an array of strings value');
                }
            }
        }

        if (array_key_exists('diocesan_calendars', $appliesToOrExcludesArr)) {
            if (false === is_array($appliesToOrExcludesArr['diocesan_calendars'])) {
                throw new \InvalidArgumentException(__METHOD__ . ': Property `diocesan_calendars` must have an array value');
            }

            foreach ($appliesToOrExcludesArr['diocesan_calendars'] as $calendar) {
                if (false === is_string($calendar)) {
                    throw new \InvalidArgumentException(__METHOD__ . ': Property `diocesan_calendars` must have an array of strings value');
                }
            }
        }
    }
}
