<?php

namespace LiturgicalCalendar\Api;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\LitSeason;
use LiturgicalCalendar\Api\Params\CalendarParams;

/**
 * @phpstan-type EventCollectionItem array{
 *      event_key: string,
 *      name: string,
 *      date: int,
 *      color: string[],
 *      color_lcl: string[],
 *      type: string,
 *      grade: int,
 *      grade_lcl: string,
 *      grade_abbr: string,
 *      grade_display?: string|null,
 *      common: string[],
 *      common_lcl: string,
 *      day_of_the_week_iso8601: int,
 *      month: int,
 *      day: int,
 *      year: int,
 *      month_short: string,
 *      month_long: string,
 *      day_of_the_week_short: string,
 *      day_of_the_week_long: string,
 *      liturgical_year?: ?string,
 *      liturgical_season: string,
 *      liturgical_season_lcl: string,
 *      has_vigil_mass?: bool,
 *      has_vesper_i?: bool,
 *      has_vesper_ii?: bool,
 *      is_vigil_mass?: bool,
 *      is_vigil_for?: string,
 *      psalter_week: int
 * }
 * @phpstan-type EventCollection array<EventCollectionItem>
 * @phpstan-type SecondaryCollectionItem array{
 *      event_key: string,
 *      date: string,
 *      timezone_type: int,
 *      timezone: string
 * }
 * @phpstan-type SecondaryCollection array<SecondaryCollectionItem>
 * @phpstan-type LiturgicalEventsMap array<string, LiturgicalEvent>
 */

class LiturgicalEventCollection
{
    /** @var EventCollection       */ private array $liturgicalEventsCollection            = [];
    /** @var SecondaryCollection   */ private array $solemnitiesCollection                 = [];
    /** @var SecondaryCollection   */ private array $feastsCollection                      = [];
    /** @var SecondaryCollection   */ private array $memorialsCollection                   = [];
    /** @var SecondaryCollection   */ private array $weekdaysAdventChristmasLentCollection = [];
    /** @var SecondaryCollection   */ private array $weekdaysAdventBeforeDec17Collection   = [];
    /** @var SecondaryCollection   */ private array $weekdaysEpiphanyCollection            = [];
    /** @var SecondaryCollection   */ private array $solemnitiesLordBVMCollection          = [];
    /** @var SecondaryCollection   */ private array $sundaysAdventLentEasterCollection     = [];
    /** @var LiturgicalEventsMap   */ private array $liturgicalEvents                      = [];
    /** @var LiturgicalEventsMap   */ private array $solemnities                           = [];
    /** @var LiturgicalEventsMap   */ private array $feasts                                = [];
    /** @var LiturgicalEventsMap   */ private array $memorials                             = [];
    /** @var LiturgicalEventsMap   */ private array $weekdaysAdventChristmasLent           = [];
    /** @var LiturgicalEventsMap   */ private array $weekdaysAdventBeforeDec17             = [];
    /** @var LiturgicalEventsMap   */ private array $weekdaysEpiphany                      = [];
    /** @var LiturgicalEventsMap   */ private array $solemnitiesLordBVM                    = [];
    /** @var LiturgicalEventsMap   */ private array $sundaysAdventLentEaster               = [];
    /** @var LiturgicalEventsMap   */ private array $suppressedEvents                      = [];
    /** @var LiturgicalEventsMap   */ private array $reinstatedEvents                      = [];
    /** @var array<string, string> */ private array $T                                     = [];
    /** @var array<string>         */ private array $Messages                              = [];
    private \IntlDateFormatter $dayOfTheWeek;
    private CalendarParams $CalendarParams;
    private LitGrade $LitGrade;

    public const SUNDAY_CYCLE  = [ "A", "B", "C" ];
    public const WEEKDAY_CYCLE = [ "I", "II" ];

    public function __construct(CalendarParams $CalendarParams)
    {
        $this->CalendarParams = $CalendarParams;
        $this->dayOfTheWeek   = \IntlDateFormatter::create(
            $this->CalendarParams->Locale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            "EEEE"
        );
        if ($this->CalendarParams->Locale === LitLocale::LATIN) {
            $this->T = [
                "YEAR"       => "ANNUM",
                "Vigil Mass" => "Missa in Vigilia"
            ];
        } else {
            $this->T = [
                /**translators: in reference to the cycle of liturgical years (A, B, C; I, II) */
                "YEAR"       => _("YEAR"),
                "Vigil Mass" => _("Vigil Mass")
            ];
        }
        $this->LitGrade = new LitGrade($this->CalendarParams->Locale);
    }

    /**
     * Returns true if the given DateTime object represents a Sunday.
     *
     * @param DateTime $dt
     * @return bool True if the given date is a Sunday, false otherwise.
     */
    public static function dateIsSunday(DateTime $dt): bool
    {
        return (int)$dt->format('N') === 7;
    }

    /**
     * Returns true if the given DateTime object does not represent a Sunday.
     *
     * @param DateTime $dt The date to check.
     * @return bool True if the given date is not a Sunday, false otherwise.
     */
    public static function dateIsNotSunday(DateTime $dt): bool
    {
        return (int)$dt->format('N') !== 7;
    }

    /**
     * Adds a LiturgicalEvent to the collection and categorizes it based on its grade and key.
     *
     * This method organizes liturgical events into different categories such as solemnities,
     * feasts, memorials, weekdays of Advent, Christmas, Lent, and Epiphany, as well as
     * Sundays of Advent, Lent, and Easter. It updates the liturgical event's display grade and
     * psalter week as necessary.
     *
     * @param string $key The key associated with the liturgical event.
     * @param LiturgicalEvent $litEvent The LiturgicalEvent object to be added.
     * @return void
     */
    public function addLiturgicalEvent(string $key, LiturgicalEvent $litEvent): void
    {
        $this->liturgicalEvents[ $key ] = $litEvent;
        if ($litEvent->grade === LitGrade::HIGHER_SOLEMNITY) {
            $this->liturgicalEvents[ $key ]->grade_display = "";
        }

        // phpcs:disable Generic.Formatting.MultipleStatementAlignment
        if ($litEvent->grade >= LitGrade::FEAST_LORD) {
            $this->solemnities[ $key ] = $litEvent->date;
        }
        if ($litEvent->grade === LitGrade::FEAST) {
            $this->feasts[ $key ]      = $litEvent->date;
        }
        if ($litEvent->grade === LitGrade::MEMORIAL) {
            $this->memorials[ $key ]   = $litEvent->date;
        }

        // Weekday of Advent from 17 to 24 Dec.
        if (str_starts_with($key, "AdventWeekday")) {
            if ($litEvent->date->format('j') >= 17 && $litEvent->date->format('j') <= 24) {
                $this->weekdaysAdventChristmasLent[ $key ] = $litEvent->date;
            } else {
                $this->weekdaysAdventBeforeDec17[ $key ]   = $litEvent->date;
            }
        } elseif (str_starts_with($key, "ChristmasWeekday")) {
            $this->weekdaysAdventChristmasLent[ $key ]     = $litEvent->date;
        } elseif (str_starts_with($key, "LentWeekday")) {
            $this->weekdaysAdventChristmasLent[ $key ]     = $litEvent->date;
        } elseif (str_starts_with($key, "DayBeforeEpiphany") || str_starts_with($key, "DayAfterEpiphany")) {
            $this->weekdaysEpiphany[ $key ]                = $litEvent->date;
        }
        //Sundays of Advent, Lent, Easter
        if (preg_match('/(?:Advent|Lent|Easter)([1-7])/', $key, $matches) === 1) {
            $this->sundaysAdventLentEaster[]               = $litEvent->date;
            $this->liturgicalEvents[ $key ]->psalter_week  = self::psalterWeek(intval($matches[1]));
        }
        //Ordinary Sunday Psalter Week
        if (preg_match('/OrdSunday([1-9][0-9]*)/', $key, $matches) === 1) {
            $this->liturgicalEvents[ $key ]->psalter_week  = self::psalterWeek(intval($matches[1]));
        }
        // phpcs:enable Generic.Formatting.MultipleStatementAlignment
    }

    /**
     * Adds an array of keys to the SolemnitiesLordBVM array.
     * This is used to store the solemnities of the Lord and the BVM.
     * @param string[] $keys The keys to add to the array.
     * @return void
     */
    public function addSolemnitiesLordBVM(array $keys): void
    {
        array_push($this->solemnitiesLordBVM, $keys);
    }

    /**
     * Gets a LiturgicalEvent object from the collection by key.
     *
     * @param string $key The key of the liturgical event to retrieve.
     * @return LiturgicalEvent|null The LiturgicalEvent object if found, otherwise null.
     */
    public function getLiturgicalEvent(string $key): ?LiturgicalEvent
    {
        if (array_key_exists($key, $this->liturgicalEvents)) {
            return $this->liturgicalEvents[ $key ];
        }
        return null;
    }

