<?php

namespace LiturgicalCalendar\Api;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\LitSeason;
use LiturgicalCalendar\Api\Params\CalendarParams;

#[\AllowDynamicProperties]
class FestivityCollection
{
    private array $festivities      = [];
    private array $solemnities      = [];
    private array $feasts           = [];
    private array $memorials        = [];
    private array $WeekdaysAdventChristmasLent  = [];
    private array $WeekdaysAdventBeforeDec17    = [];
    private array $WeekdaysEpiphany             = [];
    private array $SolemnitiesLordBVM           = [];
    private array $SundaysAdventLentEaster      = [];
    private array $T                            = [];
    private \IntlDateFormatter $dayOfTheWeek;
    private CalendarParams $CalendarParams;
    private LitGrade $LitGrade;
    public const SUNDAY_CYCLE              = [ "A", "B", "C" ];
    public const WEEKDAY_CYCLE             = [ "I", "II" ];

    public function __construct(CalendarParams $CalendarParams)
    {
        $this->CalendarParams = $CalendarParams;
        $this->dayOfTheWeek = \IntlDateFormatter::create(
            $this->CalendarParams->Locale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            "EEEE"
        );
        if ($this->CalendarParams->Locale === LitLocale::LATIN) {
            $this->T = [
                "YEAR"          => "ANNUM",
                "Vigil Mass"    => "Missa in Vigilia"
            ];
        } else {
            $this->T = [
                /**translators: in reference to the cycle of liturgical years (A, B, C; I, II) */
                "YEAR"          => _("YEAR"),
                "Vigil Mass"    => _("Vigil Mass")
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
     * Adds a Festivity to the collection and categorizes it based on its grade and key.
     *
     * This method organizes festivities into different categories such as solemnities,
     * feasts, memorials, weekdays of Advent, Christmas, Lent, and Epiphany, as well as
     * Sundays of Advent, Lent, and Easter. It updates the festivity's display grade and
     * psalter week as necessary.
     *
     * @param string $key The key associated with the festivity.
     * @param Festivity $festivity The festivity object to be added.
     * @return void
     */
    public function addFestivity(string $key, Festivity $festivity): void
    {
        $this->festivities[ $key ] = $festivity;
        if ($festivity->grade === LitGrade::HIGHER_SOLEMNITY) {
            $this->festivities[ $key ]->display_grade = "";
        }
        if ($festivity->grade >= LitGrade::FEAST_LORD) {
            $this->solemnities[ $key ]  = $festivity->date;
        }
        if ($festivity->grade === LitGrade::FEAST) {
            $this->feasts[ $key ]       = $festivity->date;
        }
        if ($festivity->grade === LitGrade::MEMORIAL) {
            $this->memorials[ $key ]    = $festivity->date;
        }
        // Weekday of Advent from 17 to 24 Dec.
        if (str_starts_with($key, "AdventWeekday")) {
            if ($festivity->date->format('j') >= 17 && $festivity->date->format('j') <= 24) {
                $this->WeekdaysAdventChristmasLent[ $key ] = $festivity->date;
            } else {
                $this->WeekdaysAdventBeforeDec17[ $key ] = $festivity->date;
            }
        } elseif (str_starts_with($key, "ChristmasWeekday")) {
            $this->WeekdaysAdventChristmasLent[ $key ] = $festivity->date;
        } elseif (str_starts_with($key, "LentWeekday")) {
            $this->WeekdaysAdventChristmasLent[ $key ] = $festivity->date;
        } elseif (str_starts_with($key, "DayBeforeEpiphany") || str_starts_with($key, "DayAfterEpiphany")) {
            $this->WeekdaysEpiphany[ $key ] = $festivity->date;
        }
        //Sundays of Advent, Lent, Easter
        if (preg_match('/(?:Advent|Lent|Easter)([1-7])/', $key, $matches) === 1) {
            $this->SundaysAdventLentEaster[] = $festivity->date;
            $this->festivities[ $key ]->psalter_week = self::psalterWeek(intval($matches[1]));
        }
        //Ordinary Sunday Psalter Week
        if (preg_match('/OrdSunday([1-9][0-9]*)/', $key, $matches) === 1) {
            $this->festivities[ $key ]->psalter_week = self::psalterWeek(intval($matches[1]));
        }
    }

    /**
     * Adds an array of keys to the SolemnitiesLordBVM array.
     * This is used to store the solemnities of the Lord and the BVM.
     * @param array $keys The keys to add to the array.
     * @return void
     */
    public function addSolemnitiesLordBVM(array $keys): void
    {
        array_push($this->SolemnitiesLordBVM, $keys);
    }

    /**
     * Gets a Festivity object from the collection by key.
     *
     * @param string $key The key of the festivity to retrieve.
     * @return Festivity|null The Festivity object if found, otherwise null.
     */
    public function getFestivity(string $key): ?Festivity
    {
        if (array_key_exists($key, $this->festivities)) {
            return $this->festivities[ $key ];
        }
        return null;
    }

    /**
     * Retrieves all festivities that occur on the specified date.
     *
     * This method filters the collection of festivities and returns an array
     * of those whose date matches the given DateTime object.
     *
     * @param DateTime $date The date for which to retrieve festivities.
     * @return array An array of Festivity objects occurring on the specified date.
     */
    public function getCalEventsFromDate(DateTime $date): array
    {
        return array_filter($this->festivities, function ($el) use ($date) {
            return $el->date == $date;
        });
    }

    /**
     * Checks if a given key is a solemnity of the Lord or the BVM.
     *
     * @param string $key The key to check.
     * @return bool True if the key is a solemnity of the Lord or the BVM, otherwise false.
     */
    public function isSolemnityLordBVM(string $key): bool
    {
        return in_array($key, $this->SolemnitiesLordBVM);
    }

    /**
     * Checks if a given date falls on a Sunday during the seasons of Advent, Lent, or Easter.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is a Sunday in Advent, Lent, or Easter, otherwise false.
     */
    public function isSundayAdventLentEaster(DateTime $date): bool
    {
        return in_array($date, $this->SundaysAdventLentEaster);
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
        return in_array($date, $this->WeekdaysAdventBeforeDec17);
    }

    /**
     * Checks if a given date is a weekday in the seasons of Advent (on or after December 17th), Christmas, or Lent.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is a weekday in Advent (on or after December 17th), Christmas, or Lent, otherwise false.
     */
    public function inWeekdaysAdventChristmasLent(DateTime $date): bool
    {
        return in_array($date, $this->WeekdaysAdventChristmasLent);
    }

    /**
     * Checks if a given date is a weekday in the season of Epiphany.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is a weekday in Epiphany, otherwise false.
     */
    public function inWeekdaysEpiphany(DateTime $date): bool
    {
        return in_array($date, $this->WeekdaysEpiphany);
    }

    /**
     * Checks if a given date exists in the current calculated Liturgical calendar.
     * This is useful for checking coincidences between mobile festivities.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is found in the calendar, otherwise false.
     */
    public function inCalendar(DateTime $date): bool
    {
        return count(array_filter($this->festivities, function ($el) use ($date) {
            $el->date == $date;
        })) > 0;
    }

    /**
     * Given a date, find the corresponding solemnity in the current calculated Liturgical calendar.
     * If no solemnity is found, returns null.
     *
     * @param DateTime $date The date to find the solemnity for.
     * @return Festivity|null The solemnity at the given date, or null if none exists.
     */
    public function solemnityFromDate(DateTime $date): ?Festivity
    {
        $key = array_search($date, $this->solemnities);
        if ($key && array_key_exists($key, $this->festivities)) {
            return $this->festivities[ $key ];
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
        return array_search($date, $this->WeekdaysEpiphany);
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
        return array_search($date, $this->WeekdaysAdventBeforeDec17);
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
        return array_search($date, $this->WeekdaysAdventChristmasLent);
    }

    /**
     * Given a date, find the corresponding Feast or Memorial in the current calculated Liturgical calendar.
     * If no Feast or Memorial is found, returns null.
     *
     * @param DateTime $date The date to find the Feast or Memorial for.
     * @return Festivity|null The Feast or Memorial at the given date, or null if none exists.
     */
    public function feastOrMemorialFromDate(DateTime $date): ?Festivity
    {
        $key = array_search($date, $this->feasts);
        if ($key && array_key_exists($key, $this->festivities)) {
            return $this->festivities[ $key ];
        }
        $key = array_search($date, $this->memorials);
        if ($key && array_key_exists($key, $this->festivities)) {
            return $this->festivities[ $key ];
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
     * Given a key and a new date, moves the corresponding festivity to the new date
     *
     * @param string $key The key of the festivity to move
     * @param DateTime $newDate The new date for the festivity
     * @return void
     */
    public function moveFestivityDate(string $key, DateTime $newDate): void
    {
        if (array_key_exists($key, $this->festivities)) {
            $this->festivities[ $key ]->date = $newDate;
        }
    }

    /**
     * Updates the categorization of a festivity based on its grade and previous grade.
     *
     * This method modifies the festivity's position in the solemnities, feasts, or memorials
     * collections according to its new grade. If the new grade is greater than or equal to
     * FEAST_LORD, the festivity is added to the solemnities collection and removed from
     * feasts or memorials if necessary. If the new grade is FEAST, it is moved to the feasts
     * collection from solemnities or memorials. If the new grade is MEMORIAL, it is added to
     * the memorials collection and removed from solemnities or feasts if needed.
     *
     * @param string $key The key associated with the festivity.
     * @param int $value The new grade of the festivity.
     * @param int $oldValue The previous grade of the festivity.
     * @return void
     */
    private function handleGradeProperty(string $key, int $value, int $oldValue): void
    {
        if ($value >= LitGrade::FEAST_LORD) {
            $this->solemnities[ $key ] = $this->festivities[ $key ]->date;
            if ($oldValue < LitGrade::FEAST_LORD && $this->feastOrMemorialKeyFromDate($this->festivities[ $key ]->date) === $key) {
                if ($this->inFeasts($this->festivities[ $key ]->date)) {
                    unset($this->feasts[ $key ]);
                } elseif ($this->inMemorials($this->festivities[ $key ]->date)) {
                    unset($this->memorials[ $key ]);
                }
            }
        } elseif ($value === LitGrade::FEAST) {
            $this->feasts[ $key ] = $this->festivities[ $key ]->date;
            if ($oldValue > LitGrade::FEAST) {
                unset($this->solemnities[ $key ]);
            } elseif ($oldValue === LitGrade::MEMORIAL) {
                unset($this->memorials[ $key ]);
            }
        } elseif ($value === LitGrade::MEMORIAL) {
            $this->memorials[ $key ] = $this->festivities[ $key ]->date;
            if ($oldValue > LitGrade::FEAST) {
                unset($this->solemnities[ $key ]);
            } elseif ($oldValue > LitGrade::MEMORIAL) {
                unset($this->feasts[ $key ]);
            }
        }
    }

    /**
     * Sets a property of a festivity in the collection if it exists and matches the expected type.
     *
     * Uses reflection to ensure the property exists on the Festivity object and checks the type
     * of the value against the expected type of the property. If the property is "grade",
     * it calls handleGradeProperty to update the festivity's categorization.
     *
     * @param string $key The key of the festivity to modify.
     * @param string $property The property name to be set.
     * @param string|int|bool $value The new value for the property.
     * @return bool True if the property was successfully set, otherwise false.
     */
    public function setProperty(string $key, string $property, string|int|bool $value): bool
    {
        $reflect = new \ReflectionClass(new Festivity("test", new DateTime('NOW')));
        if (array_key_exists($key, $this->festivities)) {
            $oldValue = $this->festivities[ $key ]->{$property};
            if ($reflect->hasProperty($property)) {
                if (
                    $reflect->getProperty($property)->getType() instanceof \ReflectionNamedType
                    && $reflect->getProperty($property)->getType()->getName() === get_debug_type($value)
                ) {
                    $this->festivities[ $key ]->{$property} = $value;
                } elseif (
                    $reflect->getProperty($property)->getType() instanceof \ReflectionUnionType
                    && in_array(get_debug_type($value), $reflect->getProperty($property)->getType()->getTypes())
                ) {
                    $this->festivities[ $key ]->{$property} = $value;
                }
                if ($key === "grade") {
                    $this->handleGradeProperty($key, $value, $oldValue);
                }
                return true;
            }
        }
        return false;
    }

    /**
     * Removes a festivity from the collection and all relevant categorizations.
     *
     * If the festivity is a Solemnity, Feast, or Memorial, it is removed from the
     * respective collection. The festivity is then unset from the collection.
     *
     * @param string $key The key of the festivity to remove.
     */
    public function removeFestivity(string $key): void
    {
        $date = $this->festivities[ $key ]->date;
        if ($this->inSolemnities($date) && $this->solemnityKeyFromDate($date) === $key) {
            unset($this->solemnities[ $key ]);
        }
        if ($this->inFeasts($date) && $this->feastOrMemorialKeyFromDate($date) === $key) {
            unset($this->feasts[ $key ]);
        }
        if ($this->inMemorials($date) && $this->feastOrMemorialKeyFromDate($date) === $key) {
            unset($this->memorials[ $key ]);
        }
        unset($this->festivities[ $key ]);
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
            ( $date > $this->festivities[ "BaptismLord" ]->date && $date < $this->festivities[ "AshWednesday" ]->date )
            ||
            ( $date > $this->festivities[ "Pentecost" ]->date && $date < $this->festivities[ "Advent1" ]->date )
        );
    }

    /**
     * Sets the liturgical seasons, year cycles, and vigil masses for each festivity in the collection.
     *
     * This method iterates through all festivities and assigns them to a liturgical season
     * based on their date. It also determines the liturgical year cycle for weekdays and Sundays,
     * and calculates vigil masses for festivities that qualify.
     *
     * The liturgical seasons are defined as:
     * - Advent: from Advent1 to before Christmas
     * - Christmas: from Christmas to Baptism of the Lord
     * - Lent: from Ash Wednesday to before Holy Thursday
     * - Easter Triduum: from Holy Thursday to before Easter
     * - Easter: from Easter to Pentecost
     * - Ordinary Time: any other time
     *
     * The year cycles are determined based on whether the festivity falls on a weekday or a Sunday/solemnity/feast.
     * Vigil masses are calculated for eligible festivities.
     *
     * @return void
     */
    public function setCyclesVigilsSeasons()
    {
        foreach ($this->festivities as $key => $festivity) {
            // DEFINE LITURGICAL SEASONS
            if ($festivity->date >= $this->festivities[ "Advent1" ]->date && $festivity->date < $this->festivities[ "Christmas" ]->date) {
                $this->festivities[ $key ]->liturgical_season = LitSeason::ADVENT;
            } elseif ($festivity->date >= $this->festivities[ "Christmas" ]->date || $festivity->date <= $this->festivities[ "BaptismLord" ]->date) {
                $this->festivities[ $key ]->liturgical_season = LitSeason::CHRISTMAS;
            } elseif ($festivity->date >= $this->festivities[ "AshWednesday" ]->date && $festivity->date < $this->festivities[ "HolyThurs" ]->date) {
                $this->festivities[ $key ]->liturgical_season = LitSeason::LENT;
            } elseif ($festivity->date >= $this->festivities[ "HolyThurs" ]->date && $festivity->date < $this->festivities[ "Easter" ]->date) {
                $this->festivities[ $key ]->liturgical_season = LitSeason::EASTER_TRIDUUM;
            } elseif ($festivity->date >= $this->festivities[ "Easter" ]->date && $festivity->date <= $this->festivities[ "Pentecost" ]->date) {
                $this->festivities[ $key ]->liturgical_season = LitSeason::EASTER;
            } else {
                $this->festivities[ $key ]->liturgical_season = LitSeason::ORDINARY_TIME;
            }

            // DEFINE YEAR CYCLES (except for Holy Week and Easter Octave)
            if ($festivity->date <= $this->festivities[ "PalmSun" ]->date || $festivity->date >= $this->festivities[ "Easter2" ]->date) {
                if (self::dateIsNotSunday($festivity->date) && (int)$festivity->grade === LitGrade::WEEKDAY) {
                    if ($this->inOrdinaryTime($festivity->date)) {
                        $this->festivities[ $key ]->liturgical_year = $this->T[ "YEAR" ] . " " . ( self::WEEKDAY_CYCLE[ ( $this->CalendarParams->Year - 1 ) % 2 ] );
                    }
                } elseif (self::dateIsSunday($festivity->date) || (int)$festivity->grade > LitGrade::FEAST) {
                    //if we're dealing with a Sunday or a Solemnity or a Feast of the Lord, then we calculate the Sunday/Festive Cycle
                    if ($festivity->date < $this->festivities[ "Advent1" ]->date) {
                        $this->festivities[ $key ]->liturgical_year = $this->T[ "YEAR" ] . " " . ( self::SUNDAY_CYCLE[ ( $this->CalendarParams->Year - 1 ) % 3 ] );
                    } elseif ($festivity->date >= $this->festivities[ "Advent1" ]->date) {
                        $this->festivities[ $key ]->liturgical_year = $this->T[ "YEAR" ] . " " . ( self::SUNDAY_CYCLE[ $this->CalendarParams->Year % 3 ] );
                    }
                    // DEFINE VIGIL MASSES
                    $this->calculateVigilMass($key, $festivity);
                }
            }
        }
    }

    /**
     * Determines if a given festivity can have a vigil mass.
     *
     * This function evaluates whether a festivity, identified by its key and date, is eligible for a vigil mass.
     * It excludes specific festivities and date ranges, such as 'AllSouls', 'AshWednesday', and the period
     * between Palm Sunday and Easter.
     *
     * @param Festivity|\stdClass $festivity The festivity object or a standard class instance representing the festivity.
     * @param string|null $key The key associated with the festivity, if applicable.
     * @return bool True if the festivity can have a vigil mass, false otherwise.
     */
    private function festivityCanHaveVigil(Festivity|\stdClass $festivity, ?string $key = null): bool
    {
        if ($festivity instanceof Festivity) {
            return (
                false === ( $key === 'AllSouls' )
                && false === ( $key === 'AshWednesday' )
                && false === ( $festivity->date > $this->festivities[ "PalmSun" ]->date && $festivity->date < $this->festivities[ "Easter" ]->date )
                && false === ( $festivity->date > $this->festivities[ "Easter" ]->date && $festivity->date < $this->festivities[ "Easter2" ]->date )
            );
        } elseif ($festivity instanceof \stdClass) {
            return (
                false === ( $festivity->event->date > $this->festivities[ "PalmSun" ]->date && $festivity->event->date < $this->festivities[ "Easter" ]->date )
                && false === ( $festivity->event->date > $this->festivities[ "Easter" ]->date && $festivity->event->date < $this->festivities[ "Easter2" ]->date )
            );
        }
    }

    /**
     * Creates a Vigil Mass festivity for a given festivity.
     *
     * The method creates a new festivity object with the same properties as the given festivity,
     * except for its name, which is suffixed with " Vigil Mass". The new festivity's date is set to the VigilDate.
     * The method also sets the has_vigil_mass, has_vesper_i, and has_vesper_ii properties to true for the given festivity,
     * and the is_vigil_mass and is_vigil_for properties to true and the given key, respectively, for the new festivity.
     *
     * @param string $key The key of the festivity for which a Vigil Mass is to be created.
     * @param Festivity $festivity The festivity object for which a Vigil Mass is to be created.
     * @param DateTime $VigilDate The date of the Vigil Mass.
     */
    private function createVigilMass(string $key, Festivity $festivity, DateTime $VigilDate): void
    {
        $this->festivities[ $key . "_vigil" ] = new Festivity(
            $festivity->name . " " . $this->T[ "Vigil Mass" ],
            $VigilDate,
            $festivity->color,
            $festivity->type,
            $festivity->grade,
            $festivity->common
        );
        $this->festivities[ $key ]->has_vigil_mass                   = true;
        $this->festivities[ $key ]->has_vesper_i                     = true;
        $this->festivities[ $key ]->has_vesper_ii                    = true;
        $this->festivities[ $key . "_vigil" ]->is_vigil_mass         = true;
        $this->festivities[ $key . "_vigil" ]->is_vigil_for          = $key;
        $this->festivities[ $key . "_vigil" ]->liturgical_year       = $this->festivities[ $key ]->liturgical_year;
        $this->festivities[ $key . "_vigil" ]->liturgical_season     = $this->festivities[ $key ]->liturgical_season;
        $this->festivities[ $key . "_vigil" ]->liturgical_season_lcl = $this->festivities[ $key ]->liturgical_season_lcl;
    }

    /**
     * Determines if a coinciding festivity takes precedence over a vigil festivity.
     *
     * This function evaluates whether the grade of a coinciding festivity is greater than
     * the grade of the given festivity or if the coinciding festivity is a Solemnity of
     * the Lord or the Blessed Virgin Mary, while the given festivity is not.
     *
     * @param string $key The key of the given festivity.
     * @param Festivity $festivity The festivity object for which precedence is being evaluated.
     * @param \stdClass $coincidingFestivity The coinciding festivity object.
     * @return bool True if the coinciding festivity takes precedence, false otherwise.
     */
    private function coincidingFestivityTakesPrecedenceOverVigil(string $key, Festivity $festivity, \stdClass $coincidingFestivity): bool
    {
        return (
            $festivity->grade < $coincidingFestivity->event->grade ||
            ( $this->isSolemnityLordBVM($coincidingFestivity->key) && !$this->isSolemnityLordBVM($key) )
        );
    }

    /**
     * Determines if a vigil festivity takes precedence over a coinciding festivity.
     *
     * This function evaluates whether the grade of the vigil festivity is greater than
     * the grade of the coinciding festivity or if the vigil festivity is a Solemnity of
     * the Lord or the Blessed Virgin Mary, while the coinciding festivity is not.
     *
     * @param string $key The key of the vigil festivity.
     * @param Festivity $festivity The festivity object of the vigil festivity.
     * @param \stdClass $coincidingFestivity The coinciding festivity object.
     * @return bool True if the vigil festivity takes precedence, false otherwise.
     */
    private function vigilTakesPrecedenceOverCoincidingFestivity(string $key, Festivity $festivity, \stdClass $coincidingFestivity): bool
    {
        return (
            $festivity->grade > $coincidingFestivity->event->grade ||
            ( $this->isSolemnityLordBVM($key) && !$this->isSolemnityLordBVM($coincidingFestivity->key) )
        );
    }

    /**
     * Handles the coincidence of a vigil festivity with another festivity.
     *
     * This function determines whether the vigil festivity or the coinciding festivity
     * takes precedence based on the provided parameters. It updates the festivity
     * properties and appends messages to the Messages array to reflect the outcome.
     * If the vigil festivity takes precedence, it confirms a Vigil Mass and I Vespers.
     * If the coinciding festivity takes precedence, the vigil is removed, and II Vespers
     * is confirmed for the coinciding festivity.
     *
     * @param string $key The key of the festivity being evaluated.
     * @param Festivity $festivity The festivity object being evaluated.
     * @param string $festivityGrade The grade of the festivity.
     * @param \stdClass $coincidingFestivity The coinciding festivity object.
     * @param bool|string $vigilTakesPrecedence Indicates if the vigil takes precedence or a special case string.
     * @return void
     */
    private function handleVigilFestivityCoincidence(string $key, Festivity $festivity, string $festivityGrade, \stdClass $coincidingFestivity, bool|string $vigilTakesPrecedence): void
    {
        if (gettype($vigilTakesPrecedence) === "string" && $vigilTakesPrecedence === "YEAR2022") {
            $festivity->has_vigil_mass = true;
            $festivity->has_vesper_i = true;
            $coincidingFestivity->event->has_vesper_ii = false;
            $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                _('The Vigil Mass for the %1$s \'%2$s\' coincides with the %3$s \'%4$s\' in the year %5$d. As per %6$s, the first has precedence, therefore the Vigil Mass is confirmed as are I Vespers.'),
                $festivityGrade,
                $festivity->name,
                $coincidingFestivity->grade,
                $coincidingFestivity->event->name,
                $this->CalendarParams->Year,
                '<a href="http://www.cultodivino.va/content/cultodivino/it/documenti/responsa-ad-dubia/2020/de-calendario-liturgico-2022.html">' . _("Decree of the Congregation for Divine Worship") . '</a>'
            );
        } else {
            $festivity->has_vigil_mass = $vigilTakesPrecedence;
            $festivity->has_vesper_i = $vigilTakesPrecedence;
            $coincidingFestivity->event->has_vesper_ii = !$vigilTakesPrecedence;
            if ($vigilTakesPrecedence) {
                $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                    _('The Vigil Mass for the %1$s \'%2$s\' coincides with the %3$s \'%4$s\' in the year %5$d. Since the first Solemnity has precedence, it will have Vespers I and a vigil Mass, whereas the last Solemnity will not have either Vespers II or an evening Mass.'),
                    $festivityGrade,
                    $festivity->name,
                    $coincidingFestivity->grade,
                    $coincidingFestivity->event->name,
                    $this->CalendarParams->Year
                );
            } else {
                unset($this->festivities[ $key . "_vigil" ]);
                $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                    _('The Vigil Mass for the %1$s \'%2$s\' coincides with the %3$s \'%4$s\' in the year %5$d. This last Solemnity takes precedence, therefore it will maintain Vespers II and an evening Mass, while the first Solemnity will not have a Vigil Mass or Vespers I.'),
                    $festivityGrade,
                    $festivity->name,
                    $coincidingFestivity->grade,
                    $coincidingFestivity->event->name,
                    $this->CalendarParams->Year
                );
            }
        }
    }

    /**
     * Given a festivity, calculate whether it should have a vigil or not, and eventually create the Vigil Mass festivity.
     * If the Vigil coincides with another Solemnity, make a note of it and handle it accordingly.
     *
     * @param string $key The key of the festivity.
     * @param Festivity $festivity The festivity object.
     */
    private function calculateVigilMass(string $key, Festivity $festivity)
    {
        //Not only will we create new events, we will also add metadata to existing events
        $VigilDate = clone( $festivity->date );
        $VigilDate->sub(new \DateInterval('P1D'));
        $festivityGrade = '';
        if (self::dateIsSunday($festivity->date) && $festivity->grade < LitGrade::SOLEMNITY) {
            $festivityGrade = $this->CalendarParams->Locale === LitLocale::LATIN ? 'Die Domini' : ucfirst($this->dayOfTheWeek->format($festivity->date->format('U')));
        } else {
            if ($festivity->grade > LitGrade::SOLEMNITY) {
                $festivityGrade = '<i>' . $this->LitGrade->i18n($festivity->grade, false) . '</i>';
            } else {
                $festivityGrade = $this->LitGrade->i18n($festivity->grade, false);
            }
        }

        //conditions for which the festivity SHOULD have a vigil
        if (self::dateIsSunday($festivity->date) || true === ( $festivity->grade >= LitGrade::SOLEMNITY )) {
            //filter out cases in which the festivity should NOT have a vigil
            if ($this->festivityCanHaveVigil($festivity, $key)) {
                $this->createVigilMass($key, $festivity, $VigilDate);
                //if however the Vigil coincides with another Solemnity let's make a note of it!
                if ($this->inSolemnities($VigilDate)) {
                    $coincidingFestivity = new \stdClass();
                    $coincidingFestivity->grade = '';
                    $coincidingFestivity->key = $this->solemnityKeyFromDate($VigilDate);
                    $coincidingFestivity->event = $this->festivities[ $coincidingFestivity->key ];
                    if (self::dateIsSunday($VigilDate) && $coincidingFestivity->event->grade < LitGrade::SOLEMNITY) {
                        //it's a Sunday
                        $coincidingFestivity->grade = $this->CalendarParams->Locale === LitLocale::LATIN
                            ? 'Die Domini'
                            : ucfirst($this->dayOfTheWeek->format($VigilDate->format('U')));
                    } else {
                        //it's a Feast of the Lord or a Solemnity
                        $coincidingFestivity->grade = ( $coincidingFestivity->event->grade > LitGrade::SOLEMNITY ? '<i>' . $this->LitGrade->i18n($coincidingFestivity->event->grade, false) . '</i>' : $this->LitGrade->i18n($coincidingFestivity->event->grade, false) );
                    }

                    //suppress warning messages for known situations, like the Octave of Easter
                    if ($festivity->grade !== LitGrade::HIGHER_SOLEMNITY) {
                        if ($this->coincidingFestivityTakesPrecedenceOverVigil($key, $festivity, $coincidingFestivity)) {
                            $this->handleVigilFestivityCoincidence($key, $festivity, $festivityGrade, $coincidingFestivity, false);
                        } elseif ($this->vigilTakesPrecedenceOverCoincidingFestivity($key, $festivity, $coincidingFestivity)) {
                            $this->handleVigilFestivityCoincidence($key, $festivity, $festivityGrade, $coincidingFestivity, true);
                        } elseif ($this->CalendarParams->Year === 2022 && ( $key === 'SacredHeart' || $key === 'Lent3' || $key === 'Assumption' )) {
                            $this->handleVigilFestivityCoincidence($key, $festivity, $festivityGrade, $coincidingFestivity, "YEAR2022");
                        } else {
                            $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                _('The Vigil Mass for the %1$s \'%2$s\' coincides with the %3$s \'%4$s\' in the year %5$d. We should ask the Congregation for Divine Worship what to do about this!'),
                                $festivityGrade,
                                $festivity->name,
                                $coincidingFestivity->grade,
                                $coincidingFestivity->event->name,
                                $this->CalendarParams->Year
                            );
                        }
                    } elseif ($this->festivityCanHaveVigil($coincidingFestivity, null)) {
                        $this->handleVigilFestivityCoincidence($key, $festivity, $festivityGrade, $coincidingFestivity, true);
                    }
                }
            } else {
                $this->festivities[ $key ]->has_vigil_mass = false;
                $this->festivities[ $key ]->has_vesper_i = false;
            }
        }
    }

    /**
     * Sorts the festivities by date, while maintaining their association with their keys.
     *
     * The FestivityCollection contains an associative array of Festivity objects, where the key is a string that identifies the event created (ex. ImmaculateConception).
     * In order to sort by date while preserving the key association, we use the uasort function.
     */
    public function sortFestivities(): void
    {
        uasort($this->festivities, array( "LiturgicalCalendar\Api\Festivity", "compDate" ));
    }

    /**
     * Retrieves all festivities from the collection.
     *
     * @return Festivity[] An array of Festivity objects contained in the collection.
     */
    public function getFestivities(): array
    {
        return $this->festivities;
    }

    /**
     * Retrieves all solemnities from the collection.
     *
     * @return DateTime[] An array of DateTime objects, each representing a date with a Solemnity.
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
     * @return array An array of arrays, where each inner array contains the key of the event and the properties of the DateTime object as key-value pairs.
     */
    public function getSolemnitiesCollection(): array
    {
        $solemnitiesCollection = [];
        foreach ($this->solemnities as $key => $solemnity) {
            $solemnitiesCollection[] = [
                "event_key" => $key,
                ...json_decode(json_encode($solemnity), true)
            ];
        }
        return $solemnitiesCollection;
    }

    /**
     * Retrieves the keys of all solemnities from the collection.
     *
     * @return array An array of keys, each representing a solemnity in the collection.
     */
    public function getSolemnitiesKeys(): array
    {
        return array_keys($this->solemnities);
    }

    /**
     * Retrieves all feasts from the collection.
     *
     * @return DateTime[] An array of DateTime objects, each representing a date with a Feast.
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
     * @return array An array of arrays, where each inner array contains the key of the event and the properties of the DateTime object as key-value pairs.
     */
    public function getFeastsCollection(): array
    {
        $feastsCollection = [];
        foreach ($this->feasts as $key => $feast) {
            $feastsCollection[] = [
                "event_key" => $key,
                ...json_decode(json_encode($feast), true)
            ];
        }
        return $feastsCollection;
    }

    /**
     * Retrieves the keys of all feasts from the collection.
     *
     * @return array An array of keys, each representing a feast in the collection.
     */
    public function getFeastsKeys(): array
    {
        return array_keys($this->feasts);
    }

    /**
     * Retrieves all memorials from the collection.
     *
     * @return DateTime[] An array of DateTime objects, each representing a date with a Memorial.
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
     * @return array An array of arrays, where each inner array contains the key of the event and the properties of the DateTime object as key-value pairs.
     */
    public function getMemorialsCollection(): array
    {
        $memorialsCollection = [];
        foreach ($this->memorials as $key => $memorial) {
            $memorialsCollection[] = [
                "event_key" => $key,
                ...json_decode(json_encode($memorial), true)
            ];
        }
        return $memorialsCollection;
    }

    /**
     * Retrieves the keys of all memorials from the collection.
     *
     * @return array An array of keys, each representing a memorial in the collection.
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
     * @return array An array of DateTime objects, each representing a weekday in Advent before December 17th.
     */
    public function getWeekdaysAdventBeforeDec17(): array
    {
        return $this->WeekdaysAdventBeforeDec17;
    }

    /**
     * Retrieves all weekdays in the seasons of Advent (on or after December 17th), Christmas, or Lent.
     *
     * These are days on which optional memorials can only be celebrated in partial form.
     *
     * @return DateTime[] An array of DateTime objects, each representing a weekday in Advent, Christmas, or Lent.
     */
    public function getWeekdaysAdventChristmasLent(): array
    {
        return $this->WeekdaysAdventChristmasLent;
    }

    /**
     * Retrieves all weekdays in the Epiphany season.
     *
     * @return DateTime[] An array of DateTime objects, each representing a weekday in the Epiphany season.
     */
    public function getWeekdaysEpiphany(): array
    {
        return $this->WeekdaysEpiphany;
    }

    /**
     * Retrieves all solemnities of the Lord and of the Blessed Virgin Mary.
     *
     * These are special solemnities that are higher in rank than regular solemnities.
     *
     * @return array An array of solemnities, each represented by a string key.
     */
    public function getSolemnitiesLordBVM(): array
    {
        return $this->SolemnitiesLordBVM;
    }

    /**
     * Retrieves all Sundays in the seasons of Advent, Lent, and Easter.
     *
     * @return DateTime[] An array of DateTime objects, each representing a Sunday in Advent, Lent, or Easter.
     */
    public function getSundaysAdventLentEaster(): array
    {
        return $this->SundaysAdventLentEaster;
    }

    /**
     * Retrieves all feasts and memorials from the collection.
     *
     * @return array An associative array of all feasts and memorials, each represented by a string key.
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
     * @return \stdClass An object containing the coinciding event and its grade.
     */
    public function determineSundaySolemnityOrFeast(DateTime $currentFeastDate): \stdClass
    {
        $coincidingFestivity = new \stdClass();
        $coincidingFestivity->grade = '';
        if (self::dateIsSunday($currentFeastDate) && $this->solemnityFromDate($currentFeastDate)->grade < LitGrade::SOLEMNITY) {
            //it's a Sunday
            $coincidingFestivity->event = $this->solemnityFromDate($currentFeastDate);
            $coincidingFestivity->grade = $this->CalendarParams->Locale === LitLocale::LATIN
                ? 'Die Domini'
                : ucfirst($this->dayOfTheWeek->format($currentFeastDate->format('U')));
        } elseif ($this->inSolemnities($currentFeastDate)) {
            //it's a Feast of the Lord or a Solemnity
            $coincidingFestivity->event = $this->solemnityFromDate($currentFeastDate);
            $coincidingFestivity->grade = ( $coincidingFestivity->event->grade > LitGrade::SOLEMNITY
                ? '<i>' . $this->LitGrade->i18n($coincidingFestivity->event->grade, false) . '</i>'
                : $this->LitGrade->i18n($coincidingFestivity->event->grade, false) );
        } elseif ($this->inFeastsOrMemorials($currentFeastDate)) {
            $coincidingFestivity->event = $this->feastOrMemorialFromDate($currentFeastDate);
            $coincidingFestivity->grade = $this->LitGrade->i18n($coincidingFestivity->event->grade, false);
        }
        return $coincidingFestivity;
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


    public function calculatePsalterWeek(): void
    {
        $messages = [];
        foreach ($this->festivities as $key => $value) {
            $messages[] = "Checking entry $key...";
            if (false === property_exists($value, 'psalter_week') || null === $value->psalter_week) {
                $messages[] = "***** The {$this->festivities[$key]->grade_lcl} of {$this->festivities[$key]->name} does not have a psalter_week property *****";

                if (property_exists($value, 'is_vigil_mass') && $value->is_vigil_mass) {
                    // Vigils can inherit the value from the corresponding event for which they are vigils
                    $messages[] = "!!!!! The {$this->festivities[$key]->grade_lcl} of {$this->festivities[$key]->name} is a Vigil Mass and does not have a psalter_week property !!!!!";
                    if (property_exists($this->festivities[$value->is_vigil_for], 'psalter_week') && null !== $this->festivities[$value->is_vigil_for]->psalter_week) {
                        $messages[] = "The {$this->festivities[$value->is_vigil_for]->grade_lcl} of {$this->festivities[$value->is_vigil_for]->name} for which it is a Vigil Mass DOES have a psalter_week property with value {$this->festivities[$value->is_vigil_for]->psalter_week}";
                        $this->festivities[$key]->psalter_week = $this->festivities[$value->is_vigil_for]->psalter_week;
                        $messages[] = "The psalter_week for the {$this->festivities[$key]->grade_lcl} of {$this->festivities[$key]->name} was set to {$this->festivities[$key]->psalter_week}";
                    } else {
                        $messages[] = "The {$this->festivities[$value->is_vigil_for]->grade_lcl} of {$this->festivities[$value->is_vigil_for]->name} for which it is a Vigil Mass DOES NOT have a psalter_week property, setting both to 0";
                        $this->festivities[$key]->psalter_week = 0;
                        $this->festivities[$value->is_vigil_for]->psalter_week = 0;
                    }
                } elseif ($this->festivities[$key]->grade === 1 || $this->festivities[$key]->grade === 2) {
                    // Commemorations and Optional memorials can inherit the value from a same day event
                    $messages[] = "^^^^^ The {$this->festivities[$key]->grade_lcl} of {$this->festivities[$key]->name} does not have a psalter_week property, checking in ferial events on the same day ^^^^^";
                    $ferialEventSameDay = array_values(array_filter(
                        $this->festivities,
                        fn ($item) => $item->grade === 0 && $item->date == $this->festivities[$key]->date // do NOT use strict check for date, will not work
                    ));
                    $messages[] = $ferialEventSameDay;
                    if (count($ferialEventSameDay) && property_exists($ferialEventSameDay[0], 'psalter_week') && null !== $ferialEventSameDay[0]->psalter_week) {
                        $messages[] = "Found a ferial event on the same day that has a psalter_week property with value {$ferialEventSameDay[0]->psalter_week}";
                        $this->festivities[$key]->psalter_week = $ferialEventSameDay[0]->psalter_week;
                    } else {
                        $messages[] = "No ferial event on the same day that has a psalter_week property, setting value to 0...";
                        $this->festivities[$key]->psalter_week = 0;
                    }
                    $messages[] = "The psalter_week property for the {$this->festivities[$key]->grade_lcl} of {$this->festivities[$key]->name} was set to {$this->festivities[$key]->psalter_week}";
                } else {
                    $this->festivities[$key]->psalter_week = 0;
                    $messages[] = "The psalter_week value for the {$this->festivities[$key]->grade_lcl} of {$this->festivities[$key]->name} was set to 0";
                }
            }
        }
        //die(json_encode($messages));
    }

    /**
     * Removes all festivities with a date before the First Sunday of Advent,
     * except for the Vigil Mass for the First Sunday of Advent.
     *
     * This method is used to clear out festivities that are not relevant to the
     * current liturgical year, such as festivities that occur before the First
     * Sunday of Advent.
     */
    public function purgeDataBeforeAdvent(): void
    {
        foreach ($this->festivities as $key => $festivity) {
            if ($festivity->date < $this->festivities[ "Advent1" ]->date) {
                //remove all except the Vigil Mass for the first Sunday of Advent
                if (
                    ( null === $festivity->is_vigil_mass )
                    ||
                    ( $festivity->is_vigil_mass && $festivity->is_vigil_for !== "Advent1" )
                ) {
                    unset($this->festivities[ $key ]);
                    // make sure it isn't still contained in another collection
                    unset($this->solemnities[ $key ]);
                    unset($this->feasts[ $key ]);
                    unset($this->memorials[ $key ]);
                    unset($this->WeekdaysAdventChristmasLent[ $key ]);
                    unset($this->WeekdaysEpiphany[ $key ]);
                    unset($this->SolemnitiesLordBVM[ $key ]);
                    unset($this->SundaysAdventLentEaster[ $key ]);
                }
            }
        }
    }

    /**
     * Removes all festivities with a date after the First Sunday of Advent,
     * including the Vigil Mass for the first Sunday of Advent, and the First
     * Sunday of Advent itself.
     *
     * This method is used to clear out festivities that are not relevant to the
     * current liturgical year, such as festivities that occur on or after the First
     * Sunday of Advent.
     */
    public function purgeDataAdventChristmas()
    {
        foreach ($this->festivities as $key => $festivity) {
            if ($festivity->date > $this->festivities[ "Advent1" ]->date) {
                unset($this->festivities[ $key ]);
                // make sure it isn't still contained in another collection
                unset($this->solemnities[ $key ]);
                unset($this->feasts[ $key ]);
                unset($this->memorials[ $key ]);
                unset($this->WeekdaysAdventChristmasLent[ $key ]);
                unset($this->SolemnitiesLordBVM[ $key ]);
                unset($this->SundaysAdventLentEaster[ $key ]);
            }
            // also remove the Vigil Mass for the first Sunday of Advent
            // unfortunately we cannot keep it, because it would have the same key as for the other calendar year
            if (
                null !== $festivity->is_vigil_mass
                &&
                $festivity->is_vigil_mass
                &&
                $festivity->is_vigil_for === "Advent1"
            ) {
                unset($this->festivities[ $key ]);
            }
        }
        //lastly remove First Sunday of Advent
        unset($this->festivities[ "Advent1" ]);
        unset($this->solemnities[ "Advent1" ]);
    }

    /**
     * Merges the current FestivityCollection with another FestivityCollection.
     *
     * This method merges the two collections by combining their respective
     * collections of solemnities, feasts, memorials, weekdays of Advent,
     * Christmas and Lent, weekdays of Epiphany, solemnities of Lord and BVM,
     * Sundays of Advent, Lent, and Easter, and the collection of all
     * festivities. The merged collection is then stored in the current
     * FestivityCollection.
     *
     * @param FestivityCollection $festivities The FestivityCollection to merge with the current one.
     * @return void
     */
    public function mergeFestivityCollection(FestivityCollection $festivities)
    {
        $this->solemnities  = array_merge($this->solemnities, $festivities->getSolemnities());
        $this->feasts       = array_merge($this->feasts, $festivities->getFeasts());
        $this->memorials    = array_merge($this->memorials, $festivities->getMemorials());
        $this->WeekdaysAdventChristmasLent = array_merge(
            $this->WeekdaysAdventChristmasLent,
            $festivities->getWeekdaysAdventChristmasLent()
        );
        $this->WeekdaysAdventBeforeDec17 = array_merge(
            $this->WeekdaysAdventBeforeDec17,
            $festivities->getWeekdaysAdventBeforeDec17()
        );
        $this->WeekdaysEpiphany         = array_merge($this->WeekdaysEpiphany, $festivities->getWeekdaysEpiphany());
        $this->SolemnitiesLordBVM       = array_merge($this->SolemnitiesLordBVM, $festivities->getSolemnitiesLordBVM());
        $this->SundaysAdventLentEaster  = array_merge($this->SundaysAdventLentEaster, $festivities->getSundaysAdventLentEaster());
        $this->festivities = array_merge($this->festivities, $festivities->getFestivities());
    }
}
