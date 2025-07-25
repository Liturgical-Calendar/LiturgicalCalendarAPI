<?php

namespace LiturgicalCalendar\Api\Models\Calendar;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\LitSeason;
use LiturgicalCalendar\Api\Params\CalendarParams;
use LiturgicalCalendar\Api\Map\LiturgicalEventsMap;
use LiturgicalCalendar\Api\Map\SolemnitiesLordBVMMap;
use LiturgicalCalendar\Api\Map\SolemnitiesMap;
use LiturgicalCalendar\Api\Map\FeastsLordMap;
use LiturgicalCalendar\Api\Map\FeastsMap;
use LiturgicalCalendar\Api\Map\MemorialsMap;
use LiturgicalCalendar\Api\Map\SundaysAdventMap;
use LiturgicalCalendar\Api\Map\SundaysLentMap;
use LiturgicalCalendar\Api\Map\SundaysEasterMap;
use LiturgicalCalendar\Api\Map\SundaysOrdinaryMap;
use LiturgicalCalendar\Api\Map\WeekdaysOrdinaryMap;
use LiturgicalCalendar\Api\Map\WeekdaysAdventBeforeDec17Map;
use LiturgicalCalendar\Api\Map\WeekdaysAdventMap;
use LiturgicalCalendar\Api\Map\WeekdaysChristmasMap;
use LiturgicalCalendar\Api\Map\WeekdaysLentMap;
use LiturgicalCalendar\Api\Map\WeekdaysEasterMap;
use LiturgicalCalendar\Api\Map\WeekdaysEpiphanyMap;
use LiturgicalCalendar\Api\Map\SuppressedEventsMap;
use LiturgicalCalendar\Api\Map\ReinstatedEventsMap;

/**
 * Represents the collections of liturgical events that will be output in a final liturgical calendar.
 */
final class LiturgicalEventCollection
{
    /** @var LiturgicalEventsMap<string, LiturgicalEvent> */
    private LiturgicalEventsMap $liturgicalEvents;

    /** @var SolemnitiesLordBVMMap<string, LiturgicalEvent> */
    private SolemnitiesLordBVMMap $solemnitiesLordBVM;

    /** @var SolemnitiesMap<string, LiturgicalEvent> */
    private SolemnitiesMap $solemnities;

    /** @var FeastsLordMap<string, LiturgicalEvent> */
    private FeastsLordMap $feastsLord;

    /** @var FeastsMap<string, LiturgicalEvent> */
    private FeastsMap $feasts;

    /** @var MemorialsMap<string, LiturgicalEvent> */
    private MemorialsMap $memorials;

    /** @var SundaysAdventMap<string, LiturgicalEvent> */
    private SundaysAdventMap $sundaysAdvent;

    /** @var SundaysLentMap<string, LiturgicalEvent> */
    private SundaysLentMap $sundaysLent;

    /** @var SundaysEasterMap<string, LiturgicalEvent> */
    private SundaysEasterMap $sundaysEaster;

    /** @var SundaysOrdinaryMap<string, LiturgicalEvent> */
    private SundaysOrdinaryMap $sundaysOrdinary;

    /** @var WeekdaysAdventBeforeDec17Map<string, LiturgicalEvent> */
    private WeekdaysAdventBeforeDec17Map $weekdaysAdventBeforeDec17;

    /** @var WeekdaysAdventMap<string, LiturgicalEvent> */
    private WeekdaysAdventMap $weekdaysAdvent;

    /** @var WeekdaysChristmasMap<string, LiturgicalEvent> */
    private WeekdaysChristmasMap $weekdaysChristmas;

    /** @var WeekdaysLentMap<string, LiturgicalEvent> */
    private WeekdaysLentMap $weekdaysLent;

    /** @var WeekdaysEasterMap<string, LiturgicalEvent> */
    private WeekdaysEasterMap $weekdaysEaster;

    /** @var WeekdaysOrdinaryMap<string, LiturgicalEvent> */
    private WeekdaysOrdinaryMap $weekdaysOrdinary;

    /** @var WeekdaysEpiphanyMap<string, LiturgicalEvent> */
    private WeekdaysEpiphanyMap $weekdaysEpiphany;

    /** @var SuppressedEventsMap<string, LiturgicalEvent> */
    private SuppressedEventsMap $suppressedEvents;

    /** @var ReinstatedEventsMap<string, LiturgicalEvent> */
    private ReinstatedEventsMap $reinstatedEvents;

    /** @var array<string,string> Translation map */
    private readonly array $T;

    /** @var string[] */
    private array $Messages;

    private \IntlDateFormatter $dayOfTheWeek;
    private CalendarParams $CalendarParams;

    /** @var string[] */
    public const array SUNDAY_CYCLE = [ 'A', 'B', 'C' ];

    /** @var string[] */
    public const array WEEKDAY_CYCLE = [ 'I', 'II' ];

    /** @var string[] */
    private const array SOLEMNITIES_LORD_BVM = [
        'Easter',
        'Christmas',
        'Ascension',
        'Pentecost',
        'Trinity',
        'CorpusChristi',
        'SacredHeart',
        'ChristKing',
        'MotherGod',
        'Annunciation',
        'ImmaculateConception',
        'Assumption',
        'StJoseph',
        'NativityJohnBaptist'
    ];

    /**
     * Constructs a new instance of the LiturgicalEventCollection class.
     *
     * Initializes various maps and settings required for generating
     * the liturgical calendar based on the provided CalendarParams.
     *
     * @param CalendarParams $CalendarParams The parameters used to define the context
     *                                        for the liturgical events, including locale
     *                                        and other configurations.
     */
    public function __construct(CalendarParams $CalendarParams)
    {
        $this->CalendarParams = $CalendarParams;
        $this->dayOfTheWeek   = \IntlDateFormatter::create(
            $this->CalendarParams->Locale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            'EEEE'
        );

        if ($this->CalendarParams->Locale === LitLocale::LATIN) {
            $this->T = [
                'YEAR'       => 'ANNUM',
                'Vigil Mass' => 'Missa in Vigilia'
            ];
        } else {
            $this->T = [
                /**translators: in reference to the cycle of liturgical years (A, B, C; I, II) */
                'YEAR'       => _('YEAR'),
                'Vigil Mass' => _('Vigil Mass')
            ];
        }

        $this->liturgicalEvents          = new LiturgicalEventsMap();
        $this->solemnitiesLordBVM        = new SolemnitiesLordBVMMap();
        $this->solemnities               = new SolemnitiesMap();
        $this->feastsLord                = new FeastsLordMap();
        $this->feasts                    = new FeastsMap();
        $this->memorials                 = new MemorialsMap();
        $this->sundaysAdvent             = new SundaysAdventMap();
        $this->sundaysLent               = new SundaysLentMap();
        $this->sundaysEaster             = new SundaysEasterMap();
        $this->sundaysOrdinary           = new SundaysOrdinaryMap();
        $this->weekdaysAdventBeforeDec17 = new WeekdaysAdventBeforeDec17Map();
        $this->weekdaysAdvent            = new WeekdaysAdventMap();
        $this->weekdaysChristmas         = new WeekdaysChristmasMap();
        $this->weekdaysLent              = new WeekdaysLentMap();
        $this->weekdaysEaster            = new WeekdaysEasterMap();
        $this->weekdaysOrdinary          = new WeekdaysOrdinaryMap();
        $this->weekdaysEpiphany          = new WeekdaysEpiphanyMap();
        $this->suppressedEvents          = new SuppressedEventsMap();
        $this->reinstatedEvents          = new ReinstatedEventsMap();
        $this->Messages                  = [];
    }

    /**
     * Returns true if the given DateTime object represents a Sunday.
     *
     * @param DateTime $dt
     * @return bool True if the given date is a Sunday, false otherwise.
     */
    public static function dateIsSunday(DateTime $dt): bool
    {
        return (int) $dt->format('N') === 7;
    }