    /**
     * Retrieves all liturgical events that occur on the specified date.
     *
     * This method filters the collection of liturgical events and returns an array
     * of those whose date matches the given DateTime object.
     *
     * @param DateTime $date The date for which to retrieve liturgical events.
     * @return array<string, LiturgicalEvent> An array of LiturgicalEvent objects occurring on the specified date.
     */
    public function getCalEventsFromDate(DateTime $date): array
    {
        // important: DateTime objects cannot use strict comparison!
        return array_filter($this->liturgicalEvents, fn ($el) => $el->date == $date);
    }

    /**
     * Checks if a given key is a solemnity of the Lord or the BVM.
     *
     * @param string $key The key to check.
     * @return bool True if the key is a solemnity of the Lord or the BVM, otherwise false.
     */
    public function isSolemnityLordBVM(string $key): bool
    {
        return in_array($key, $this->solemnitiesLordBVM);
    }

    /**
     * Checks if a given date falls on a Sunday during the seasons of Advent, Lent, or Easter.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is a Sunday in Advent, Lent, or Easter, otherwise false.
     */
    public function isSundayAdventLentEaster(DateTime $date): bool
    {
        return in_array($date, $this->sundaysAdventLentEaster);
    }

    /**
     * Checks if a given date is a solemnity.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is a solemnity, otherwise false.
     */
    public function inSolemnities(DateTime $date): bool
    {
        return in_array($date, $this->solemnities);
    }

    /**
     * Checks if a given date is not a solemnity.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is not a solemnity, otherwise false.
     */
    public function notInSolemnities(DateTime $date): bool
    {
        return !$this->inSolemnities($date);
    }

    /**
     * Checks if a given date is a feast.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is a feast, otherwise false.
     */
    public function inFeasts(DateTime $date): bool
    {
        return in_array($date, $this->feasts);
    }

    public function notInFeasts(DateTime $date): bool
    {
        return !$this->inFeasts($date);
    }

    /**
     * Checks if a given date is either a solemnity or a feast.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is a solemnity or a feast, otherwise false.
     */
    public function inSolemnitiesOrFeasts(DateTime $date): bool
    {
        return $this->inSolemnities($date) || $this->inFeasts($date);
    }

    /**
     * Checks if a given date is neither a solemnity nor a feast.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is not a solemnity or a feast, otherwise false.
     */
    public function notInSolemnitiesOrFeasts(DateTime $date): bool
    {
        return !$this->inSolemnitiesOrFeasts($date);
    }

    /**
     * Checks if a given date is a memorial.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is a memorial, otherwise false.
     */
    public function inMemorials(DateTime $date): bool
    {
        return in_array($date, $this->memorials);
    }

    /**
     * Checks if a given date is not a memorial.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is not a memorial, otherwise false.
     */
    public function notInMemorials(DateTime $date): bool
    {
        return !$this->inMemorials($date);
    }

    /**
     * Checks if a given date is either a feast or a memorial.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is a feast or a memorial, otherwise false.
     */
    public function inFeastsOrMemorials(DateTime $date): bool
    {
        return $this->inFeasts($date) || $this->inMemorials($date);
    }

    /**
     * Checks if a given date is neither a feast nor a memorial.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is not a feast or a memorial, otherwise false.
     */
    public function notInFeastsOrMemorials(DateTime $date): bool
    {
        return !$this->inFeastsOrMemorials($date);
    }

    /**
     * Checks if a given date is a solemnity, feast, or memorial.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is a solemnity, feast, or memorial, otherwise false.
     */
    public function inSolemnitiesFeastsOrMemorials(DateTime $date): bool
    {
        return $this->inSolemnities($date) || $this->inFeastsOrMemorials($date);
    }

    /**
     * Checks if a given date is not a solemnity, feast, or memorial.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is not a solemnity, feast, or memorial, otherwise false.
     */
    public function notInSolemnitiesFeastsOrMemorials(DateTime $date): bool
    {
        return !$this->inSolemnitiesFeastsOrMemorials($date);
    }

    /**
     * Checks if a given date is a weekday in the season of Advent before December 17th.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is a weekday in Advent before December 17th, otherwise false.
     */
    public function inWeekdaysAdventBeforeDec17(DateTime $date): bool
    {
        return in_array($date, $this->weekdaysAdventBeforeDec17);
    }

    /**
     * Checks if a given date is a weekday in the seasons of Advent (on or after December 17th), Christmas, or Lent.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is a weekday in Advent (on or after December 17th), Christmas, or Lent, otherwise false.
     */
    public function inWeekdaysAdventChristmasLent(DateTime $date): bool
    {
        return in_array($date, $this->weekdaysAdventChristmasLent);
    }

    /**
     * Checks if a given date is a weekday in the season of Epiphany.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is a weekday in Epiphany, otherwise false.
     */
    public function inWeekdaysEpiphany(DateTime $date): bool
    {
        return in_array($date, $this->weekdaysEpiphany);
    }

    /**
     * Checks if a given date exists in the current calculated Liturgical calendar.
     * This is useful for checking coincidences between mobile liturgical events.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is found in the calendar, otherwise false.
     */
    public function inCalendar(DateTime $date): bool
    {
        // important: DateTime objects cannot use strict comparison!
        return array_find($this->liturgicalEvents, fn ($el) => $el->date == $date) !== false;
    }

    /**
     * Given a date, find the corresponding solemnity in the current calculated Liturgical calendar.
     * If no solemnity is found, returns null.
     *
     * @param DateTime $date The date to find the solemnity for.
     * @return LiturgicalEvent|null The solemnity at the given date, or null if none exists.
     */
    public function solemnityFromDate(DateTime $date): ?LiturgicalEvent
    {
        $key = array_search($date, $this->solemnities);
        if ($key && array_key_exists($key, $this->liturgicalEvents)) {
            return $this->liturgicalEvents[ $key ];
        }
        return null;
    }

    /**
     * Given a date, find the corresponding key for the solemnity in the current calculated Liturgical calendar.
     * If no solemnity is found, returns false.
     *
     * @param DateTime $date The date for which to find the key for the solemnity.
     * @return string|int|false The key for the solemnity at the given date, or false if none exists.
     */
    public function solemnityKeyFromDate(DateTime $date): string|int|false
    {
        return array_search($date, $this->solemnities);
    }

    /**
     * Given a date, find the corresponding key for the weekday in the Epiphany season in the current calculated Liturgical calendar.
     * If no weekday in the Epiphany season is found, returns false.
     *
     * @param DateTime $date The date for which to find the key for the weekday in the Epiphany season.
     * @return string|int|false The key for the weekday in the Epiphany season key at the given date, or false if none exists.
     */
    public function weekdayEpiphanyKeyFromDate(DateTime $date): string|int|false
    {
        return array_search($date, $this->weekdaysEpiphany);
    }

    /**
     * Given a date, find the corresponding key for the weekday in the Advent season before December 17th in the current calculated Liturgical calendar.
     * If no weekday in the Advent season before December 17th is found, returns false.
     *
     * @param DateTime $date The date for which to find the key for the weekday in the Advent season before December 17th.
     * @return string|int|false The key for the weekday in the Advent season before December 17th at the given date, or false if none exists.
     */
    public function weekdayAdventBeforeDec17KeyFromDate(DateTime $date): string|int|false
    {
        return array_search($date, $this->weekdaysAdventBeforeDec17);
    }

    /**
     * Given a date, find the corresponding key for the weekday in the Advent (on or after December 17th), Christmas, or Lent season in the current calculated Liturgical calendar.
     * If no weekday in the Advent, Christmas, or Lent season is found, returns false.
     *
     * @param DateTime $date The date for which to find the key for the weekday in the Advent (on or after December 17th), Christmas, or Lent season.
     * @return string|int|false The key for the weekday in the Advent (on or after December 17th), Christmas, or Lent season at the given date, or false if none exists.
     */
    public function weekdayAdventChristmasLentKeyFromDate(DateTime $date): string|int|false
    {
        return array_search($date, $this->weekdaysAdventChristmasLent);
    }

    /**
     * Given a date, find the corresponding Feast or Memorial in the current calculated Liturgical calendar.
     * If no Feast or Memorial is found, returns null.
     *
     * @param DateTime $date The date to find the Feast or Memorial for.
     * @return LiturgicalEvent|null The Feast or Memorial at the given date, or null if none exists.
     */
    public function feastOrMemorialFromDate(DateTime $date): ?LiturgicalEvent
    {
        $key = array_search($date, $this->feasts);
        if ($key && array_key_exists($key, $this->liturgicalEvents)) {
            return $this->liturgicalEvents[ $key ];
        }
        $key = array_search($date, $this->memorials);
        if ($key && array_key_exists($key, $this->liturgicalEvents)) {
            return $this->liturgicalEvents[ $key ];
        }
        return null;
    }

    /**
     * Given a date, find the corresponding key for the Feast or Memorial in the current calculated Liturgical calendar.
     * If no Feast or Memorial is found, returns false.
     *
     * @param DateTime $date The date for which to find the key for the Feast or Memorial.
     * @return string|int|false The key for the Feast or Memorial at the given date, or false if none exists.
     */
    public function feastOrMemorialKeyFromDate(DateTime $date): string|int|false
    {
        $key = array_search($date, $this->feasts);
        if ($key) {
            return $key;
        }
        return array_search($date, $this->memorials);
    }

    /**
     * Given a key and a new date, moves the corresponding liturgical event to the new date
     *
     * @param string $key The key of the liturgical event to move
     * @param DateTime $newDate The new date for the liturgical event
     * @return void
     */
    public function moveLiturgicalEventDate(string $key, DateTime $newDate): void
    {
        if (array_key_exists($key, $this->liturgicalEvents)) {
            $this->liturgicalEvents[ $key ]->date = $newDate;
        }
    }

    /**
     * Updates the categorization of a liturgical event based on its grade and previous grade.
     *
     * This method modifies the liturgical event's position in the solemnities, feasts, or memorials
     * collections according to its new grade. If the new grade is greater than or equal to
     * FEAST_LORD, the liturgical event is added to the solemnities collection and removed from
     * feasts or memorials if necessary. If the new grade is FEAST, it is moved to the feasts
     * collection from solemnities or memorials. If the new grade is MEMORIAL, it is added to
     * the memorials collection and removed from solemnities or feasts if needed.
     *
     * @param string $key The key associated with the liturgical event.
     * @param int $value The new grade of the liturgical event.
     * @param int $oldValue The previous grade of the liturgical event.
     * @return void
     */
    private function handleGradeProperty(string $key, int $value, int $oldValue): void
    {
        if ($value >= LitGrade::FEAST_LORD) {
            $this->solemnities[ $key ] = $this->liturgicalEvents[ $key ]->date;
            if ($oldValue < LitGrade::FEAST_LORD && $this->feastOrMemorialKeyFromDate($this->liturgicalEvents[ $key ]->date) === $key) {
                if ($this->inFeasts($this->liturgicalEvents[ $key ]->date)) {
                    unset($this->feasts[ $key ]);
                } elseif ($this->inMemorials($this->liturgicalEvents[ $key ]->date)) {
                    unset($this->memorials[ $key ]);
                }
            }
        } elseif ($value === LitGrade::FEAST) {
            $this->feasts[ $key ] = $this->liturgicalEvents[ $key ]->date;
            if ($oldValue > LitGrade::FEAST) {
                unset($this->solemnities[ $key ]);
            } elseif ($oldValue === LitGrade::MEMORIAL) {
                unset($this->memorials[ $key ]);
            }
        } elseif ($value === LitGrade::MEMORIAL) {
            $this->memorials[ $key ] = $this->liturgicalEvents[ $key ]->date;
            if ($oldValue > LitGrade::FEAST) {
                unset($this->solemnities[ $key ]);
            } elseif ($oldValue > LitGrade::MEMORIAL) {
                unset($this->feasts[ $key ]);
            }
        }
    }

    /**
     * Sets a property of a liturgical event in the collection if it exists and matches the expected type.
     *
     * Uses reflection to ensure the property exists on the LiturgicalEvent object and checks the type
     * of the value against the expected type of the property. If the property is "grade",
     * it calls handleGradeProperty to update the liturgical event's categorization.
     *
     * @param string $key The key of the liturgical event to modify.
     * @param string $property The property name to be set.
     * @param string|int|bool $value The new value for the property.
     * @return bool True if the property was successfully set, otherwise false.
     */
    public function setProperty(string $key, string $property, string|int|bool $value): bool
    {
        $reflect = new \ReflectionClass(new LiturgicalEvent("test", new DateTime('NOW')));
        if (array_key_exists($key, $this->liturgicalEvents)) {
            $oldValue = $this->liturgicalEvents[ $key ]->{$property};
            if ($reflect->hasProperty($property)) {
                if (
                    $reflect->getProperty($property)->getType() instanceof \ReflectionNamedType
                    && $reflect->getProperty($property)->getType()->getName() === get_debug_type($value)
                ) {
                    $this->liturgicalEvents[ $key ]->{$property} = $value;
                } elseif (
                    $reflect->getProperty($property)->getType() instanceof \ReflectionUnionType
                    && in_array(get_debug_type($value), $reflect->getProperty($property)->getType()->getTypes())
                ) {
                    $this->liturgicalEvents[ $key ]->{$property} = $value;
                }
                if ($property === "grade") {
                    $this->handleGradeProperty($key, $value, $oldValue);
                    // Consequentially, we also need to update the grade_lcl and grade_abbr properties
                    $this->liturgicalEvents[ $key ]->grade_lcl = $this->LitGrade->i18n($value, false);
                    $this->liturgicalEvents[ $key ]->setGradeAbbreviation($this->LitGrade->i18n($value, false, true));
                }
                return true;
            }
        }
        return false;
    }

    /**
     * Removes a liturgical event from the collection and all relevant categorizations.
     *
     * If the liturgical event is a Solemnity, Feast, or Memorial, it is removed from the
     * respective collection. The liturgical event is then unset from the collection,
     * after being added to the suppressedEvents collection.
     *
     * @param string $key The key of the liturgical event to remove.
     */
    public function removeLiturgicalEvent(string $key): void
    {
        $date = $this->liturgicalEvents[ $key ]->date;
        if ($this->inSolemnities($date) && $this->solemnityKeyFromDate($date) === $key) {
            unset($this->solemnities[ $key ]);
        }
        if ($this->inFeasts($date) && $this->feastOrMemorialKeyFromDate($date) === $key) {
            unset($this->feasts[ $key ]);
        }
        if ($this->inMemorials($date) && $this->feastOrMemorialKeyFromDate($date) === $key) {
            unset($this->memorials[ $key ]);
        }
        $this->suppressedEvents[ $key ] = $this->liturgicalEvents[ $key ];
        unset($this->liturgicalEvents[ $key ]);
    }

    /**
     * Adds a LiturgicalEvent to the collection of suppressed events.
     *
     * This method does not perform any checks and simply adds the liturgical event to the
     * suppressedEvents collection with the given key.
     *
     * @param string $key The key associated with the liturgical event.
     * @param LiturgicalEvent $litEvent The liturgical event to be added.
     * @return void
     */
    public function addSuppressedEvent(string $key, LiturgicalEvent $litEvent): void
    {
        $this->suppressedEvents[ $key ] = $litEvent;
    }

    /**
     * Checks if a given key is associated with a suppressed event.
     *
     * @param string $key The key to check.
     * @return bool True if the key is associated with a suppressed event, otherwise false.
     */
    public function isSuppressed(string $key): bool
    {
        return array_key_exists($key, $this->suppressedEvents);
    }

    /**
     * Retrieves a suppressed event by its key.
     *
     * @param string $key The key of the suppressed event.
     * @return LiturgicalEvent The suppressed event.
     */
    public function getSuppressedEventByKey(string $key): ?LiturgicalEvent
    {
        return $this->suppressedEvents[ $key ] ?? null;
    }

    /**
     * Reinstates a suppressed event by moving it from the suppressedEvents collection to the reinstatedEvents collection.
     *
     * The event is removed from the suppressedEvents collection and added to the reinstatedEvents collection.
     *
     * @param string $key The key of the suppressed event.
     */
    public function reinstateEvent(string $key): void
    {
        $this->reinstatedEvents[ $key ] = $this->suppressedEvents[ $key ];
        unset($this->suppressedEvents[ $key ]);
    }


    /**
     * Retrieves the keys of all suppressed events.
     * @return string[] An array of event keys, each representing a suppressed event.
     */
    public function getSuppressedKeys(): array
    {
        return array_keys($this->suppressedEvents);
    }

    /**
     * Retrieves an array of suppressed events.
     *
     * The array contains LiturgicalEvent objects that were previously in the collection
     * but have been removed. The keys of the array are the event keys of the
     * suppressed events.
     *
     * @return SecondaryCollection A collection of items containing event_keys and serialized DateTime objects, each item representing a suppressed event.
     */
    public function getSuppressedEvents(): array
    {
        $suppressedEvents = [];
        foreach ($this->suppressedEvents as $key => $event) {
            $suppressedEvents[] = [
                "event_key" => $key,
                ...json_decode(json_encode($event->date), true)
            ];
        }
        return $suppressedEvents;
    }

    /**
     * Retrieves the keys of all reinstated events.
     *
     * @return string[] An array of event keys, each representing a reinstated event.
     */
    public function getReinstatedKeys(): array
    {
        return array_keys($this->reinstatedEvents);
    }

    /**
     * Retrieves an array of reinstated events.
     *
     * The array contains LiturgicalEvent objects that were previously suppressed
     * and have been moved back into the collection as reinstated events.
     *
     * @return SecondaryCollection A collection of items containing event_keys and serialized DateTime objects, each item representing a reinstated event.
     */
    public function getReinstatedEvents(): array
    {
        $reinstatedEvents = [];
        foreach ($this->reinstatedEvents as $key => $event) {
            $reinstatedEvents[] = [
                "event_key" => $key,
                ...json_decode(json_encode($event->date), true)
            ];
        }
        return $reinstatedEvents;
    }