    /**
     * Returns true if the given DateTime object does not represent a Sunday.
     *
     * @param DateTime $dt The date to check.
     * @return bool True if the given date is not a Sunday, false otherwise.
     */
    public static function dateIsNotSunday(DateTime $dt): bool
    {
        return (int) $dt->format('N') !== 7;
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
        $litEvent->event_key = $key;
        if ($litEvent->grade === LitGrade::HIGHER_SOLEMNITY) {
            $litEvent->grade_display = '';
        }

        if (in_array($key, self::SOLEMNITIES_LORD_BVM, true)) {
            $this->solemnitiesLordBVM->addEvent($litEvent);
        }

        if ($litEvent->grade->value >= LitGrade::SOLEMNITY->value) {
            $this->solemnities->addEvent($litEvent);
        } elseif ($litEvent->grade === LitGrade::FEAST_LORD) {
            $this->feastsLord->addEvent($litEvent);
        } elseif ($litEvent->grade === LitGrade::FEAST) {
            $this->feasts->addEvent($litEvent);
        } elseif ($litEvent->grade === LitGrade::MEMORIAL) {
            $this->memorials->addEvent($litEvent);
        }

        // Sundays of Advent, Lent, Easter
        if (preg_match('/(Advent|Lent|Easter)([1-7])/', $key, $matches) === 1) {
            $litEvent->psalter_week = self::psalterWeek(intval($matches[2]));
            if ($matches[1] === 'Advent') {
                $this->sundaysAdvent->addEvent($litEvent);
            }
            if ($matches[1] === 'Lent') {
                $this->sundaysLent->addEvent($litEvent);
            }
            if ($matches[1] === 'Easter') {
                $this->sundaysEaster->addEvent($litEvent);
            }
        }

        // Sundays of Ordinary Time (also calculate Psalter Week)
        if (preg_match('/OrdSunday([1-9][0-9]*)/', $key, $matches) === 1) {
            $litEvent->psalter_week = self::psalterWeek(intval($matches[1]));
            $this->sundaysOrdinary->addEvent($litEvent);
        }

        // Weekdays of Advent, Christmas, Lent, Epiphany, Easter, and Ordinary Time
        if (str_starts_with($key, 'AdventWeekday')) {
            if ($litEvent->date->format('j') >= 17 && $litEvent->date->format('j') <= 24) {
                // from 17 to 24 Dec.
                $this->weekdaysAdvent->addEvent($litEvent);
            } else {
                // before 17 Dec
                $this->weekdaysAdventBeforeDec17->addEvent($litEvent);
            }
        } elseif (str_starts_with($key, 'ChristmasWeekday')) {
            $this->weekdaysChristmas->addEvent($litEvent);
        } elseif (str_starts_with($key, 'LentWeekday')) {
            $this->weekdaysLent->addEvent($litEvent);
        } elseif (str_starts_with($key, 'DayBeforeEpiphany') || str_starts_with($key, 'DayAfterEpiphany')) {
            $this->weekdaysEpiphany->addEvent($litEvent);
        } elseif (str_starts_with($key, 'EasterWeekday')) {
            $this->weekdaysEaster->addEvent($litEvent);
        } elseif (str_starts_with($key, 'FirstOrdWeekday') || str_starts_with($key, 'LastOrdWeekday')) {
            $this->weekdaysOrdinary->addEvent($litEvent);
        }

        $this->liturgicalEvents->addEvent($litEvent);
    }

    /**
     * Adds an array of keys to the SolemnitiesLordBVM array.
     * This is used to store the solemnities of the Lord and the BVM.
     * @param string[] $keys The keys to add to the array.
     * @return void
     */
    public function addSolemnitiesLordBVM(array $keys): void
    {
        foreach ($keys as $key) {
            $litEvent = $this->liturgicalEvents->getEvent($key);
            if (null === $litEvent) {
                throw new \InvalidArgumentException('Invalid key: ' . $key . ' for Solemnities of the Lord and of the Blessed Virgin Mary, valid keys are: ' . implode(', ', $this->liturgicalEvents->getKeys()));
            }
            $this->solemnitiesLordBVM->addEvent($litEvent);
        }
    }

    /**
     * Adds an array of keys to the FeastsLordBVM array.
     * This is used to store the feasts of the Lord and the BVM.
     * @param string[] $keys The keys to add to the array.
     * @return void
     */
    public function addFeastsLord(array $keys): void
    {
        foreach ($keys as $key) {
            $litEvent = $this->liturgicalEvents->getEvent($key);
            if (null === $litEvent) {
                throw new \InvalidArgumentException('Invalid key: ' . $key . ' for Feasts of the Lord, valid keys are: ' . implode(', ', $this->liturgicalEvents->getKeys()));
            }
            if ($litEvent->grade === LitGrade::FEAST_LORD) {
                $this->feastsLord->addEvent($litEvent);
            } else {
                throw new \InvalidArgumentException('Trying to add a non feast: ' . $key . ' to Feasts of the Lord, liturgical event has grade: ' . LitGrade::i18n($litEvent->grade, $this->CalendarParams->Locale, false, false));
            }
        }
    }

    /**
     * Gets a LiturgicalEvent object by key.
     *
     * @param string $key The key of the liturgical event to retrieve.
     * @return LiturgicalEvent|null The LiturgicalEvent object if found, otherwise null.
     */
    public function getLiturgicalEvent(string $key): ?LiturgicalEvent
    {
        return $this->liturgicalEvents->getEvent($key);
    }

    /**
     * Retrieves all events that occur on the specified date.
     *
     * This method filters the event map and returns an array of events
     * whose date matches the given DateTime object.
     *
     * @param DateTime $date The date for which to retrieve events.
     * @return array<string,LiturgicalEvent> An array of events occurring on the specified date.
     */
    public function getCalEventsFromDate(DateTime $date): array
    {
        // important: DateTime objects cannot use strict comparison!
        return $this->liturgicalEvents->getEventsByDate($date);
    }

    /**
     * Checks if a given key is in the solemnities map for a solemnity of the Lord or the Blessed Virgin Mary.
     *
     * @param string $key The key to check.
     * @return bool True if the key is a solemnity of the Lord or the BVM, otherwise false.
     */
    public function isSolemnityLordBVM(string $key): bool
    {
        return $this->solemnitiesLordBVM->hasEvent($key);
    }

    /**
     * Checks if a given date falls on a Sunday during the seasons of Advent, Lent, or Easter.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is a Sunday in Advent, Lent, or Easter, otherwise false.
     */
    public function isSundayAdventLentEaster(DateTime $date): bool
    {
        return $this->sundaysAdvent->hasDate($date) || $this->sundaysLent->hasDate($date) || $this->sundaysEaster->hasDate($date);
    }

    /**
     * Checks if a given date is in the solemnities map.
     *
     * These include Solemnities of the Lord and of the Blessed Virgin Mary.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is a solemnity, otherwise false.
     */
    public function inSolemnities(DateTime $date): bool
    {
        return $this->solemnities->hasDate($date);
    }

    /**
     * Checks if a given date is not in the solemnities map.
     *
     * These include Solemnities of the Lord and of the Blessed Virgin Mary.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is not a solemnity, otherwise false.
     */
    public function notInSolemnities(DateTime $date): bool
    {
        return !$this->solemnities->hasDate($date);
    }

    /**
     * Checks if a given date is in the feasts of the Lord map.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is a feast of the Lord, otherwise false.
     */
    public function inFeastsLord(DateTime $date): bool
    {
        return $this->feastsLord->hasDate($date);
    }

    /**
     * Checks if a given date is not in the feasts of the Lord map.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is not a feast of the Lord, otherwise false.
     */
    public function notInFeastsLord(DateTime $date): bool
    {
        return !$this->feastsLord->hasDate($date);
    }

    /**
     * Checks if a given date is in the feasts map.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is a feast, otherwise false.
     */
    public function inFeasts(DateTime $date): bool
    {
        return $this->feasts->hasDate($date);
    }

    /**
     * Checks if a given date is not in the feasts map.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is not a feast, otherwise false.
     */
    public function notInFeasts(DateTime $date): bool
    {
        return !$this->feasts->hasDate($date);
    }

    /**
     * Checks if a given date is in either the solemnities map or in the feasts map.
     *
     * Solemnities include Solemnities of the Lord and of the Blessed Virgin Mary.
     * Feasts inlude Feasts of the Lord.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the a solemnity or a feast falls on the given date, otherwise false.
     */
    public function inSolemnitiesOrFeasts(DateTime $date): bool
    {
        return $this->inSolemnities($date) || $this->inFeastsLord($date) || $this->inFeasts($date);
    }

    /**
     * Checks if a given date is neither in the solemnities map nor in the feasts map.
     *
     * Solemnities include Solemnities of the Lord and of the Blessed Virgin Mary.
     * Feasts inlude Feasts of the Lord.
     *
     * @param DateTime $date The date to check.
     * @return bool True if no solemnity or feast falls on the given date, otherwise false.
     */
    public function notInSolemnitiesOrFeasts(DateTime $date): bool
    {
        return !$this->inSolemnitiesOrFeasts($date);
    }

    /**
     * Checks if a given date is in the memorials map.
     *
     * @param DateTime $date The date to check.
     * @return bool True if a memorial falls on the given date, otherwise false.
     */
    public function inMemorials(DateTime $date): bool
    {
        return $this->memorials->hasDate($date);
    }

    /**
     * Checks if a given date is not in the memorials map.
     *
     * @param DateTime $date The date to check.
     * @return bool True if no memorial falls on the given date, otherwise false.
     */
    public function notInMemorials(DateTime $date): bool
    {
        return !$this->memorials->hasDate($date);
    }

    /**
     * Checks if a given date is in the Feasts of the Lord map, in the feasts map or in the memorials map.
     *
     * @param DateTime $date The date to check.
     * @return bool True if a feast or a memorial falls on the given date, otherwise false.
     */
    public function inFeastsOrMemorials(DateTime $date): bool
    {
        return $this->inFeastsLord($date) || $this->inFeasts($date) || $this->inMemorials($date);
    }

    /**
     * Checks if a given date is neither in the feasts map nor in the memorials map.
     *
     * @param DateTime $date The date to check.
     * @return bool True if no feast or memorial falls on the given date, otherwise false.
     */
    public function notInFeastsOrMemorials(DateTime $date): bool
    {
        return !$this->inFeastsOrMemorials($date);
    }

    /**
     * Checks if a given date is either in the solemnities map, or in the feasts map, or in the memorials map.
     *
     * Solemnities include Solemnities of the Lord and of the Blessed Virgin Mary.
     * Feasts inlude Feasts of the Lord.
     *
     * @param DateTime $date The date to check.
     * @return bool True if a solemnity, feast, or memorial falls on the given date, otherwise false.
     */
    public function inSolemnitiesFeastsOrMemorials(DateTime $date): bool
    {
        return $this->inSolemnities($date) || $this->inFeastsOrMemorials($date);
    }

    /**
     * Checks if a given date is neither in the solemnities map, nor in the feasts map, nor in the memorials map, i.e., it is not a solemnity, feast, or memorial.
     *
     * @param DateTime $date The date to check.
     * @return bool True if no solemnity, feast, or memorial falls on the given date, otherwise false.
     */
    public function notInSolemnitiesFeastsOrMemorials(DateTime $date): bool
    {
        return !$this->inSolemnitiesFeastsOrMemorials($date);
    }

    /**
     * Checks if a given date is in the map of weekdays in the season of Advent before December 17th.
     *
     * @param DateTime $date The date to check.
     * @return bool True if a weekday in Advent before December 17th falls on the given date, otherwise false.
     */
    public function inWeekdaysAdventBeforeDec17(DateTime $date): bool
    {
        return $this->weekdaysAdventBeforeDec17->hasDate($date);
    }

    /**
     * Checks if a given date is in the map of weekdays in the seasons of Advent (on or after December 17th), Christmas, or Lent.
     *
     * @param DateTime $date The date to check.
     * @return bool True if a weekday in Advent (on or after December 17th), or a weekday of Christmas, or a weekday of Lent falls on the given date, otherwise false.
     */
    public function inWeekdaysAdventChristmasLent(DateTime $date): bool
    {
        return $this->weekdaysAdvent->hasDate($date) || $this->weekdaysChristmas->hasDate($date) || $this->weekdaysLent->hasDate($date);
    }

    /**
     * Checks if a given date is in the map of weekdays in the season of Epiphany.
     *
     * @param DateTime $date The date to check.
     * @return bool True if a weekday in Epiphany falls on the given date, otherwise false.
     */
    public function inWeekdaysEpiphany(DateTime $date): bool
    {
        return $this->weekdaysEpiphany->hasDate($date);
    }

    /**
     * Checks if a given date exists in the map of all liturgical events in the current calculated Liturgical calendar.
     * This is useful for checking coincidences between mobile liturgical events.
     *
     * @param DateTime $date The date to check.
     * @return bool True if the date is found in the calendar, otherwise false.
     */
    public function inCalendar(DateTime $date): bool
    {
        // important: DateTime objects cannot use strict comparison!
        return $this->liturgicalEvents->hasDate($date);
    }

    /**
     * Given a date, find the corresponding solemnity in the current calculated Liturgical calendar.
     * Solemnities include Solemnities of the Lord and of the Blessed Virgin Mary.
     * If no solemnity is found, returns null.
     *
     * @param DateTime $date The date to find the solemnity for.
     * @return LiturgicalEvent|null The solemnity at the given date, or null if none exists.
     */
    public function solemnityFromDate(DateTime $date): ?LiturgicalEvent
    {
        return $this->solemnities->getEventByDate($date);
    }

    /**
     * Given a date, find the corresponding Feast of the Lord in the current calculated Liturgical calendar.
     * If no Feast of the Lord is found, returns null.
     *
     * @param DateTime $date The date to find the feast of the Lord for.
     * @return LiturgicalEvent|null The feast of the Lord at the given date, or null if none exists.
     */
    public function feastLordFromDate(DateTime $date): ?LiturgicalEvent
    {
        return $this->feastsLord->getEventByDate($date);
    }

    /**
     * Given a date, find the corresponding Feast in the current calculated Liturgical calendar.
     * If no Feast is found, returns null.
     *
     * @param DateTime $date The date to find the feast for.
     * @return LiturgicalEvent|null The feast at the given date, or null if none exists.
     */
    public function feastFromDate(DateTime $date): ?LiturgicalEvent
    {
        return $this->feasts->getEventByDate($date);
    }

    /**
     * Given a date, find the corresponding key for the solemnity in the current calculated Liturgical calendar.
     * If no solemnity is found, returns null.
     *
     * @param DateTime $date The date for which to find the key for the solemnity.
     * @return string|null The key for the solemnity at the given date, or null if none exists.
     */
    public function solemnityKeyFromDate(DateTime $date): ?string
    {
        return $this->solemnities->getEventKeyByDate($date);
    }

    /**
     * Given a date, find the corresponding key for the weekday in the Epiphany season in the current calculated Liturgical calendar.
     * If no weekday in the Epiphany season is found, returns null.
     *
     * @param DateTime $date The date for which to find the key for the weekday in the Epiphany season.
     * @return string|null The key for the weekday in the Epiphany season key at the given date, or null if none exists.
     */
    public function weekdayEpiphanyKeyFromDate(DateTime $date): ?string
    {
        return $this->weekdaysEpiphany->getEventKeyByDate($date);
    }

    /**
     * Given a date, find the corresponding key for the weekday in the Advent season before December 17th in the current calculated Liturgical calendar.
     * If no weekday in the Advent season before December 17th is found, returns null.
     *
     * @param DateTime $date The date for which to find the key for the weekday in the Advent season before December 17th.
     * @return string|null The key for the weekday in the Advent season before December 17th at the given date, or null if none exists.
     */
    public function weekdayAdventBeforeDec17KeyFromDate(DateTime $date): ?string
    {
        return $this->weekdaysAdventBeforeDec17->getEventKeyByDate($date);
    }