    /**
     * Determines if a given date falls within Ordinary Time.
     *
     * Ordinary Time is defined as the period after the Baptism of the Lord
     * until Ash Wednesday, and from after Pentecost until the start of Advent.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is in Ordinary Time, otherwise false.
     */
    public function inOrdinaryTime(DateTime $date): bool
    {
        return (
            ( $date > $this->liturgicalEvents[ "BaptismLord" ]->date && $date < $this->liturgicalEvents[ "AshWednesday" ]->date )
            ||
            ( $date > $this->liturgicalEvents[ "Pentecost" ]->date && $date < $this->liturgicalEvents[ "Advent1" ]->date )
        );
    }

    /**
     * Sets the liturgical seasons, year cycles, and vigil masses for each liturgical events in the collection.
     *
     * This method iterates through all liturgical events and assigns them to a liturgical season
     * based on their date. It also determines the liturgical year cycle for weekdays and Sundays,
     * and calculates vigil masses for liturgical events that qualify.
     *
     * The liturgical seasons are defined as:
     * - Advent: from Advent1 to before Christmas
     * - Christmas: from Christmas to Baptism of the Lord
     * - Lent: from Ash Wednesday to before Holy Thursday
     * - Easter Triduum: from Holy Thursday to before Easter
     * - Easter: from Easter to Pentecost
     * - Ordinary Time: any other time
     *
     * The year cycles are determined based on whether the liturgical events falls on a weekday or a Sunday/solemnity/feast.
     * Vigil masses are calculated for eligible liturgical events.
     *
     * @return void
     */
    public function setCyclesVigilsSeasons()
    {
        // DEFINE LITURGICAL SEASONS
        foreach ($this->liturgicalEvents as $key => $litEvent) {
            if ($litEvent->date >= $this->liturgicalEvents[ "Advent1" ]->date && $litEvent->date < $this->liturgicalEvents[ "Christmas" ]->date) {
                $this->liturgicalEvents[ $key ]->liturgical_season = LitSeason::ADVENT;
            } elseif ($litEvent->date >= $this->liturgicalEvents[ "Christmas" ]->date || $litEvent->date <= $this->liturgicalEvents[ "BaptismLord" ]->date) {
                $this->liturgicalEvents[ $key ]->liturgical_season = LitSeason::CHRISTMAS;
            } elseif ($litEvent->date >= $this->liturgicalEvents[ "AshWednesday" ]->date && $litEvent->date < $this->liturgicalEvents[ "HolyThurs" ]->date) {
                $this->liturgicalEvents[ $key ]->liturgical_season = LitSeason::LENT;
            } elseif ($litEvent->date >= $this->liturgicalEvents[ "HolyThurs" ]->date && $litEvent->date < $this->liturgicalEvents[ "Easter" ]->date) {
                $this->liturgicalEvents[ $key ]->liturgical_season = LitSeason::EASTER_TRIDUUM;
            } elseif ($litEvent->date >= $this->liturgicalEvents[ "Easter" ]->date && $litEvent->date <= $this->liturgicalEvents[ "Pentecost" ]->date) {
                $this->liturgicalEvents[ $key ]->liturgical_season = LitSeason::EASTER;
            } else {
                $this->liturgicalEvents[ $key ]->liturgical_season = LitSeason::ORDINARY_TIME;
            }
        }

        // DEFINE YEAR CYCLES (except for Holy Week and Easter Octave) and VIGIL MASSES
        // This has to be a separate cycle, because in order to correctly create Vigil Masses, we need to have already set the liturgical seasons
        foreach ($this->liturgicalEvents as $key => $litEvent) {
            if ($litEvent->date <= $this->liturgicalEvents[ "PalmSun" ]->date || $litEvent->date >= $this->liturgicalEvents[ "Easter2" ]->date) {
                if (self::dateIsNotSunday($litEvent->date) && (int)$litEvent->grade === LitGrade::WEEKDAY) {
                    if ($this->inOrdinaryTime($litEvent->date)) {
                        $this->liturgicalEvents[ $key ]->liturgical_year = $this->T[ "YEAR" ] . " " . ( self::WEEKDAY_CYCLE[ ( $this->CalendarParams->Year - 1 ) % 2 ] );
                    }
                } elseif (self::dateIsSunday($litEvent->date) || (int)$litEvent->grade > LitGrade::FEAST) {
                    //if we're dealing with a Sunday or a Solemnity or a Feast of the Lord, then we calculate the Sunday/Festive Cycle
                    if ($litEvent->date < $this->liturgicalEvents[ "Advent1" ]->date) {
                        $this->liturgicalEvents[ $key ]->liturgical_year = $this->T[ "YEAR" ] . " " . ( self::SUNDAY_CYCLE[ ( $this->CalendarParams->Year - 1 ) % 3 ] );
                    } elseif ($litEvent->date >= $this->liturgicalEvents[ "Advent1" ]->date) {
                        $this->liturgicalEvents[ $key ]->liturgical_year = $this->T[ "YEAR" ] . " " . ( self::SUNDAY_CYCLE[ $this->CalendarParams->Year % 3 ] );
                    }

                    // DEFINE VIGIL MASSES within the same cycle, to avoid having to create/run yet another cycle
                    $this->calculateVigilMass($key, $litEvent);
                }
            }
        }
    }

    /**
     * Determines if a given liturgical event can have a vigil mass.
     *
     * This function evaluates whether a liturgical event, identified by its key and date, is eligible for a vigil mass.
     * It excludes specific liturgical events and date ranges, such as 'AllSouls', 'AshWednesday', and the period
     * between Palm Sunday and Easter.
     *
     * @param LiturgicalEvent|\stdClass $litEvent The liturgical event object or a standard class instance representing the liturgical event.
     * @param string|null $key The key associated with the liturgical event, if applicable.
     * @return bool True if the liturgical event can have a vigil mass, false otherwise.
     */
    private function liturgicalEventCanHaveVigil(LiturgicalEvent|\stdClass $litEvent, ?string $key = null): bool
    {
        if ($litEvent instanceof LiturgicalEvent) {
            return (
                false === ( $key === 'AllSouls' )
                && false === ( $key === 'AshWednesday' )
                && false === ( $litEvent->date > $this->liturgicalEvents[ "PalmSun" ]->date && $litEvent->date < $this->liturgicalEvents[ "Easter" ]->date )
                && false === ( $litEvent->date > $this->liturgicalEvents[ "Easter" ]->date && $litEvent->date < $this->liturgicalEvents[ "Easter2" ]->date )
            );
        }
        else {
            return (
                false === ( $litEvent->event->date > $this->liturgicalEvents[ "PalmSun" ]->date && $litEvent->event->date < $this->liturgicalEvents[ "Easter" ]->date )
                && false === ( $litEvent->event->date > $this->liturgicalEvents[ "Easter" ]->date && $litEvent->event->date < $this->liturgicalEvents[ "Easter2" ]->date )
            );
        }
    }

    /**
     * Creates a Vigil Mass event for a given liturgical event.
     *
     * The method creates a new LiturgicalEvent object with the same properties as the given LiturgicalEvent,
     * except for its name, which is suffixed with " Vigil Mass". The new liturgical event's date is set to the VigilDate.
     * The method also sets the has_vigil_mass, has_vesper_i, and has_vesper_ii properties to true for the given liturgical event,
     * and the is_vigil_mass and is_vigil_for properties to true and the given key, respectively, for the new liturgical event.
     *
     * @param string $key The key of the liturgical event for which a Vigil Mass is to be created.
     * @param LiturgicalEvent $litEvent The LiturgicalEvent object for which a Vigil Mass is to be created.
     * @param DateTime $VigilDate The date of the Vigil Mass.
     */
    private function createVigilMass(string $key, LiturgicalEvent $litEvent, DateTime $VigilDate): void
    {
        $this->liturgicalEvents[ $key . "_vigil" ] = new LiturgicalEvent(
            $litEvent->name . " " . $this->T[ "Vigil Mass" ],
            $VigilDate,
            $litEvent->color,
            $litEvent->type,
            $litEvent->grade,
            $litEvent->common,
            $litEvent->grade_display
        );

        $this->liturgicalEvents[ $key ]->has_vigil_mass               = true;
        $this->liturgicalEvents[ $key ]->has_vesper_i                 = true;
        $this->liturgicalEvents[ $key ]->has_vesper_ii                = true;
        $this->liturgicalEvents[ $key . "_vigil" ]->is_vigil_mass     = true;
        $this->liturgicalEvents[ $key . "_vigil" ]->is_vigil_for      = $key;
        $this->liturgicalEvents[ $key . "_vigil" ]->liturgical_year   = $this->liturgicalEvents[ $key ]->liturgical_year;
        $this->liturgicalEvents[ $key . "_vigil" ]->liturgical_season = $this->liturgicalEvents[ $key ]->liturgical_season;
    }