    /**
     * Given a date, find the corresponding key for the weekday in the Advent (on or after December 17th), Christmas, or Lent season in the current calculated Liturgical calendar.
     * If no weekday in the Advent, Christmas, or Lent season is found, returns null.
     *
     * @param DateTime $date The date for which to find the key for the weekday in the Advent (on or after December 17th), Christmas, or Lent season.
     * @return string|null The key for the weekday in the Advent (on or after December 17th), Christmas, or Lent season at the given date, or null if none exists.
     */
    public function weekdayAdventChristmasLentKeyFromDate(DateTime $date): ?string
    {
        return $this->weekdaysAdvent->getEventKeyByDate($date) ?? $this->weekdaysChristmas->getEventKeyByDate($date) ?? $this->weekdaysLent->getEventKeyByDate($date);
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
        return $this->feastsLord->getEventByDate($date) ?? $this->feasts->getEventByDate($date) ?? $this->memorials->getEventByDate($date);
    }

    /**
     * Given a date, find the corresponding key for the Feast or Memorial in the current calculated Liturgical calendar.
     * If no Feast or Memorial is found, returns null.
     *
     * @param DateTime $date The date for which to find the key for the Feast or Memorial.
     * @return string|null The key for the Feast or Memorial at the given date, or null if none exists.
     */
    public function feastOrMemorialKeyFromDate(DateTime $date): ?string
    {
        return $this->feastsLord->getEventKeyByDate($date) ?? $this->feasts->getEventKeyByDate($date) ?? $this->memorials->getEventKeyByDate($date);
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
        $this->liturgicalEvents->moveEventDateByKey($key, $newDate);
    }