    /**
     * Determines if a coinciding event takes precedence over a vigil event.
     *
     * This function evaluates whether the grade of a coinciding event is greater than
     * the grade of the given liturgical event or if the coinciding event is a Solemnity of
     * the Lord or the Blessed Virgin Mary, while the given liturgical event is not.
     *
     * @param string $key The key of the given liturgical event.
     * @param LiturgicalEvent $litEvent The LiturgicalEvent object for which precedence is being evaluated.
     * @param \stdClass $coincidingEvent The coinciding liturgical event object.
     * @return bool True if the coinciding event takes precedence, false otherwise.
     */
    private function coincidingLiturgicalEventTakesPrecedenceOverVigil(string $key, LiturgicalEvent $litEvent, \stdClass $coincidingEvent): bool
    {
        return (
            $litEvent->grade < $coincidingEvent->event->grade ||
            ( $this->isSolemnityLordBVM($coincidingEvent->key) && !$this->isSolemnityLordBVM($key) )
        );
    }

    /**
     * Determines if a vigil event takes precedence over a coinciding event.
     *
     * This function evaluates whether the grade of the vigil event is greater than
     * the grade of the coinciding event or if the vigil event is a Solemnity of
     * the Lord or the Blessed Virgin Mary, while the coinciding event is not.
     *
     * @param string $key The key of the vigil event.
     * @param LiturgicalEvent $litEvent The LiturgicalEvent object of the vigil event.
     * @param \stdClass $coincidingEvent The coinciding event object.
     * @return bool True if the vigil event takes precedence, false otherwise.
     */
    private function vigilTakesPrecedenceOverCoincidingLiturgicalEvent(string $key, LiturgicalEvent $litEvent, \stdClass $coincidingEvent): bool
    {
        return (
            $litEvent->grade > $coincidingEvent->event->grade ||
            ( $this->isSolemnityLordBVM($key) && !$this->isSolemnityLordBVM($coincidingEvent->key) )
        );
    }

    /**
     * Handles the coincidence of a vigil event with another liturgical event.
     *
     * This function determines whether the vigil event or the coinciding liturgical event
     * takes precedence based on the provided parameters. It updates the event
     * properties and appends messages to the Messages array to reflect the outcome.
     * If the vigil event takes precedence, it confirms a Vigil Mass and I Vespers.
     * If the coinciding event takes precedence, the vigil is removed, and II Vespers
     * is confirmed for the coinciding event.
     *
     * @param string $key The key of the liturgical event being evaluated.
     * @param LiturgicalEvent $litEvent The LiturgicalEvent object being evaluated.
     * @param string $litEventGrade The grade of the liturgical event.
     * @param \stdClass $coincidingEvent The coinciding liturgical event object.
     * @param bool|string $vigilTakesPrecedence Indicates if the vigil takes precedence or a special case string.
     * @return void
     */
    private function handleVigilLiturgicalEventCoincidence(string $key, LiturgicalEvent $litEvent, string $litEventGrade, \stdClass $coincidingEvent, bool|string $vigilTakesPrecedence): void
    {
        if (gettype($vigilTakesPrecedence) === "string" && $vigilTakesPrecedence === "YEAR2022") {
            $litEvent->has_vigil_mass              = true;
            $litEvent->has_vesper_i                = true;
            $coincidingEvent->event->has_vesper_ii = false;

            $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                _('The Vigil Mass for the %1$s \'%2$s\' coincides with the %3$s \'%4$s\' in the year %5$d. As per %6$s, the first has precedence, therefore the Vigil Mass is confirmed as are I Vespers.'),
                $litEventGrade,
                $litEvent->name,
                $coincidingEvent->grade,
                $coincidingEvent->event->name,
                $this->CalendarParams->Year,
                '<a href="http://www.cultodivino.va/content/cultodivino/it/documenti/responsa-ad-dubia/2020/de-calendario-liturgico-2022.html">' . _("Decree of the Congregation for Divine Worship") . '</a>'
            );
        } else {
            $litEvent->has_vigil_mass              = $vigilTakesPrecedence;
            $litEvent->has_vesper_i                = $vigilTakesPrecedence;
            $coincidingEvent->event->has_vesper_ii = !$vigilTakesPrecedence;
            if ($vigilTakesPrecedence) {
                $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                    _('The Vigil Mass for the %1$s \'%2$s\' coincides with the %3$s \'%4$s\' in the year %5$d. Since the first Solemnity has precedence, it will have Vespers I and a vigil Mass, whereas the last Solemnity will not have either Vespers II or an evening Mass.'),
                    $litEventGrade,
                    $litEvent->name,
                    $coincidingEvent->grade,
                    $coincidingEvent->event->name,
                    $this->CalendarParams->Year
                );
            } else {
                unset($this->liturgicalEvents[ $key . "_vigil" ]);
                $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                    _('The Vigil Mass for the %1$s \'%2$s\' coincides with the %3$s \'%4$s\' in the year %5$d. This last Solemnity takes precedence, therefore it will maintain Vespers II and an evening Mass, while the first Solemnity will not have a Vigil Mass or Vespers I.'),
                    $litEventGrade,
                    $litEvent->name,
                    $coincidingEvent->grade,
                    $coincidingEvent->event->name,
                    $this->CalendarParams->Year
                );
            }
        }
    }

    /**
     * Given a liturgical event, calculate whether it should have a vigil or not, and eventually create the Vigil Mass liturgical event.
     * If the Vigil coincides with another Solemnity, make a note of it and handle it accordingly.
     *
     * @param string $key The key of the liturgical event.
     * @param LiturgicalEvent $litEvent The liturgical event object.
     */
    private function calculateVigilMass(string $key, LiturgicalEvent $litEvent): void
    {
        //Not only will we create new events, we will also add metadata to existing events
        $VigilDate = clone( $litEvent->date );
        $VigilDate->sub(new \DateInterval('P1D'));
        $litEventGrade = '';
        if (self::dateIsSunday($litEvent->date) && $litEvent->grade < LitGrade::SOLEMNITY) {
            $litEventGrade = $this->CalendarParams->Locale === LitLocale::LATIN ? 'Die Domini' : ucfirst($this->dayOfTheWeek->format($litEvent->date->format('U')));
        } else {
            if ($litEvent->grade > LitGrade::SOLEMNITY) {
                $litEventGrade = '<i>' . $this->LitGrade->i18n($litEvent->grade, false) . '</i>';
            } else {
                $litEventGrade = $this->LitGrade->i18n($litEvent->grade, false);
            }
        }

        //conditions for which the liturgical event SHOULD have a vigil
        if (self::dateIsSunday($litEvent->date) || true === ( $litEvent->grade >= LitGrade::SOLEMNITY )) {
            //filter out cases in which the liturgical event should NOT have a vigil
            if ($this->liturgicalEventCanHaveVigil($litEvent, $key)) {
                $this->createVigilMass($key, $litEvent, $VigilDate);
                //if however the Vigil coincides with another Solemnity let's make a note of it!
                if ($this->inSolemnities($VigilDate)) {
                    $coincidingEvent        = new \stdClass();
                    $coincidingEvent->grade = '';
                    $coincidingEvent->key   = $this->solemnityKeyFromDate($VigilDate);
                    $coincidingEvent->event = $this->liturgicalEvents[ $coincidingEvent->key ];
                    if (self::dateIsSunday($VigilDate) && $coincidingEvent->event->grade < LitGrade::SOLEMNITY) {
                        //it's a Sunday
                        $coincidingEvent->grade = $this->CalendarParams->Locale === LitLocale::LATIN
                            ? 'Die Domini'
                            : ucfirst($this->dayOfTheWeek->format($VigilDate->format('U')));
                    } else {
                        //it's a Feast of the Lord or a Solemnity
                        $coincidingEvent->grade = ( $coincidingEvent->event->grade > LitGrade::SOLEMNITY ? '<i>' . $this->LitGrade->i18n($coincidingEvent->event->grade, false) . '</i>' : $this->LitGrade->i18n($coincidingEvent->event->grade, false) );
                    }

                    //suppress warning messages for known situations, like the Octave of Easter
                    if ($litEvent->grade !== LitGrade::HIGHER_SOLEMNITY) {
                        if ($this->coincidingLiturgicalEventTakesPrecedenceOverVigil($key, $litEvent, $coincidingEvent)) {
                            $this->handleVigilLiturgicalEventCoincidence($key, $litEvent, $litEventGrade, $coincidingEvent, false);
                        } elseif ($this->vigilTakesPrecedenceOverCoincidingLiturgicalEvent($key, $litEvent, $coincidingEvent)) {
                            $this->handleVigilLiturgicalEventCoincidence($key, $litEvent, $litEventGrade, $coincidingEvent, true);
                        } elseif ($this->CalendarParams->Year === 2022 && ( $key === 'SacredHeart' || $key === 'Lent3' || $key === 'Assumption' )) {
                            $this->handleVigilLiturgicalEventCoincidence($key, $litEvent, $litEventGrade, $coincidingEvent, "YEAR2022");
                        } else {
                            $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                _('The Vigil Mass for the %1$s \'%2$s\' coincides with the %3$s \'%4$s\' in the year %5$d. We should ask the Congregation for Divine Worship what to do about this!'),
                                $litEventGrade,
                                $litEvent->name,
                                $coincidingEvent->grade,
                                $coincidingEvent->event->name,
                                $this->CalendarParams->Year
                            );
                        }
                    } elseif ($this->liturgicalEventCanHaveVigil($coincidingEvent, null)) {
                        $this->handleVigilLiturgicalEventCoincidence($key, $litEvent, $litEventGrade, $coincidingEvent, true);
                    }
                }
            } else {
                $this->liturgicalEvents[ $key ]->has_vigil_mass = false;
                $this->liturgicalEvents[ $key ]->has_vesper_i   = false;
            }
        }
    }

    /**
     * Sorts the liturgical events by date, while maintaining their association with their keys.
     *
     * The liturgical events array is an associative array of LiturgicalEvent objects, where the key is a string that identifies the event created (ex. ImmaculateConception).
     * In order to sort by date while preserving the key association, we use the uasort function.
     */
    public function sortLiturgicalEvents(): void
    {
        uasort($this->liturgicalEvents, array( "LiturgicalCalendar\Api\LiturgicalEvent", "compDate" ));
    }

    /**
     * Retrieves all liturgical events from the collection.
     *
     * @return LiturgicalEvent[] An array of LiturgicalEvent objects contained in the collection.
     */
    public function getLiturgicalEvents(): array
    {
        return $this->liturgicalEvents;
    }

    /**
     * Retrieves a collection of all solemnities from the collection.
     *
     * @return LiturgicalEventsMap A map of solemnity keys to their corresponding LiturgicalEvent objects.
     */
    public function getSolemnities(): array
    {
        return $this->solemnities;
    }

    /**
     * Retrieves all solemnities from the collection in a format that can be easily converted to JSON.
     *
     * This method is similar to getSolemnities, but it returns an array of arrays, where each inner array contains the key of the
     * event and the properties of the DateTime object as key-value pairs.
     *
     * @return SecondaryCollection An array of arrays, where each inner array contains the key of the event and the properties of the DateTime object as key-value pairs.
     */
    public function getSolemnitiesCollection(): array
    {
        if (empty($this->solemnitiesCollection)) {
            $solemnitiesCollection = [];
            foreach ($this->solemnities as $key => $solemnity) {
                $solemnitiesCollection[] = [
                    "event_key" => $key,
                    ...json_decode(json_encode($solemnity), true)
                ];
            }
            return $solemnitiesCollection;
        } else {
            return $this->solemnitiesCollection;
        }
    }

    /**
     * Retrieves the keys of all solemnities from the collection.
     *
     * @return string[] An array of keys, each representing a solemnity in the collection.
     */
    public function getSolemnitiesKeys(): array
    {
        return array_keys($this->solemnities);
    }

    /**
     * Retrieves all feasts from the collection.
     *
     * @return LiturgicalEventsMap A map of event keys to their corresponding LiturgicalEvent objects, each representing a feast.
     */
    public function getFeasts(): array
    {
        return $this->feasts;
    }

    /**
     * Retrieves all feasts from the collection in a format that can be easily converted to JSON.
     *
     * This method is similar to getFeasts, but it returns an array of arrays, where each inner array contains the key of the
     * event and the properties of the DateTime object as key-value pairs.
     *
     * @return SecondaryCollection An array of arrays, where each inner array contains the key of the event and the properties of the DateTime object as key-value pairs.
     */
    public function getFeastsCollection(): array
    {
        if (empty($this->feastsCollection)) {
            $feastsCollection = [];
            foreach ($this->feasts as $key => $feast) {
                $feastsCollection[] = [
                    "event_key" => $key,
                    ...json_decode(json_encode($feast), true)
                ];
            }
            return $feastsCollection;
        } else {
            return $this->feastsCollection;
        }
    }

    /**
     * Retrieves the keys of all feasts from the collection.
     *
     * @return string[] An array of keys, each representing a feast in the collection.
     */
    public function getFeastsKeys(): array
    {
        return array_keys($this->feasts);
    }

    /**
     * Retrieves all memorials from the collection.
     *
     * @return LiturgicalEventsMap A map of event keys to their corresponding LiturgicalEvent objects, each representing a memorial.
     */
    public function getMemorials(): array
    {
        return $this->memorials;
    }

    /**
     * Retrieves all memorials from the collection in a format that can be easily converted to JSON.
     *
     * This method is similar to getMemorials, but it returns an array of arrays, where each inner array contains the key of the
     * event and the properties of the DateTime object as key-value pairs.
     *
     * @return SecondaryCollection An array of arrays, where each inner array contains the key of the event and the properties of the DateTime object as key-value pairs.
     */
    public function getMemorialsCollection(): array
    {
        if (empty($this->memorialsCollection)) {
            $memorialsCollection = [];
            foreach ($this->memorials as $key => $memorial) {
                $memorialsCollection[] = [
                    "event_key" => $key,
                    ...json_decode(json_encode($memorial), true)
                ];
            }
            return $memorialsCollection;
        } else {
            return $this->memorialsCollection;
        }
    }

    /**
     * Retrieves all weekdays in the seasons of Advent (on or after December 17th), Christmas, or Lent
     * in a format that can be easily converted to JSON.
     *
     * This method returns an array of arrays, where each inner array contains the key of the
     * event and the properties of the DateTime object as key-value pairs.
     *
     * @return SecondaryCollection An array of arrays, where each inner array contains the key of the event
     * and the properties of the DateTime object as key-value pairs.
     */
    public function getWeekdaysAdventChristmasLentCollection(): array
    {
        if (empty($this->weekdaysAdventChristmasLentCollection)) {
            $weekdaysAdventChristmasLentCollection = [];
            foreach ($this->weekdaysAdventChristmasLent as $key => $weekday) {
                $weekdaysAdventChristmasLentCollection[] = [
                    "event_key" => $key,
                    ...json_decode(json_encode($weekday), true)
                ];
            }
            return $weekdaysAdventChristmasLentCollection;
        } else {
            return $this->weekdaysAdventChristmasLentCollection;
        }
    }

    /**
     * Retrieves all weekdays in Advent before December 17th in a format that can be easily converted to JSON.
     *
     * This method returns an array of arrays, where each inner array contains the key of the
     * event and the properties of the DateTime object as key-value pairs.
     *
     * These are days on which obligatory memorials will suppress the Advent weekday.
     *
     * @return SecondaryCollection An array of arrays, where each inner array contains the key of the event
     * and the properties of the DateTime object as key-value pairs.
     */
    public function getWeekdaysAdventBeforeDec17Collection(): array
    {
        if (empty($this->weekdaysAdventBeforeDec17Collection)) {
            $weekdaysAdventBeforeDec17Collection = [];
            foreach ($this->weekdaysAdventBeforeDec17 as $key => $weekday) {
                $weekdaysAdventBeforeDec17Collection[] = [
                    "event_key" => $key,
                    ...json_decode(json_encode($weekday), true)
                ];
            }
            return $weekdaysAdventBeforeDec17Collection;
        } else {
            return $this->weekdaysAdventBeforeDec17Collection;
        }
    }

    /**
     * Retrieves all weekdays in the season of Epiphany
     * in a format that can be easily converted to JSON.
     *
     * This method returns an array of arrays, where each inner array contains the key of the
     * event and the properties of the DateTime object as key-value pairs.
     *
     * @return SecondaryCollection An array of arrays, where each inner array contains the key of the event
     * and the properties of the DateTime object as key-value pairs.
     */
    public function getWeekdaysEpiphanyCollection(): array
    {
        if (empty($this->weekdaysEpiphanyCollection)) {
            $weekdaysEpiphanyCollection = [];
            foreach ($this->weekdaysEpiphany as $key => $weekday) {
                $weekdaysEpiphanyCollection[] = [
                    "event_key" => $key,
                    ...json_decode(json_encode($weekday), true)
                ];
            }
            return $weekdaysEpiphanyCollection;
        } else {
            return $this->weekdaysEpiphanyCollection;
        }
    }

    /**
     * Retrieves all solemnities of the Lord and the BVM from the collection
     * in a format that can be easily converted to JSON.
     *
     * This method returns an array of arrays, where each inner array contains the key of the event
     * and the properties of the DateTime object as key-value pairs.
     *
     * @return SecondaryCollection An array of arrays, where each inner array contains the key of the event
     * and the properties of the DateTime object as key-value pairs.
     */
    public function getSolemnitiesLordBVMCollection(): array
    {
        if (empty($this->solemnitiesLordBVMCollection)) {
            $solemnitiesLordBVMCollection = [];
            foreach ($this->solemnitiesLordBVM as $key => $solemnity) {
                $solemnitiesLordBVMCollection[] = [
                    "event_key" => $key,
                    ...json_decode(json_encode($solemnity), true)
                ];
            }
            return $solemnitiesLordBVMCollection;
        } else {
            return $this->solemnitiesLordBVMCollection;
        }
    }

    /**
     * Retrieves all Sundays in the seasons of Advent, Lent, and Easter
     * in a format that can be easily converted to JSON.
     *
     * This method returns an array of arrays, where each inner array contains the key of the
     * event and the properties of the DateTime object as key-value pairs.
     *
     * @return SecondaryCollection An array of arrays, where each inner array contains the key of the event
     * and the properties of the DateTime object as key-value pairs.
     */
    public function getSundaysAdventLentEasterCollection(): array
    {
        if (empty($this->sundaysAdventLentEasterCollection)) {
            $sundaysAdventLentEasterCollection = [];
            foreach ($this->sundaysAdventLentEaster as $key => $sunday) {
                $sundaysAdventLentEasterCollection[] = [
                    "event_key" => $key,
                    ...json_decode(json_encode($sunday), true)
                ];
            }
            return $sundaysAdventLentEasterCollection;
        } else {
            return $this->sundaysAdventLentEasterCollection;
        }
    }

    /**
     * Retrieves all liturgical events from the collection
     * in a format that can be easily converted to JSON.
     *
     * This method returns a collection of liturgical events or celebrations,
     * each with properties such as event_key, name, date, etc.
     *
     * @return EventCollection
     */
    public function getLiturgicalEventsCollection(): array
    {
        if (empty($this->liturgicalEventsCollection)) {
            foreach ($this->liturgicalEvents as $key => $litEvent) {
                $this->add($key, $litEvent);
            }
        }
        return $this->liturgicalEventsCollection;
    }

    /**
     * Retrieves the keys of all memorials from the collection.
     *
     * @return string[] An array of keys, each representing a memorial in the collection.
     */
    public function getMemorialsKeys(): array
    {
        return array_keys($this->memorials);
    }

    /**
     * Retrieves all weekdays in Advent before December 17th.
     *
     * These are days on which obligatory memorials will suppress the Advent weekday.
     *
     * @return LiturgicalEventsMap A map of event_keys to LiturgicalEvent objects, each representing a weekday in Advent before December 17th.
     */
    public function getWeekdaysAdventBeforeDec17(): array
    {
        return $this->weekdaysAdventBeforeDec17;
    }

    /**
     * Retrieves all weekdays in the seasons of Advent (on or after December 17th), Christmas, or Lent.
     *
     * These are days on which optional memorials can only be celebrated in partial form.
     *
     * @return LiturgicalEventsMap A map of event_keys to LiturgicalEvent objects, each representing a weekday in the seasons of Advent (on or after December 17th), Christmas, or Lent.
     */
    public function getWeekdaysAdventChristmasLent(): array
    {
        return $this->weekdaysAdventChristmasLent;
    }

    /**
     * Retrieves all weekdays in the Epiphany season.
     *
     * @return LiturgicalEventsMap A map of event_keys to LiturgicalEvent objects, each representing a weekday in the Epiphany season.
     */
    public function getWeekdaysEpiphany(): array
    {
        return $this->weekdaysEpiphany;
    }

    /**
     * Retrieves all solemnities of the Lord and of the Blessed Virgin Mary.
     *
     * These are special solemnities that are higher in rank than regular solemnities.
     *
     * @return LiturgicalEventsMap A map of event_keys to LiturgicalEvent objects, each representing a solemnity of the Lord or the Blessed Virgin Mary.
     */
    public function getSolemnitiesLordBVM(): array
    {
        return $this->solemnitiesLordBVM;
    }

    /**
     * Retrieves all Sundays in the seasons of Advent, Lent, and Easter.
     *
     * @return LiturgicalEventsMap A map of event_keys to LiturgicalEvent objects, each representing a Sunday in the seasons of Advent, Lent, or Easter.
     */
    public function getSundaysAdventLentEaster(): array
    {
        return $this->sundaysAdventLentEaster;
    }

    /**
     * Retrieves all feasts and memorials from the collection.
     *
     * @return LiturgicalEventsMap A map of event_keys to LiturgicalEvent objects, each representing feasts and memorials.
     */
    public function getFeastsAndMemorials(): array
    {
        return array_merge($this->feasts, $this->memorials);
    }

    /**
     * Given a date, returns the coinciding Sunday solemnity or feast, or the coinciding feast or memorial.
     *
     * If the date is a Sunday, it returns the Sunday solemnity or feast.
     * If the date is a Feast of the Lord or a Solemnity, it returns the Feast of the Lord or Solemnity.
     * If the date is a feast or memorial, it returns the feast or memorial.
     *
     * The returned object contains two properties: 'event' and 'grade'.
     * The 'event' property is the coinciding Sunday solemnity or feast, or the coinciding feast or memorial.
     * The 'grade' property is the grade of the coinciding event, formatted as a string.
     *
     * @param DateTime $currentFeastDate The date to check for coinciding events.
     * @return object{grade: string, event: LiturgicalEvent} An object containing the coinciding event and its grade.
     */
    public function determineSundaySolemnityOrFeast(DateTime $currentFeastDate): object
    {
        $coincidingEvent        = new \stdClass();
        $coincidingEvent->grade = '';
        if (self::dateIsSunday($currentFeastDate) && $this->solemnityFromDate($currentFeastDate)->grade < LitGrade::SOLEMNITY) {
            //it's a Sunday
            $coincidingEvent->event = $this->solemnityFromDate($currentFeastDate);
            $coincidingEvent->grade = $this->CalendarParams->Locale === LitLocale::LATIN
                ? 'Die Domini'
                : ucfirst($this->dayOfTheWeek->format($currentFeastDate->format('U')));
        } elseif ($this->inSolemnities($currentFeastDate)) {
            //it's a Feast of the Lord or a Solemnity
            $coincidingEvent->event = $this->solemnityFromDate($currentFeastDate);
            $coincidingEvent->grade = ( $coincidingEvent->event->grade > LitGrade::SOLEMNITY
                ? '<i>' . $this->LitGrade->i18n($coincidingEvent->event->grade, false) . '</i>'
                : $this->LitGrade->i18n($coincidingEvent->event->grade, false) );
        } elseif ($this->inFeastsOrMemorials($currentFeastDate)) {
            $coincidingEvent->event = $this->feastOrMemorialFromDate($currentFeastDate);
            $coincidingEvent->grade = $this->LitGrade->i18n($coincidingEvent->event->grade, false);
        }
        return $coincidingEvent;
    }

    /**
     * Returns the psalter week number for a given week of Ordinary Time or a seasonal week.
     *
     * The psalter week number is a number from 1 to 4, where 1 is the first week of Ordinary Time,
     * of Advent, of Christmas, of Lent, or of Easter; 2 is the second week, and so on.
     * If the given week number is a multiple of 4, the psalter week is 4.
     * Otherwise, the psalter week is the remainder of the week number divided by 4.
     *
     * @param int $weekOfOrdinaryTimeOrSeason The week number of Ordinary Time or a seasonal week.
     * @return int The psalter week number.
     */
    public static function psalterWeek(int $weekOfOrdinaryTimeOrSeason): int
    {
        return $weekOfOrdinaryTimeOrSeason % 4 === 0 ? 4 : $weekOfOrdinaryTimeOrSeason % 4;
    }


    /**
     * Iterate over all liturgical events and check if the psalter_week property is set.
     * If it is not set, then:
     * - if the liturgical event is a Vigil Mass, check if the corresponding event for which it is a Vigil Mass has a psalter_week property and set the Vigil Mass to the same value.
     * - if the liturgical event is a Commemoration or an Optional Memorial, check if there is a ferial event on the same day with a psalter_week property and set the Commemoration or Optional Memorial to the same value.
     * - otherwise, set the psalter_week property to 0.
     */
    public function calculatePsalterWeek(): void
    {
        //$messages = [];
        foreach ($this->liturgicalEvents as $key => $value) {
            //$messages[] = "Checking entry $key...";
            if (false === property_exists($value, 'psalter_week') || null === $value->psalter_week) {
                //$messages[] = "***** The {$this->liturgicalEvents[$key]->grade_lcl} of {$this->liturgicalEvents[$key]->name} does not have a psalter_week property *****";

                if (property_exists($value, 'is_vigil_mass') && $value->is_vigil_mass) {
                    // Vigils can inherit the value from the corresponding event for which they are vigils
                    //$messages[] = "!!!!! The {$this->liturgicalEvents[$key]->grade_lcl} of {$this->liturgicalEvents[$key]->name} is a Vigil Mass and does not have a psalter_week property !!!!!";
                    if (property_exists($this->liturgicalEvents[$value->is_vigil_for], 'psalter_week') && null !== $this->liturgicalEvents[$value->is_vigil_for]->psalter_week) {
                        //$messages[] = "The {$this->liturgicalEvents[$value->is_vigil_for]->grade_lcl} of {$this->liturgicalEvents[$value->is_vigil_for]->name} for which it is a Vigil Mass DOES have a psalter_week property with value {$this->liturgicalEvents[$value->is_vigil_for]->psalter_week}";
                        $this->liturgicalEvents[$key]->psalter_week = $this->liturgicalEvents[$value->is_vigil_for]->psalter_week;
                        //$messages[] = "The psalter_week for the {$this->liturgicalEvents[$key]->grade_lcl} of {$this->liturgicalEvents[$key]->name} was set to {$this->liturgicalEvents[$key]->psalter_week}";
                    } else {
                        //$messages[] = "The {$this->liturgicalEvents[$value->is_vigil_for]->grade_lcl} of {$this->liturgicalEvents[$value->is_vigil_for]->name} for which it is a Vigil Mass DOES NOT have a psalter_week property, setting both to 0";
                        $this->liturgicalEvents[$key]->psalter_week                 = 0;
                        $this->liturgicalEvents[$value->is_vigil_for]->psalter_week = 0;
                    }
                } elseif ($this->liturgicalEvents[$key]->grade === 1 || $this->liturgicalEvents[$key]->grade === 2) {
                    // Commemorations and Optional memorials can inherit the value from a same day event
                    //$messages[] = "^^^^^ The {$this->liturgicalEvents[$key]->grade_lcl} of {$this->liturgicalEvents[$key]->name} does not have a psalter_week property, checking in ferial events on the same day ^^^^^";
                    $ferialEventSameDay = array_values(array_filter(
                        $this->liturgicalEvents,
                        fn ($item) => $item->grade === 0 && $item->date == $this->liturgicalEvents[$key]->date // do NOT use strict check for date, will not work
                    ));
                    //$messages[] = $ferialEventSameDay;
                    if (count($ferialEventSameDay) && property_exists($ferialEventSameDay[0], 'psalter_week') && null !== $ferialEventSameDay[0]->psalter_week) {
                        //$messages[] = "Found a ferial event on the same day that has a psalter_week property with value {$ferialEventSameDay[0]->psalter_week}";
                        $this->liturgicalEvents[$key]->psalter_week = $ferialEventSameDay[0]->psalter_week;
                    } else {
                        //$messages[] = "No ferial event on the same day that has a psalter_week property, setting value to 0...";
                        $this->liturgicalEvents[$key]->psalter_week = 0;
                    }
                    //$messages[] = "The psalter_week property for the {$this->liturgicalEvents[$key]->grade_lcl} of {$this->liturgicalEvents[$key]->name} was set to {$this->liturgicalEvents[$key]->psalter_week}";
                } else {
                    $this->liturgicalEvents[$key]->psalter_week = 0;
                    //$messages[] = "The psalter_week value for the {$this->liturgicalEvents[$key]->grade_lcl} of {$this->liturgicalEvents[$key]->name} was set to 0";
                }
            }
        }
        //die(json_encode($messages));
    }

    /**
     * Removes all liturgical events with a date before the First Sunday of Advent,
     * except for the Vigil Mass for the First Sunday of Advent.
     *
     * This method is used to clear out liturgical events that are not relevant to the
     * current liturgical year, such as liturgical events that occur before the First
     * Sunday of Advent.
     */
    public function purgeDataBeforeAdvent(): void
    {
        foreach ($this->liturgicalEvents as $key => $litEvent) {
            if ($litEvent->date < $this->liturgicalEvents[ "Advent1" ]->date) {
                //remove all except the Vigil Mass for the first Sunday of Advent
                if (
                    ( null === $litEvent->is_vigil_mass )
                    ||
                    ( $litEvent->is_vigil_mass && $litEvent->is_vigil_for !== "Advent1" )
                ) {
                    unset($this->liturgicalEvents[ $key ]);
                    // make sure it isn't still contained in another collection
                    unset($this->solemnities[ $key ]);
                    unset($this->feasts[ $key ]);
                    unset($this->memorials[ $key ]);
                    unset($this->weekdaysAdventChristmasLent[ $key ]);
                    unset($this->weekdaysEpiphany[ $key ]);
                    unset($this->solemnitiesLordBVM[ $key ]);
                    unset($this->sundaysAdventLentEaster[ $key ]);
                    unset($this->reinstatedEvents[ $key ]);
                }
            }
        }
        foreach ($this->suppressedEvents as $key => $litEvent) {
            if ($litEvent->date < $this->liturgicalEvents[ "Advent1" ]->date) {
                unset($this->suppressedEvents[ $key ]);
            }
        }
    }

    /**
     * Removes all liturgical events with a date after the First Sunday of Advent,
     * including the Vigil Mass for the first Sunday of Advent, and the First
     * Sunday of Advent itself.
     *
     * This method is used to clear out liturgical events that are not relevant to the
     * current liturgical year, such as liturgical events that occur on or after the First
     * Sunday of Advent.
     */
    public function purgeDataAdventChristmas(): void
    {
        foreach ($this->liturgicalEvents as $key => $litEvent) {
            if ($litEvent->date > $this->liturgicalEvents[ "Advent1" ]->date) {
                unset($this->liturgicalEvents[ $key ]);
                // make sure it isn't still contained in another collection
                unset($this->solemnities[ $key ]);
                unset($this->feasts[ $key ]);
                unset($this->memorials[ $key ]);
                unset($this->weekdaysAdventChristmasLent[ $key ]);
                unset($this->solemnitiesLordBVM[ $key ]);
                unset($this->sundaysAdventLentEaster[ $key ]);
                unset($this->reinstatedEvents[ $key ]);
            }
            // also remove the Vigil Mass for the first Sunday of Advent
            // unfortunately we cannot keep it, because it would have the same key as for the other calendar year
            if (
                null !== $litEvent->is_vigil_mass
                &&
                $litEvent->is_vigil_mass
                &&
                $litEvent->is_vigil_for === "Advent1"
            ) {
                unset($this->liturgicalEvents[ $key ]);
            }
        }
        foreach ($this->suppressedEvents as $key => $litEvent) {
            if ($litEvent->date > $this->liturgicalEvents[ "Advent1" ]->date) {
                unset($this->suppressedEvents[ $key ]);
            }
        }
        //lastly remove First Sunday of Advent
        unset($this->liturgicalEvents[ "Advent1" ]);
        unset($this->solemnities[ "Advent1" ]);
    }

    /**
     * Merges the current LiturgicalEventCollection with another LiturgicalEventCollection.
     *
     * This method merges the two collections by combining their respective
     * collections of solemnities, feasts, memorials, weekdays of Advent,
     * Christmas and Lent, weekdays of Epiphany, solemnities of Lord and BVM,
     * Sundays of Advent, Lent, and Easter, and the collection of all
     * liturgical events. The merged collection is then stored in the current
     * LiturgicalEventCollection.
     *
     * @param LiturgicalEventCollection $litEvents The LiturgicalEventCollection to merge with the current one.
     * @return void
     */
    public function mergeLiturgicalEventCollection(LiturgicalEventCollection $litEvents)
    {
        $this->solemnitiesCollection = array_merge($this->getSolemnitiesCollection(), $litEvents->getSolemnitiesCollection());
        $this->feastsCollection      = array_merge($this->getFeastsCollection(), $litEvents->getFeastsCollection());
        $this->memorialsCollection   = array_merge($this->getMemorialsCollection(), $litEvents->getMemorialsCollection());

        $this->weekdaysAdventChristmasLentCollection = array_merge(
            $this->getWeekdaysAdventChristmasLentCollection(),
            $litEvents->getWeekdaysAdventChristmasLentCollection()
        );
        $this->weekdaysAdventBeforeDec17Collection   = array_merge(
            $this->getWeekdaysAdventBeforeDec17Collection(),
            $litEvents->getWeekdaysAdventBeforeDec17Collection()
        );

        $this->weekdaysEpiphanyCollection        = array_merge($this->getWeekdaysEpiphanyCollection(), $litEvents->getWeekdaysEpiphanyCollection());
        $this->solemnitiesLordBVMCollection      = array_merge($this->getSolemnitiesLordBVMCollection(), $litEvents->getSolemnitiesLordBVMCollection());
        $this->sundaysAdventLentEasterCollection = array_merge($this->getSundaysAdventLentEasterCollection(), $litEvents->getSundaysAdventLentEasterCollection());
        $this->liturgicalEventsCollection        = array_merge($this->getLiturgicalEventsCollection(), $litEvents->getLiturgicalEventsCollection());
        $this->suppressedEvents                  = array_merge($this->suppressedEvents, $litEvents->suppressedEvents);
        $this->reinstatedEvents                  = array_merge($this->reinstatedEvents, $litEvents->reinstatedEvents);
    }

    /**
     * Adds a liturgical event to the collection in an associative array format.
     *
     * The associative array contains the liturgical event key as the value for the
     * "event_key" key and all other properties of the liturgical event as additional keys.
     *
     * @param string $key The key of the liturgical event.
     * @param LiturgicalEvent $litEvent The liturgical event to add.
     * @return void
     */
    private function add(string $key, LiturgicalEvent $litEvent): void
    {
        $litEventAssocArr = [
            "event_key" => $key,
            ...json_decode(json_encode($litEvent), true)
        ];
        ksort($litEventAssocArr);
        $this->liturgicalEventsCollection[] = $litEventAssocArr;
    }
}