    /**
     * Updates the categorization of a liturgical event based on its grade and previous grade,
     * and updates the grade_lcl and grade_abbr properties.
     *
     * This method modifies the liturgical event's position in the solemnities, feasts, or memorials
     * collections according to its new grade. If the new grade is greater than or equal to
     * FEAST_LORD, the liturgical event is added to the solemnities collection and removed from
     * feasts or memorials if necessary. If the new grade is FEAST, it is moved to the feasts
     * collection from solemnities or memorials. If the new grade is MEMORIAL, it is added to
     * the memorials collection and removed from solemnities or feasts if needed.
     *
     * @param string $key The key associated with the liturgical event.
     * @param LitGrade $newGradeValue The new grade of the liturgical event.
     * @param LitGrade $oldGradeValue The previous grade of the liturgical event.
     * @return void //$this->CalendarParams->Locale
     */
    private function handleGradeProperty(string $key, LitGrade $newGradeValue, LitGrade $oldGradeValue): void
    {
        $litEvent = $this->liturgicalEvents->getEvent($key);
        // Update the grade_lcl and grade_abbr properties
        $litEvent->setGradeLocalization(LitGrade::i18n($newGradeValue, $this->CalendarParams->Locale, false, false));
        $litEvent->setGradeAbbreviation(LitGrade::i18n($newGradeValue, $this->CalendarParams->Locale, false, true));

        if ($newGradeValue->value >= LitGrade::FEAST_LORD->value) {
            $this->solemnities->addEvent($litEvent);
            if ($oldGradeValue->value < LitGrade::FEAST_LORD->value && $this->feastOrMemorialKeyFromDate($litEvent->date) === $key) {
                if ($this->feasts->hasKey($key)) {
                    $this->feasts->removeEvent($key);
                } elseif ($this->memorials->hasKey($key)) {
                    $this->memorials->removeEvent($key);
                }
            }
        } elseif ($newGradeValue === LitGrade::FEAST) {
            $this->feasts->addEvent($litEvent);
            if ($oldGradeValue->value > LitGrade::FEAST->value) {
                $this->solemnities->removeEvent($key);
            } elseif ($oldGradeValue === LitGrade::MEMORIAL) {
                $this->memorials->removeEvent($key);
            }
        } elseif ($newGradeValue === LitGrade::MEMORIAL) {
            $this->memorials->addEvent($litEvent);
            if ($oldGradeValue->value > LitGrade::FEAST->value) {
                $this->solemnities->removeEvent($key);
            } elseif ($oldGradeValue->value > LitGrade::MEMORIAL->value) {
                $this->feasts->removeEvent($key);
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
     * @param string|int|bool $newValue The new value for the property.
     * @return bool True if the property was successfully set, otherwise false.
     */
    public function setProperty(string $key, string $property, string|int|bool|LitGrade $newValue): bool
    {
        $reflect = new \ReflectionClass(new LiturgicalEvent('test', new DateTime('NOW')));
        if ($this->liturgicalEvents->hasKey($key)) {
            $litEvent = $this->liturgicalEvents->getEvent($key);
            if ($reflect->hasProperty($property)) {
                $oldValue            = $litEvent->{$property};
                $reflectProperty     = $reflect->getProperty($property);
                $reflectPropertyType = $reflectProperty->getType();

                if (false === $reflectProperty->isPublic()) {
                    throw new \InvalidArgumentException(__METHOD__ . ": property `{$property}` is not public.");
                }

                $namedTypeCondition = (
                    $reflectPropertyType instanceof \ReflectionNamedType
                    && $reflectPropertyType->getName() === get_debug_type($newValue)
                );
                $unionTypeCondition = (
                    $reflectPropertyType instanceof \ReflectionUnionType
                    && in_array(get_debug_type($newValue), $reflectPropertyType->getTypes())
                );

                if (
                    ( $namedTypeCondition || $unionTypeCondition )
                    && $litEvent->{$property} !== $newValue
                ) {
                    $litEvent->{$property} = $newValue;
                } else {
                    return false;
                }

                // If the value being updated is the grade, update the liturgical event's categorization
                if ($property === 'grade') {
                    /**
                     * @var LitGrade $newValue
                     * @var LitGrade $oldValue
                     */
                    $this->handleGradeProperty($key, $newValue, $oldValue);
                }
                return true;
            } else {
                throw new \InvalidArgumentException(__METHOD__ . ": property `{$property}` does not exist on the LiturgicalEvent object.");
            }
        }
        return false;
    }

    /**
     * Removes a liturgical event from the collection and all relevant categorizations.
     *
     * If the liturgical event is a Solemnity, Feast, or Memorial, it is removed from the
     * respective map. The liturgical event is then unset from the collection,
     * after being added to the suppressedEvents map.
     *
     * @param string $key The key of the liturgical event to remove.
     */
    public function removeLiturgicalEvent(string $key): void
    {
        if ($this->solemnities->hasKey($key)) {
            $this->solemnities->removeEvent($key);
        }
        if ($this->feasts->hasKey($key)) {
            $this->feasts->removeEvent($key);
        }
        if ($this->memorials->hasKey($key)) {
            $this->memorials->removeEvent($key);
        }
        $this->suppressedEvents->addEvent($this->liturgicalEvents->getEvent($key));
        $this->liturgicalEvents->removeEvent($key);
    }

    /**
     * Adds a LiturgicalEvent to the collection of suppressed events.
     *
     * This method does not perform any checks and simply adds the liturgical event to the
     * suppressedEvents collection with the given key.
     *
     * @param LiturgicalEvent $litEvent The liturgical event to be added.
     * @return void
     */
    public function addSuppressedEvent(LiturgicalEvent $litEvent): void
    {
        $this->suppressedEvents->addEvent($litEvent);
    }

    /**
     * Checks if a given key is associated with a suppressed event.
     *
     * @param string $key The key to check.
     * @return bool True if the key is associated with a suppressed event, otherwise false.
     */
    public function isSuppressed(string $key): bool
    {
        return $this->suppressedEvents->hasKey($key);
    }

    /**
     * Retrieves a suppressed event by its key.
     *
     * @param string $key The key of the suppressed event.
     * @return LiturgicalEvent The suppressed event.
     */
    public function getSuppressedEventByKey(string $key): ?LiturgicalEvent
    {
        return $this->suppressedEvents->getEvent($key);
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
        $this->reinstatedEvents->addEvent($this->suppressedEvents->getEvent($key));
        $this->suppressedEvents->removeEvent($key);
    }

    /**
     * Retrieves the keys of all suppressed events.
     * @return string[] An array of event keys, each representing a suppressed event.
     */
    public function getSuppressedKeys(): array
    {
        return $this->suppressedEvents->getKeys();
    }

    /**
     * Retrieves an array of suppressed events.
     *
     * The array contains LiturgicalEvent objects that were previously in the collection
     * but have been removed. The keys of the array are the event keys of the
     * suppressed events.
     *
     * @return SuppressedEventsMap<string, LiturgicalEvent> The map of suppressed events, where each event key maps to a LiturgicalEvent object.
     */
    public function getSuppressedEvents(): SuppressedEventsMap
    {
        return $this->suppressedEvents;
    }

    /**
     * Retrieves the keys of all reinstated events.
     *
     * @return string[] An array of event keys, each representing a reinstated event.
     */
    public function getReinstatedKeys(): array
    {
        return $this->reinstatedEvents->getKeys();
    }

    /**
     * Retrieves an array of reinstated events.
     *
     * The array contains LiturgicalEvent objects that were previously suppressed
     * and have been moved back into the collection as reinstated events.
     *
     * @return ReinstatedEventsMap<string, LiturgicalEvent> A map of reinstated events, where each event key maps to a LiturgicalEvent object.
     */
    public function getReinstatedEvents(): ReinstatedEventsMap
    {
        return $this->reinstatedEvents;
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
            ( $date > $this->liturgicalEvents->getEvent('BaptismLord')->date && $date < $this->liturgicalEvents->getEvent('AshWednesday')->date )
            ||
            ( $date > $this->liturgicalEvents->getEvent('Pentecost')->date && $date < $this->liturgicalEvents->getEvent('Advent1')->date )
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
        foreach ($this->liturgicalEvents as $litEvent) {
            if ($litEvent->date >= $this->liturgicalEvents->getEvent('Advent1')->date && $litEvent->date < $this->liturgicalEvents->getEvent('Christmas')->date) {
                $litEvent->liturgical_season = LitSeason::ADVENT;
            } elseif ($litEvent->date >= $this->liturgicalEvents->getEvent('Christmas')->date || $litEvent->date <= $this->liturgicalEvents->getEvent('BaptismLord')->date) {
                $litEvent->liturgical_season = LitSeason::CHRISTMAS;
            } elseif ($litEvent->date >= $this->liturgicalEvents->getEvent('AshWednesday')->date && $litEvent->date < $this->liturgicalEvents->getEvent('HolyThurs')->date) {
                $litEvent->liturgical_season = LitSeason::LENT;
            } elseif ($litEvent->date >= $this->liturgicalEvents->getEvent('HolyThurs')->date && $litEvent->date < $this->liturgicalEvents->getEvent('Easter')->date) {
                $litEvent->liturgical_season = LitSeason::EASTER_TRIDUUM;
            } elseif ($litEvent->date >= $this->liturgicalEvents->getEvent('Easter')->date && $litEvent->date <= $this->liturgicalEvents->getEvent('Pentecost')->date) {
                $litEvent->liturgical_season = LitSeason::EASTER;
            } else {
                $litEvent->liturgical_season = LitSeason::ORDINARY_TIME;
            }
        }

        // DEFINE YEAR CYCLES (except for Holy Week and Easter Octave) and VIGIL MASSES
        // This has to be a separate cycle, because in order to correctly create Vigil Masses, we need to have already set the liturgical seasons
        foreach ($this->liturgicalEvents as $litEvent) {
            if ($litEvent->date <= $this->liturgicalEvents->getEvent('PalmSun')->date || $litEvent->date >= $this->liturgicalEvents->getEvent('Easter2')->date) {
                if (self::dateIsNotSunday($litEvent->date) && $litEvent->grade === LitGrade::WEEKDAY) {
                    if ($this->inOrdinaryTime($litEvent->date)) {
                        $litEvent->liturgical_year = $this->T['YEAR'] . ' ' . ( self::WEEKDAY_CYCLE[( $this->CalendarParams->Year - 1 ) % 2] );
                    }
                } elseif (self::dateIsSunday($litEvent->date) || $litEvent->grade->value > LitGrade::FEAST->value) {
                    //if we're dealing with a Sunday or a Solemnity or a Feast of the Lord, then we calculate the Sunday/Festive Cycle
                    if ($litEvent->date < $this->liturgicalEvents->getEvent('Advent1')->date) {
                        $litEvent->liturgical_year = $this->T['YEAR'] . ' ' . ( self::SUNDAY_CYCLE[( $this->CalendarParams->Year - 1 ) % 3] );
                    } elseif ($litEvent->date >= $this->liturgicalEvents->getEvent('Advent1')->date) {
                        $litEvent->liturgical_year = $this->T['YEAR'] . ' ' . ( self::SUNDAY_CYCLE[$this->CalendarParams->Year % 3] );
                    }

                    // DEFINE VIGIL MASSES within the same cycle, to avoid having to create/run yet another cycle
                    $this->calculateVigilMass($litEvent);
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
     * @return bool True if the liturgical event can have a vigil mass, false otherwise.
     */
    private function liturgicalEventCanHaveVigil(LiturgicalEvent|\stdClass $litEvent): bool
    {
        if ($litEvent instanceof LiturgicalEvent) {
            return (
                false === ( $litEvent->event_key === 'AllSouls' )
                && false === ( $litEvent->event_key === 'AshWednesday' )
                && false === ( $litEvent->date > $this->liturgicalEvents->getEvent('PalmSun')->date && $litEvent->date < $this->liturgicalEvents->getEvent('Easter')->date )
                && false === ( $litEvent->date > $this->liturgicalEvents->getEvent('Easter')->date && $litEvent->date < $this->liturgicalEvents->getEvent('Easter2')->date )
            );
        }
        else {
            return (
                false === ( $litEvent->event->date > $this->liturgicalEvents->getEvent('PalmSun')->date && $litEvent->event->date < $this->liturgicalEvents->getEvent('Easter')->date )
                && false === ( $litEvent->event->date > $this->liturgicalEvents->getEvent('Easter')->date && $litEvent->event->date < $this->liturgicalEvents->getEvent('Easter2')->date )
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
     * @param LiturgicalEvent $eventForWhichIsVigilMass The LiturgicalEvent object for which a Vigil Mass is to be created.
     * @param DateTime $VigilDate The date of the Vigil Mass.
     */
    private function createVigilMassFor(LiturgicalEvent $eventForWhichIsVigilMass, DateTime $VigilDate): void
    {
        $key = $eventForWhichIsVigilMass->event_key;

        $litEvent                    = new LiturgicalEvent(
            $eventForWhichIsVigilMass->name . ' ' . $this->T['Vigil Mass'],
            $VigilDate,
            $eventForWhichIsVigilMass->color,
            $eventForWhichIsVigilMass->type,
            $eventForWhichIsVigilMass->grade,
            $eventForWhichIsVigilMass->common,
            $eventForWhichIsVigilMass->grade_display
        );
        $litEvent->event_key         = $key . '_vigil';
        $litEvent->is_vigil_mass     = true;
        $litEvent->is_vigil_for      = $key;
        $litEvent->liturgical_year   = $eventForWhichIsVigilMass->liturgical_year;
        $litEvent->liturgical_season = $eventForWhichIsVigilMass->liturgical_season;
        $this->liturgicalEvents->addEvent($litEvent);

        $eventForWhichIsVigilMass->has_vigil_mass = true;
        $eventForWhichIsVigilMass->has_vesper_i   = true;
        $eventForWhichIsVigilMass->has_vesper_ii  = true;
    }

    /**
     * Determines if a coinciding event takes precedence over a vigil event.
     *
     * This function evaluates whether the grade of a coinciding event is greater than
     * the grade of the given liturgical event or if the coinciding event is a Solemnity of
     * the Lord or the Blessed Virgin Mary, while the given liturgical event is not.
     *
     * @param LiturgicalEvent $litEvent The LiturgicalEvent object for which precedence is being evaluated.
     * @param \stdClass $coincidingEvent The coinciding liturgical event object.
     * @return bool True if the coinciding event takes precedence, false otherwise.
     */
    private function coincidingLiturgicalEventTakesPrecedenceOverVigil(LiturgicalEvent $litEvent, \stdClass $coincidingEvent): bool
    {
        return (
            $litEvent->grade < $coincidingEvent->event->grade ||
            ( $this->isSolemnityLordBVM($coincidingEvent->key) && !$this->isSolemnityLordBVM($litEvent->event_key) )
        );
    }

    /**
     * Determines if a vigil event takes precedence over a coinciding event.
     *
     * This function evaluates whether the grade of the vigil event is greater than
     * the grade of the coinciding event or if the vigil event is a Solemnity of
     * the Lord or the Blessed Virgin Mary, while the coinciding event is not.
     *
     * @param LiturgicalEvent $litEvent The LiturgicalEvent object of the vigil event.
     * @param \stdClass $coincidingEvent The coinciding event object.
     * @return bool True if the vigil event takes precedence, false otherwise.
     */
    private function vigilTakesPrecedenceOverCoincidingLiturgicalEvent(LiturgicalEvent $litEvent, \stdClass $coincidingEvent): bool
    {
        return (
            $litEvent->grade > $coincidingEvent->event->grade ||
            ( $this->isSolemnityLordBVM($litEvent->event_key) && !$this->isSolemnityLordBVM($coincidingEvent->key) )
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
     * @param LiturgicalEvent $litEvent The LiturgicalEvent object being evaluated.
     * @param string $litEventGradeLcl The grade of the liturgical event.
     * @param \stdClass $coincidingEvent The coinciding liturgical event object.
     * @param bool|string $vigilTakesPrecedence Indicates if the vigil takes precedence or a special case string.
     * @return void
     */
    private function handleVigilLiturgicalEventCoincidence(LiturgicalEvent $litEvent, string $litEventGradeLcl, \stdClass $coincidingEvent, bool|string $vigilTakesPrecedence): void
    {
        if (gettype($vigilTakesPrecedence) === 'string') {
            if ($vigilTakesPrecedence === 'YEAR2022') {
                $litEvent->has_vigil_mass              = true;
                $litEvent->has_vesper_i                = true;
                $coincidingEvent->event->has_vesper_ii = false;

                $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                    _('The Vigil Mass for the %1$s \'%2$s\' coincides with the %3$s \'%4$s\' in the year %5$d. As per %6$s, the first has precedence, therefore the Vigil Mass is confirmed as are I Vespers.'),
                    $litEventGradeLcl,
                    $litEvent->name,
                    $coincidingEvent->grade_lcl,
                    $coincidingEvent->event->name,
                    $this->CalendarParams->Year,
                    '<a href="http://www.cultodivino.va/content/cultodivino/it/documenti/responsa-ad-dubia/2020/de-calendario-liturgico-2022.html" target="_blank">' . _('Decree of the Congregation for Divine Worship') . '</a>'
                );
            }
        } else {
            $litEvent->has_vigil_mass              = $vigilTakesPrecedence;
            $litEvent->has_vesper_i                = $vigilTakesPrecedence;
            $coincidingEvent->event->has_vesper_ii = !$vigilTakesPrecedence;
            if ($vigilTakesPrecedence) {
                $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                    _('The Vigil Mass for the %1$s \'%2$s\' coincides with the %3$s \'%4$s\' in the year %5$d. Since the first Solemnity has precedence, it will have Vespers I and a vigil Mass, whereas the last Solemnity will not have either Vespers II or an evening Mass.'),
                    $litEventGradeLcl,
                    $litEvent->name,
                    $coincidingEvent->grade_lcl,
                    $coincidingEvent->event->name,
                    $this->CalendarParams->Year
                );
            } else {
                $this->liturgicalEvents->removeEvent($litEvent->event_key . '_vigil');
                $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                    _('The Vigil Mass for the %1$s \'%2$s\' coincides with the %3$s \'%4$s\' in the year %5$d. This last Solemnity takes precedence, therefore it will maintain Vespers II and an evening Mass, while the first Solemnity will not have a Vigil Mass or Vespers I.'),
                    $litEventGradeLcl,
                    $litEvent->name,
                    $coincidingEvent->grade_lcl,
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
     * @param LiturgicalEvent $litEvent The liturgical event object.
     */
    private function calculateVigilMass(LiturgicalEvent $litEvent): void
    {
        $key = $litEvent->event_key;
        //Not only will we create new events, we will also add metadata to existing events
        $VigilDate = clone( $litEvent->date );
        $VigilDate->sub(new \DateInterval('P1D'));
        $litEventGradeLcl = '';
        if (self::dateIsSunday($litEvent->date) && $litEvent->grade->value < LitGrade::SOLEMNITY->value) {
            $dayOfTheWeek     = $this->dayOfTheWeek->format($litEvent->date->format('U')) ?: 'N/A';
            $litEventGradeLcl = $this->CalendarParams->Locale === LitLocale::LATIN ? 'Die Domini' : ucfirst($dayOfTheWeek);
        } else {
            if ($litEvent->grade->value > LitGrade::SOLEMNITY->value) {
                $litEventGradeLcl = '<i>' . LitGrade::i18n($litEvent->grade, $this->CalendarParams->Locale, false) . '</i>';
            } else {
                $litEventGradeLcl = LitGrade::i18n($litEvent->grade, $this->CalendarParams->Locale, false);
            }
        }

        //conditions for which the liturgical event SHOULD have a vigil
        if (self::dateIsSunday($litEvent->date) || $litEvent->grade->value >= LitGrade::SOLEMNITY->value) {
            //filter out cases in which the liturgical event should NOT have a vigil
            if ($this->liturgicalEventCanHaveVigil($litEvent)) {
                $this->createVigilMassFor($litEvent, $VigilDate);
                //if however the Vigil coincides with another Solemnity let's make a note of it!
                if ($this->inSolemnities($VigilDate)) {
                    $coincidingEvent            = new \stdClass();
                    $coincidingEvent->grade_lcl = '';
                    $coincidingEvent->key       = $this->solemnityKeyFromDate($VigilDate);
                    $coincidingEvent->event     = $this->liturgicalEvents->getEvent($coincidingEvent->key);
                    if (self::dateIsSunday($VigilDate) && $coincidingEvent->event->grade->value < LitGrade::SOLEMNITY->value) {
                        //it's a Sunday
                        $dayOfTheWeek               = $this->dayOfTheWeek->format($VigilDate->format('U')) ?: 'N/A';
                        $coincidingEvent->grade_lcl = $this->CalendarParams->Locale === LitLocale::LATIN
                            ? 'Die Domini'
                            : ucfirst($dayOfTheWeek);
                    } else {
                        //it's a Feast of the Lord or a Solemnity
                        $coincidingEvent->grade_lcl = (
                            $coincidingEvent->event->grade->value > LitGrade::SOLEMNITY->value
                                ? '<i>' . LitGrade::i18n($coincidingEvent->event->grade, $this->CalendarParams->Locale, false) . '</i>'
                                : LitGrade::i18n($coincidingEvent->event->grade, $this->CalendarParams->Locale, false)
                        );
                    }

                    //suppress warning messages for known situations, like the Octave of Easter
                    if ($litEvent->grade !== LitGrade::HIGHER_SOLEMNITY) {
                        if ($this->coincidingLiturgicalEventTakesPrecedenceOverVigil($litEvent, $coincidingEvent)) {
                            $this->handleVigilLiturgicalEventCoincidence($litEvent, $litEventGradeLcl, $coincidingEvent, false);
                        } elseif ($this->vigilTakesPrecedenceOverCoincidingLiturgicalEvent($litEvent, $coincidingEvent)) {
                            $this->handleVigilLiturgicalEventCoincidence($litEvent, $litEventGradeLcl, $coincidingEvent, true);
                        } elseif ($this->CalendarParams->Year === 2022 && ( $key === 'SacredHeart' || $key === 'Lent3' || $key === 'Assumption' )) {
                            $this->handleVigilLiturgicalEventCoincidence($litEvent, $litEventGradeLcl, $coincidingEvent, 'YEAR2022');
                        } else {
                            $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                _('The Vigil Mass for the %1$s \'%2$s\' coincides with the %3$s \'%4$s\' in the year %5$d. We should ask the Congregation for Divine Worship what to do about this!'),
                                $litEventGradeLcl,
                                $litEvent->name,
                                $coincidingEvent->grade_lcl,
                                $coincidingEvent->event->name,
                                $this->CalendarParams->Year
                            );
                        }
                    } elseif ($this->liturgicalEventCanHaveVigil($coincidingEvent)) {
                        $this->handleVigilLiturgicalEventCoincidence($litEvent, $litEventGradeLcl, $coincidingEvent, true);
                    }
                }
            } else {
                $litEvent->has_vigil_mass = false;
                $litEvent->has_vesper_i   = false;
            }
        }
    }

    /**
     * Sorts the liturgical events by date and by liturgical grade, while maintaining their association with their keys.
     *
     * The LiturgicalEventsMap contains an associative array of liturgical event keys to LiturgicalEvent objects.
     * In order to sort by date (and by liturgical grade) while preserving the key association, the LiturgicalEventsMap implements the uasort function.
     */
    public function sortLiturgicalEvents(): void
    {
        $this->liturgicalEvents->sort();
    }

    /**
     * Retrieves all liturgical events from the LiturgicalEventsMap.
     *
     * @return LiturgicalEventsMap<string, LiturgicalEvent> A map of liturgical event keys to their corresponding LiturgicalEvent objects.
     */
    public function getLiturgicalEvents(): LiturgicalEventsMap
    {
        return $this->liturgicalEvents;
    }

    /**
     * Retrieves all solemnities of the Lord and of the Blessed Virgin Mary from the SolemnitiesLordBVMMap.
     *
     * @return SolemnitiesLordBVMMap<string, LiturgicalEvent> A map of solemnity keys to their corresponding LiturgicalEvent objects.
     */
    public function getSolemnitiesLordBVM(): SolemnitiesLordBVMMap
    {
        return $this->solemnitiesLordBVM;
    }

    /**
     * Retrieves the keys of all solemnities of the Lord and the Blessed Virgin Mary.
     *
     * @return string[] An array of keys, each representing a solemnity of the Lord or the BVM in the collection.
     */
    public function getSolemnitiesLordBVMKeys(): array
    {
        return $this->solemnitiesLordBVM->getKeys();
    }

    /**
     * Retrieves all solemnities from the SolemenitiesMap.
     *
     * @return SolemnitiesMap<string, LiturgicalEvent> A map of solemnity keys to their corresponding LiturgicalEvent objects.
     */
    public function getSolemnities(): SolemnitiesMap
    {
        return $this->solemnities;
    }

    /**
     * Retrieves the keys of all solemnities from the SolemnitiesMap.
     *
     * @return string[] An array of keys, each representing a solemnity in the collection.
     */
    public function getSolemnitiesKeys(): array
    {
        return $this->solemnities->getKeys();
    }

    /**
     * Retrieves all feasts of the Lord and of the Blessed Virgin Mary from the FeastsLordBVMMap.
     *
     * @return FeastsLordMap<string, LiturgicalEvent> A map of feast keys to their corresponding LiturgicalEvent objects.
     */
    public function getFeastsLord(): FeastsLordMap
    {
        return $this->feastsLord;
    }

    /**
     * Retrieves the keys of all feasts of the Lord and the Blessed Virgin Mary.
     *
     * @return string[] An array of keys, each representing a feast of the Lord or the BVM in the collection.
     */
    public function getFeastsLordKeys(): array
    {
        return $this->feastsLord->getKeys();
    }

    /**
     * Retrieves all feasts from the FeastsMap.
     *
     * @return FeastsMap<string, LiturgicalEvent> A map of event keys to their corresponding LiturgicalEvent objects, each representing a feast.
     */
    public function getFeasts(): FeastsMap
    {
        return $this->feasts;
    }

    /**
     * Retrieves the keys of all feasts from the FeastsMap.
     *
     * @return string[] An array of keys, each representing a feast in the collection.
     */
    public function getFeastsKeys(): array
    {
        return $this->feasts->getKeys();
    }

    /**
     * Retrieves all memorials from the MemorialsMap.
     *
     * @return MemorialsMap<string, LiturgicalEvent> A map of event keys to their corresponding LiturgicalEvent objects, each representing a memorial.
     */
    public function getMemorials(): MemorialsMap
    {
        return $this->memorials;
    }

    /**
     * Retrieves the keys of all memorials from the MemorialsMap.
     *
     * @return string[] An array of keys, each representing a memorial in the map.
     */

    public function getMemorialsKeys(): array
    {
        return $this->memorials->getKeys();
    }


    /**
     * Retrieves all liturgical events from the collection
     * in a format that can be easily converted to JSON.
     *
     * This method returns a collection of liturgical events or celebrations,
     * each with properties such as event_key, name, date, etc.
     *
     * @return LiturgicalEvent[]
     */
    public function getLiturgicalEventsCollection(): array
    {
        return $this->liturgicalEvents->toCollection();
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
     * @param DateTime $currentLitEventDate The date to check for coinciding events.
     * @param string $key The key of the event that seems to coincide with a Sunday, Solemnity or feast.
     * @return \stdClass holding {grade_lcl:string,event:LiturgicalEvent} An object containing the coinciding event and its grade.
     */
    public function determineSundaySolemnityOrFeast(DateTime $currentLitEventDate, string $key): \stdClass
    {
        $coincidingEvent            = new \stdClass();
        $coincidingEvent->grade_lcl = '';
        if (self::dateIsSunday($currentLitEventDate) && $this->inFeastsLord($currentLitEventDate)) {
            //it's a Sunday (which is also considered a Feast of the Lord)
            $dayOfTheWeek               = $this->dayOfTheWeek->format($currentLitEventDate->format('U')) ?: 'N/A';
            $coincidingEvent->event     = $this->feastLordFromDate($currentLitEventDate);
            $coincidingEvent->grade_lcl = $this->CalendarParams->Locale === LitLocale::LATIN
                ? 'Die Domini'
                : ucfirst($dayOfTheWeek);
        } elseif ($this->inSolemnities($currentLitEventDate) || $this->inFeastsLord($currentLitEventDate)) {
            //it's a Feast of the Lord or a Solemnity (that may or may not fall on a Sunday)
            $coincidingEvent->event     = $this->solemnityFromDate($currentLitEventDate) ?? $this->feastLordFromDate($currentLitEventDate);
            $coincidingEvent->grade_lcl = ( $coincidingEvent->event->grade->value > LitGrade::SOLEMNITY->value
                ? '<i>' . LitGrade::i18n($coincidingEvent->event->grade, $this->CalendarParams->Locale, false) . '</i>'
                : LitGrade::i18n($coincidingEvent->event->grade, $this->CalendarParams->Locale, false) );
        } elseif ($this->inFeastsOrMemorials($currentLitEventDate)) {
            $coincidingEvent->event     = $this->feastOrMemorialFromDate($currentLitEventDate);
            $coincidingEvent->grade_lcl = LitGrade::i18n($coincidingEvent->event->grade, $this->CalendarParams->Locale, false);
        } else {
            // DEBUG START
            $isSunday = self::dateIsSunday($currentLitEventDate);
            $msg      = 'Is Sunday? ' . ( $isSunday ? 'yes' : 'no' );
            $msg     .= "\n" . print_r($this->getCalEventsFromDate($currentLitEventDate), true);
            throw new \Exception('No liturgical event found for ' . $currentLitEventDate->format('Y-m-d') . ', coinciding event key: ' . $key . ".\n" . $msg);
            // DEBUG END
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
        foreach ($this->liturgicalEvents as $litEvent) {
            //$messages[] = "Checking entry $litEvent->event_key...";
            if (null === $litEvent->psalter_week) {
                //$messages[] = "***** The {$litEvent->grade_lcl} of {$litEvent->name} does not have a psalter_week property *****";

                if ($litEvent->is_vigil_mass) {
                    // Vigils can inherit the value from the corresponding event for which they are vigils
                    //$messages[] = "!!!!! The {$litEvent->grade_lcl} of {$litEvent->name} is a Vigil Mass and does not have a psalter_week property !!!!!";
                    $eventForWhichIsVigil = $this->liturgicalEvents->getEvent($litEvent->is_vigil_for);
                    if (null !== $eventForWhichIsVigil->psalter_week) {
                        //$messages[] = "The {$eventForWhichIsVigil->grade_lcl} of {$eventForWhichIsVigil->name} for which it is a Vigil Mass DOES have a psalter_week property with value {$eventForWhichIsVigil->psalter_week}";
                        $litEvent->psalter_week = $eventForWhichIsVigil->psalter_week;
                        //$messages[] = "The psalter_week for the {$litEvent->grade_lcl} of {$litEvent->name} was set to {$litEvent->psalter_week}";
                    } else {
                        //$messages[] = "The {$eventForWhichIsVigil->grade_lcl} of {$eventForWhichIsVigil->name} for which it is a Vigil Mass DOES NOT have a psalter_week property, setting both to 0";
                        $litEvent->psalter_week             = 0;
                        $eventForWhichIsVigil->psalter_week = 0;
                    }
                } elseif ($litEvent->grade === LitGrade::COMMEMORATION || $litEvent->grade === LitGrade::MEMORIAL_OPT) {
                    // Commemorations and Optional memorials can inherit the value from a same day event
                    //$messages[] = "^^^^^ The {$litEvent->grade_lcl} of {$litEvent->name} does not have a psalter_week property, checking in ferial events on the same day ^^^^^";
                    $ferialEventSameDay = $this->liturgicalEvents->getSameDayFerialEvent($litEvent->date);
                    //$messages[] = $ferialEventSameDay;
                    if (null !== $ferialEventSameDay && null !== $ferialEventSameDay->psalter_week) {
                        //$messages[] = "Found a ferial event on the same day that has a psalter_week property with value {$ferialEventSameDay[0]->psalter_week}";
                        $litEvent->psalter_week = $ferialEventSameDay->psalter_week;
                    } else {
                        //$messages[] = "No ferial event on the same day that has a psalter_week property, setting value to 0...";
                        $litEvent->psalter_week = 0;
                    }
                    //$messages[] = "The psalter_week property for the {$litEvent->grade_lcl} of {$litEvent->name} was set to {$litEvent->psalter_week}";
                } else {
                    $litEvent->psalter_week = 0;
                    //$messages[] = "The psalter_week value for the {$litEvent->grade_lcl} of {$litEvent->name} was set to 0";
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
            if ($litEvent->date < $this->liturgicalEvents->getEvent('Advent1')->date) {
                //remove all except the Vigil Mass for the first Sunday of Advent
                if (
                    ( null === $litEvent->is_vigil_mass )
                    ||
                    ( $litEvent->is_vigil_mass && $litEvent->is_vigil_for !== 'Advent1' )
                ) {
                    $this->liturgicalEvents->removeEvent($key);
                    $this->solemnitiesLordBVM->removeEvent($key);
                    $this->solemnities->removeEvent($key);
                    $this->feastsLord->removeEvent($key);
                    $this->feasts->removeEvent($key);
                    $this->memorials->removeEvent($key);
                    $this->sundaysEaster->removeEvent($key);
                    $this->sundaysLent->removeEvent($key);
                    $this->sundaysOrdinary->removeEvent($key);
                    $this->weekdaysEaster->removeEvent($key);
                    $this->weekdaysChristmas->removeEvent($key);
                    $this->weekdaysEpiphany->removeEvent($key);
                    $this->weekdaysOrdinary->removeEvent($key);
                    $this->reinstatedEvents->removeEvent($key);
                    $this->suppressedEvents->removeEvent($key);
                    // No need to remove anything related to Advent.
                }
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
            if ($litEvent->date > $this->liturgicalEvents->getEvent('Advent1')->date) {
                $this->liturgicalEvents->removeEvent($key);
                // make sure it isn't still contained in another collection
                $this->solemnitiesLordBVM->removeEvent($key);
                $this->solemnities->removeEvent($key);
                $this->feastsLord->removeEvent($key);
                $this->feasts->removeEvent($key);
                $this->memorials->removeEvent($key);
                $this->sundaysAdvent->removeEvent($key);
                $this->sundaysOrdinary->removeEvent($key);
                $this->weekdaysAdvent->removeEvent($key);
                $this->weekdaysChristmas->removeEvent($key);
                $this->weekdaysOrdinary->removeEvent($key);
                $this->reinstatedEvents->removeEvent($key);
                $this->suppressedEvents->removeEvent($key);
                // No need to remove anything related to Lent or Easter.
            }
            // also remove the Vigil Mass for the first Sunday of Advent
            // unfortunately we cannot keep it, because it would have the same key as for the other calendar year
            if (
                null !== $litEvent->is_vigil_mass
                && $litEvent->is_vigil_mass
                && $litEvent->is_vigil_for === 'Advent1'
            ) {
                $this->liturgicalEvents->removeEvent($key);
            }
        }
        //lastly remove First Sunday of Advent
        $this->liturgicalEvents->removeEvent('Advent1');
        $this->sundaysAdvent->removeEvent('Advent1');
        $this->solemnities->removeEvent('Advent1');
    }

    /**
     * Merges the current LiturgicalEventCollection with another LiturgicalEventCollection.
     *
     * This method merges the given collection into the current one by combining their respective
     * internal maps of liturgical events, solemnities, feasts, memorials, weekdays of Advent,
     * Christmas and Lent, weekdays of Epiphany, solemnities of Lord and BVM,
     * Sundays of Advent, Lent, and Easter.
     *
     * @param LiturgicalEventCollection $litEvents The LiturgicalEventCollection to merge with the current one.
     * @return void
     */
    public function merge(LiturgicalEventCollection $litEvents): void
    {
        $this->solemnitiesLordBVM->merge($litEvents->solemnitiesLordBVM);
        $this->solemnities->merge($litEvents->solemnities);
        $this->feastsLord->merge($litEvents->feastsLord);
        $this->feasts->merge($litEvents->feasts);
        $this->memorials->merge($litEvents->memorials);
        $this->sundaysAdvent->merge($litEvents->sundaysAdvent);
        $this->sundaysLent->merge($litEvents->sundaysLent);
        $this->sundaysEaster->merge($litEvents->sundaysEaster);
        $this->sundaysOrdinary->merge($litEvents->sundaysOrdinary);
        $this->weekdaysAdventBeforeDec17->merge($litEvents->weekdaysAdventBeforeDec17);
        $this->weekdaysAdvent->merge($litEvents->weekdaysAdvent);
        $this->weekdaysChristmas->merge($litEvents->weekdaysChristmas);
        $this->weekdaysLent->merge($litEvents->weekdaysLent);
        $this->weekdaysEpiphany->merge($litEvents->weekdaysEpiphany);
        $this->weekdaysOrdinary->merge($litEvents->weekdaysOrdinary);
        $this->liturgicalEvents->merge($litEvents->liturgicalEvents);
        $this->suppressedEvents->merge($litEvents->suppressedEvents);
        $this->reinstatedEvents->merge($litEvents->reinstatedEvents);
    }
}
