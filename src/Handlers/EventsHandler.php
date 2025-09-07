<?php

namespace LiturgicalCalendar\Api\Handlers;

use LiturgicalCalendar\Api\Enum\RomanMissal;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Http\Exception\UnprocessableContentException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemCollection;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemCreateNewFixed;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemCreateNewMobile;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemMakeDoctor;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemSetPropertyGrade;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemSetPropertyName;
use LiturgicalCalendar\Api\Models\EventsPath\LiturgicalEventAbstract;
use LiturgicalCalendar\Api\Models\EventsPath\LiturgicalEventFixed;
use LiturgicalCalendar\Api\Models\EventsPath\LiturgicalEventMap;
use LiturgicalCalendar\Api\Models\EventsPath\LiturgicalEventMobile;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanData;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewFixed;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewMobile;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemSetPropertyGrade;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemSetPropertyName;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanLitCalItemCreateNewFixed;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanLitCalItemCreateNewMobile;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemMakePatron;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemMoveEvent;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalData;
use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionData\WiderRegionData;
use LiturgicalCalendar\Api\Params\EventsParams;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Utilities;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @phpstan-import-type DecreeItemFromObject from \LiturgicalCalendar\Api\Models\Decrees\DecreeItem
 */
final class EventsHandler extends AbstractHandler
{
    /** @var LiturgicalEventMap */
    private static LiturgicalEventMap $liturgicalEvents;
    private static ?DiocesanData $DiocesanData       = null;
    private static ?NationalData $NationalData       = null;
    private static ?WiderRegionData $WiderRegionData = null;
    private EventsParams $EventsParams;

    /**
     * @param string[] $requestPathParams the path parameters from the request
     *
     * Initializes the Events class.
     *
     * This method will:
     * - Initialize the instance of the Core class
     * - Set the request path parts
     * - Initialize a new EventsParams object
     * - Initialize the WorldDioceses object from the world_dioceses.json file
     */
    public function __construct(array $requestPathParams = [])
    {
        parent::__construct($requestPathParams);

        self::$liturgicalEvents = new LiturgicalEventMap();
    }


    /**
     * Validate the request path parameters.
     *
     * This method will validate the request path parameters as follows:
     * - The first path parameter must be either "nation" or "diocese".
     * - If the first path parameter is "nation", there must be a second path parameter which is a valid national calendar ID.
     * - If the first path parameter is "diocese", there must be a second path parameter which is a valid diocesan calendar ID.
     * - If the first path parameter is neither "nation" nor "diocese", it will produce an error response with a status code of 422 and a description of the error.
     * - If the number of path parameters is not 2, it will produce an error response with a status code of 422 and a description of the error.
     *
     * @return void
     */
    private function validateRequestPathParams(): void
    {
        /** @var array{locale?:string,national_calendar?:string,diocesan_calendar?:string,eternal_high_priest?:bool} */
        $params = [];
        if (false === in_array($this->requestPathParams[0], ['nation', 'diocese'])) {
            throw new UnprocessableContentException('Unknown resource path: ' . $this->requestPathParams[0] . ', expected either /nation/{nation} or /diocese/{diocese_id}');
        }
        if (count($this->requestPathParams) === 2) {
            if ($this->requestPathParams[0] === 'nation') {
                /** @var array{locale?:string,national_calendar:string,diocesan_calendar?:string,eternal_high_priest?:bool} */
                $params = [ 'national_calendar' => $this->requestPathParams[1] ];
                $this->EventsParams->setParams($params);
            } else {
                /** @var array{locale?:string,national_calendar?:string,diocesan_calendar:string,eternal_high_priest?:bool} */
                $params = [ 'diocesan_calendar' => $this->requestPathParams[1] ];
                $this->EventsParams->setParams($params);
            }
        } else {
            $description = 'Wrong number of path parameters, needed two but got ' . count($this->requestPathParams) . ': [' . implode(',', $this->requestPathParams) . ']';
            throw new UnprocessableContentException($description);
        }
    }



    /**
     * Loads the JSON data for the specified diocesan calendar.
     *
     * If the payload is not valid according to {@see \LiturgicalCalendar\Api\Enum\LitSchema::DIOCESAN}, the response will be a JSON error response with a status code of 422 Unprocessable Content.
     *
     * @return void
     */
    private function loadDiocesanData(): void
    {
        if ($this->EventsParams->DiocesanCalendar !== null) {
            $DiocesanData = array_find(
                $this->EventsParams->calendarsMetadata->diocesan_calendars,
                fn ($el) => $el->calendar_id === $this->EventsParams->DiocesanCalendar
            );
            if (null !== $DiocesanData) {
                $this->EventsParams->NationalCalendar = $DiocesanData->nation;

                $diocesanDataFile = strtr(
                    JsonData::DIOCESAN_CALENDAR_FILE->path(),
                    [
                        '{nation}'       => $this->EventsParams->NationalCalendar,
                        '{diocese}'      => $this->EventsParams->DiocesanCalendar,
                        '{diocese_name}' => $DiocesanData->diocese
                    ]
                );

                $diocesanDataJson   = Utilities::jsonFileToObject($diocesanDataFile);
                self::$DiocesanData = DiocesanData::fromObject($diocesanDataJson);
                if (
                    !in_array($this->EventsParams->Locale, self::$DiocesanData->metadata->locales)
                ) {
                    $this->EventsParams->Locale = self::$DiocesanData->metadata->locales[0];
                    $baseLocale                 = \Locale::getPrimaryLanguage($this->EventsParams->Locale);
                    if (null === $baseLocale) {
                        throw new ValidationException(
                            '"Names are not always the same among all men, but differ in each language;'
                            . ' yet all are trying to express the nature of things."'
                            . ' — Plato, Cratylus, 383a'
                        );
                    }

                    $this->EventsParams->baseLocale = $baseLocale;
                }
            } else {
                $description = "unknown diocese `{$this->EventsParams->DiocesanCalendar}`, supported values are: ["
                    . implode(',', $this->EventsParams->calendarsMetadata->diocesan_calendars_keys) . ']';
                throw new ValidationException($description);
            }
        }
    }

    /**
     * Loads the JSON data for the specified National and Wider Region calendars.
     *
     * If the National calendar is specified, it retrieves the corresponding JSON data file.
     * If the JSON data is valid, it extracts settings like locale and checks for wider region metadata.
     * If wider region metadata is present, it loads the corresponding wider region data and its internationalization file.
     * Updates liturgical event names in the wider region data using the internationalization file.
     *
     * @return void
     */
    private function loadNationalAndWiderRegionData(): void
    {
        if ($this->EventsParams->NationalCalendar !== null) {
            $NationalDataFile = strtr(
                JsonData::NATIONAL_CALENDAR_FILE->path(),
                [
                    '{nation}' => $this->EventsParams->NationalCalendar
                ]
            );

            $nationalDataJson   = Utilities::jsonFileToObject($NationalDataFile);
            self::$NationalData = NationalData::fromObject($nationalDataJson);

            if (
                !in_array($this->EventsParams->Locale, self::$NationalData->metadata->locales)
            ) {
                $this->EventsParams->Locale = self::$NationalData->metadata->locales[0];
                $baseLocale                 = \Locale::getPrimaryLanguage($this->EventsParams->Locale);
                if (null === $baseLocale) {
                    throw new \RuntimeException(
                        '"Spoken words are the symbols of mental experience, and written words are the symbols of spoken words.'
                        . ' Just as all men have not the same speech sounds, so do they not all have the same written symbols.'
                        . ' But the mental experiences, which these directly symbolize, are the same for all."'
                        . ' — Aristotle, De Interpretatione, 1.16a'
                    );
                }

                $this->EventsParams->baseLocale = $baseLocale;
            }

            if (self::$NationalData->hasWiderRegion()) {
                $widerRegionDataFile = strtr(
                    JsonData::WIDER_REGION_FILE->path(),
                    [
                        '{wider_region}' => self::$NationalData->metadata->wider_region
                    ]
                );

                $widerRegionI18nFile = strtr(
                    JsonData::WIDER_REGION_I18N_FILE->path(),
                    [
                        '{wider_region}' => self::$NationalData->metadata->wider_region,
                        '{locale}'       => $this->EventsParams->Locale
                    ]
                );

                /** @var array<string,string> $widerRegionI18nData */
                $widerRegionI18nData   = Utilities::jsonFileToArray($widerRegionI18nFile);
                $widerRegionDataJson   = Utilities::jsonFileToObject($widerRegionDataFile);
                self::$WiderRegionData = WiderRegionData::fromObject($widerRegionDataJson);

                foreach (self::$WiderRegionData->litcal as $litCalItem) {
                    $event_key = $litCalItem->liturgical_event->event_key;
                    if (array_key_exists($event_key, $widerRegionI18nData)) {
                        $litCalItem->setName($widerRegionI18nData[$event_key]);
                    }
                }
            }
        }
    }

    /**
     * Sets the locale for the current instance, affecting date formatting
     * and translations of liturgical texts.
     *
     * This method retrieves the primary language from the current locale,
     * constructs an array of potential locale strings, and sets the locale
     * for PHP's internationalization functions. It also configures the domain
     * for gettext translations and initializes LitGrade and LitCommon instances
     * with the specified locale.
     *
     * @return void
     */
    private function setLocale(): void
    {
        $localeArray = [
            $this->EventsParams->Locale . '.utf8',
            $this->EventsParams->Locale . '.UTF-8',
            $this->EventsParams->Locale,
            $this->EventsParams->baseLocale . '_' . strtoupper($this->EventsParams->baseLocale) . '.utf8',
            $this->EventsParams->baseLocale . '_' . strtoupper($this->EventsParams->baseLocale) . '.UTF-8',
            $this->EventsParams->baseLocale . '_' . strtoupper($this->EventsParams->baseLocale),
            $this->EventsParams->baseLocale . '.utf8',
            $this->EventsParams->baseLocale . '.UTF-8',
            $this->EventsParams->baseLocale
        ];
        setlocale(LC_ALL, $localeArray);
        bindtextdomain('litcal', Router::$apiFilePath . 'i18n');
        textdomain('litcal');
        LiturgicalEventAbstract::setLocale($this->EventsParams->Locale);
    }

    /**
     * This function processes the data from the Sanctorale of the Latin Missal
     * and adds it to the LiturgicalEventCollection.
     *
     * The LiturgicalEventCollection is an array of liturgical event arrays, where each liturgical event
     * array has several keys: "event_key", "grade", "common", "missal", "grade_lcl",
     * and "common_lcl". "event_key" is the key for the liturgical event in the
     * LiturgicalEventCollection, "grade" is the grade of the liturgical event (i.e. solemnity,
     * feast, memorial, etc.), "common" is the common number of the liturgical event,
     * "missal" is the missal to which the liturgical event belongs, "grade_lcl" is the
     * localized grade of the liturgical event, and "common_lcl" is the localized common
     * number of the liturgical event.
     *
     * The function first retrieves the filename of the Sanctorale of the Latin
     * Missal. If the file does not exist, the function returns a 404 error.
     *
     * The function then reads the contents of the file into an array and decodes
     * it from JSON. If there is an error in decoding the JSON, the function returns
     * a 500 error.
     *
     * The function then loops through the array of liturgical event arrays and adds
     * each liturgical event to the LiturgicalEventCollection. It also adds the missal to which
     * the liturgical event belongs, the localized grade of the liturgical event, and the
     * localized common number of the liturgical event to the liturgical event array.
     *
     * Finally, the function checks if there is a related translation file for
     * the Sanctorale of the Latin Missal. If there is, the function reads the
     * contents of the file into an array and decodes it from JSON. If there is an
     * error in decoding the JSON, the function returns a 500 error.
     *
     * The function then loops through the array of liturgical event arrays and adds
     * the translated name of the liturgical event to the liturgical event array.
     */
    private function processSanctoraleEvents(): void
    {
        foreach (RomanMissal::getLatinMissalIds() as $LatinMissalId) {
            $MissalDataFile = RomanMissal::getSanctoraleFileName($LatinMissalId);
            $i18nPath       = RomanMissal::getSanctoraleI18nFilePath($LatinMissalId);

            if (false !== $MissalDataFile) {
                if (false === $i18nPath) {
                    throw new ServiceUnavailableException('Could not find translation file for Latin missal ' . $LatinMissalId);
                }
                $i18nFile   = "{$i18nPath}{$this->EventsParams->baseLocale}.json";
                $names      = Utilities::jsonFileToArray($i18nFile);
                $MissalData = Utilities::jsonFileToArray($MissalDataFile);

                /** @var array{event_key:string,month:integer,day:integer,grade:integer,color:string[],type:string,common?:string[],grade_display?:string} $liturgicalEvent */
                foreach ($MissalData as $liturgicalEvent) {
                    $key = $liturgicalEvent['event_key'];
                    if (array_key_exists($key, $names)) {
                        $liturgicalEvent['name'] = $names[$key];
                    }
                    if (false === isset($liturgicalEvent['name'])) {
                        throw new \RuntimeException('Could not find name for liturgical event ' . $key);
                    }
                    /** @var array{event_key:string,name:string,month:integer,day:integer,grade:integer,color:string[],type:string,common?:string[],grade_display?:string} $liturgicalEvent */
                    self::$liturgicalEvents->addEvent(LiturgicalEventFixed::fromArray($liturgicalEvent));
                }
            }
        }
    }


    /**
     * Processes the Memorials from Decrees data and populates the LiturgicalEventCollection.
     *
     * This function reads the Memorials from Decrees data from a JSON file and its
     * internationalization (i18n) data from another JSON file. It decodes both files
     * and checks for JSON errors, producing appropriate error responses if any
     * issues are encountered.
     *
     * For each liturgical event in the Memorials from Decrees data, the function checks if
     * it is already present in the LiturgicalEventCollection. If not, it adds the liturgical event
     * to the collection with its localized name and default attributes such as
     * grade, common, common_lcl, and calendar. It also adds the URL of the decree
     * promulgating the liturgical event.
     *
     * If the liturgical event is already present in the LiturgicalEventCollection, the function
     * checks if the action attribute of the liturgical event is 'setProperty'. If so, it
     * updates the specified property of the liturgical event. If the action attribute is
     * 'makeDoctor', it updates the name of the liturgical event.
     *
     * @return void
     */
    private function processMemorialsFromDecreesData(): void
    {
        $I18nFile    = JsonData::DECREES_I18N_FOLDER->path() . "/{$this->EventsParams->baseLocale}.json";
        $names       = Utilities::jsonFileToArray($I18nFile);
        $decreesFile = JsonData::DECREES_FILE->path();
        $decrees     = Utilities::jsonFileToObjectArray($decreesFile);
        /** @var DecreeItemFromObject[] $decrees */
        /** @var array<string,string> $names */
        DecreeItemCollection::setNames($decrees, $names);
        $decreeItems = DecreeItemCollection::fromObject($decrees);
        foreach ($decreeItems as $decreeItem) {
            $key = $decreeItem->getEventKey();
            if (false === self::$liturgicalEvents->hasKey($key) && ( $decreeItem->liturgical_event instanceof DecreeItemCreateNewFixed || $decreeItem->liturgical_event instanceof DecreeItemCreateNewMobile )) {
                if ($decreeItem->liturgical_event instanceof DecreeItemCreateNewFixed) {
                    self::$liturgicalEvents->addEvent(LiturgicalEventFixed::fromObject($decreeItem->liturgical_event));
                } else {
                    self::$liturgicalEvents->addEvent(LiturgicalEventMobile::fromObject($decreeItem->liturgical_event));
                }
            } elseif ($decreeItem->liturgical_event instanceof DecreeItemSetPropertyName) {
                $existingLiturgicalEvent = self::$liturgicalEvents->getEvent($key);
                if (null === $existingLiturgicalEvent) {
                    throw new \RuntimeException('Thomas, called Didymus, one of the Twelve, was not with them when Jesus came. - John 20:24');
                }
                $existingLiturgicalEvent->name = $names[$key];
            } elseif ($decreeItem->liturgical_event instanceof DecreeItemSetPropertyGrade) {
                $existingLiturgicalEvent = self::$liturgicalEvents->getEvent($key);
                if (null === $existingLiturgicalEvent) {
                    throw new \RuntimeException('It would seem that Jonah has been swallowed by the whale.');
                }
                $existingLiturgicalEvent->grade = $decreeItem->liturgical_event->grade;
            } elseif ($decreeItem->liturgical_event instanceof DecreeItemMakeDoctor) {
                $existingLiturgicalEvent = self::$liturgicalEvents->getEvent($key);
                if (null === $existingLiturgicalEvent) {
                    throw new \RuntimeException('Is Ishmael lost in the desert again?');
                }
                $existingLiturgicalEvent->name = $names[$key];
            }
        }
    }


    /**
     * Processes the National Calendar data and populates the LiturgicalEventCollection.
     *
     * This function checks if the NationalCalendar parameter and NationalData are set.
     * If WiderRegionData contains a 'litcal' property, it processes each liturgicalevent with
     * the action 'createNew' and adds it to the LiturgicalEventCollection, setting localized
     * grade and common attributes.
     *
     * It also iterates through the NationalData 'litcal' property and adds new liturgical events
     * to the LiturgicalEventCollection with localized attributes.
     *
     * If NationalData metadata includes 'missals', it attempts to load liturgicalevent data
     * from the specified Roman Missals, adding them to the LiturgicalEventCollection with
     * localized attributes and associating the missal name.
     *
     * Produces error responses if required resource files are not found.
     *
     * @return void
     */
    private function processNationalCalendarData(): void
    {
        if ($this->EventsParams->NationalCalendar !== null && self::$NationalData !== null) {
            if (count(self::$NationalData->metadata->missals) > 0) {
                foreach (self::$NationalData->metadata->missals as $missalId) {
                    $missalDataFile = RomanMissal::getSanctoraleFileName($missalId);
                    $I18nPath       = RomanMissal::getSanctoraleI18nFilePath($missalId);
                    if ($missalDataFile !== false) {
                        $I18nFile   = "{$I18nPath}{$this->EventsParams->Locale}.json";
                        $names      = Utilities::jsonFileToArray($I18nFile);
                        $MissalData = Utilities::jsonFileToArray($missalDataFile);

                        /** @var array{event_key:string,day:integer,month:integer,grade:integer,color:string[],type:string,common?:string[],grade_display?:string} $liturgicalEvent */
                        foreach ($MissalData as $liturgicalEvent) {
                            $key = $liturgicalEvent['event_key'];
                            if (array_key_exists($key, $names)) {
                                $liturgicalEvent['name'] = $names[$key];
                            }
                            if (false === isset($liturgicalEvent['name'])) {
                                throw new \Exception('Missing name for liturgical event ' . $key . ', unable to process liturgical events.');
                            }
                            /** @var array{event_key:string,name:string,day:integer,month:integer,grade:integer,color:string[],type:string,common?:string[],grade_display?:string} $liturgicalEvent */
                            self::$liturgicalEvents->addEvent(LiturgicalEventFixed::fromArray($liturgicalEvent));
                        }
                    }
                }
            }

            if (self::$WiderRegionData !== null) {
                foreach (self::$WiderRegionData->litcal as $litCalItem) {
                    if ($litCalItem->liturgical_event instanceof LitCalItemCreateNewFixed) {
                        $event = LiturgicalEventFixed::fromObject($litCalItem->liturgical_event);
                        self::$liturgicalEvents->addEvent($event);
                    } elseif ($litCalItem->liturgical_event instanceof LitCalItemCreateNewMobile) {
                        $event = LiturgicalEventMobile::fromObject($litCalItem->liturgical_event);
                        self::$liturgicalEvents->addEvent($event);
                    } elseif ($litCalItem->liturgical_event instanceof LitCalItemSetPropertyGrade) {
                        $existingLiturgicalEvent = self::$liturgicalEvents->getEvent($litCalItem->liturgical_event->event_key);
                        if (null === $existingLiturgicalEvent) {
                            throw new \RuntimeException('“The goat that was sent away presented a type of Him who takes away the sins of men.” – Justin Martyr');
                        }
                        $existingLiturgicalEvent->grade = $litCalItem->liturgical_event->grade;
                    } elseif ($litCalItem->liturgical_event instanceof LitCalItemSetPropertyName) {
                        $existingLiturgicalEvent = self::$liturgicalEvents->getEvent($litCalItem->liturgical_event->event_key);
                        if (null === $existingLiturgicalEvent) {
                            throw new \RuntimeException('No dove on this ark, did Noah already set it out?');
                        }
                        $existingLiturgicalEvent->name = $litCalItem->liturgical_event->name;
                    } elseif ($litCalItem->liturgical_event instanceof LitCalItemMakePatron) {
                        $existingLiturgicalEvent = self::$liturgicalEvents->getEvent($litCalItem->liturgical_event->event_key);
                        if (null === $existingLiturgicalEvent) {
                            throw new \RuntimeException('“Son, why have you done this to us? Your father and I have been looking for you with great anxiety.” – Luke 2:48');
                        }
                        $existingLiturgicalEvent->name = $litCalItem->liturgical_event->name;
                        if (property_exists($litCalItem->liturgical_event, 'grade')) {
                            $existingLiturgicalEvent->grade = $litCalItem->liturgical_event->grade;
                        }
                    } else {
                        throw new \ValueError('Unknown LitCalItem->liturgical_event type: ' . get_class($litCalItem->liturgical_event));
                    }
                }
            }

            $NationalCalendarI18nFile = strtr(
                JsonData::NATIONAL_CALENDAR_I18N_FILE->path(),
                [
                    '{nation}' => $this->EventsParams->NationalCalendar,
                    '{locale}' => $this->EventsParams->Locale
                ]
            );

            /** @var array<string,string> $NationalCalendarI18nData */
            $NationalCalendarI18nData = Utilities::jsonFileToArray($NationalCalendarI18nFile);

            foreach (self::$NationalData->litcal as $litCalItem) {
                $key = $litCalItem->liturgical_event->event_key;
                if ($litCalItem->liturgical_event instanceof LitCalItemCreateNewFixed) {
                    $litCalItem->setName($NationalCalendarI18nData[$key]);
                    self::$liturgicalEvents->addEvent(LiturgicalEventFixed::fromObject($litCalItem->liturgical_event));
                } elseif ($litCalItem->liturgical_event instanceof LitCalItemCreateNewMobile) {
                    $litCalItem->setName($NationalCalendarI18nData[$key]);
                    self::$liturgicalEvents->addEvent(LiturgicalEventMobile::fromObject($litCalItem->liturgical_event));
                } elseif ($litCalItem->liturgical_event instanceof LitCalItemSetPropertyName) {
                    $existingLiturgicalEvent = self::$liturgicalEvents->getEvent($key);
                    if (null === $existingLiturgicalEvent) {
                        throw new \RuntimeException("Unknown event key '{$key}' when setting name from National calendar");
                    }
                    $existingLiturgicalEvent->name = $NationalCalendarI18nData[$key];
                } elseif ($litCalItem->liturgical_event instanceof LitCalItemSetPropertyGrade) {
                    $existingLiturgicalEvent = self::$liturgicalEvents->getEvent($key);
                    if (null === $existingLiturgicalEvent) {
                        throw new \RuntimeException("Unknown event key '{$key}' when setting grade from National calendar");
                    }
                    $existingLiturgicalEvent->grade = $litCalItem->liturgical_event->grade;
                } elseif ($litCalItem->liturgical_event instanceof LitCalItemMakePatron) {
                    $existingLiturgicalEvent = self::$liturgicalEvents->getEvent($key);
                    if (null === $existingLiturgicalEvent) {
                        throw new \RuntimeException('Rising very early before dawn, he left and went off to a deserted place, where he prayed. Simon and those who were with him pursued him and on finding him said, “Everyone is looking for you.” - Mark 1:35-37');
                    }
                    $existingLiturgicalEvent->name  = $NationalCalendarI18nData[$key];
                    $existingLiturgicalEvent->grade = $litCalItem->liturgical_event->grade;
                } elseif ($litCalItem->liturgical_event instanceof LitCalItemMoveEvent) {
                    // Do nothing
                } else {
                    throw new \ValueError('Unknown LitCalItem->liturgical_event type: ' . get_class($litCalItem->liturgical_event));
                }
            }
        }
    }

    /**
     * Processes the Diocesan Calendar data and populates the LiturgicalEventCollection.
     *
     * This function checks if the DiocesanCalendar parameter and DiocesanData are set.
     * If so, it iterates through the DiocesanData 'litcal' property and adds new liturgical events
     * to the LiturgicalEventCollection with localized attributes and a modified event_key
     * incorporating the DiocesanCalendar parameter.
     *
     * @return void
     */
    private function processDiocesanCalendarData(): void
    {
        if ($this->EventsParams->DiocesanCalendar !== null && self::$DiocesanData !== null) {
            $DiocesanCalendarI18nFile = strtr(
                JsonData::DIOCESAN_CALENDAR_I18N_FILE->path(),
                [
                    '{nation}'  => $this->EventsParams->NationalCalendar,
                    '{diocese}' => $this->EventsParams->DiocesanCalendar,
                    '{locale}'  => $this->EventsParams->Locale
                ]
            );

            /** @var array<string,string> $DiocesanCalendarI18nData */
            $DiocesanCalendarI18nData = Utilities::jsonFileToArray($DiocesanCalendarI18nFile);

            foreach (self::$DiocesanData->litcal as $diocesanLitCalItem) {
                $key  = $diocesanLitCalItem->liturgical_event->event_key;
                $name = $DiocesanCalendarI18nData[$key];
                $diocesanLitCalItem->setName('[ ' . self::$DiocesanData->metadata->diocese_name . ' ] ' . $name);
                $diocesanLitCalItem->liturgical_event->setKey($this->EventsParams->DiocesanCalendar . '_' . $key);
                if ($diocesanLitCalItem->liturgical_event instanceof DiocesanLitCalItemCreateNewFixed) {
                    self::$liturgicalEvents->addEvent(LiturgicalEventFixed::fromObject($diocesanLitCalItem->liturgical_event));
                } elseif ($diocesanLitCalItem->liturgical_event instanceof DiocesanLitCalItemCreateNewMobile) {
                    self::$liturgicalEvents->addEvent(LiturgicalEventMobile::fromObject($diocesanLitCalItem->liturgical_event));
                } else {
                    throw new \ValueError('Unknown DiocesanLitCalItem->liturgical_event type: ' . get_class($diocesanLitCalItem->liturgical_event));
                }
            }
        }
    }


    /**
     * Initializes the Events class and processes the request.
     *
     * This method performs the following actions:
     * - Validates the Accept header.
     * - Sets the response Content-ype based on the request and the best type available.
     * - Retrieves and sets parameters from the request.
     * - Loads and processes various calendar and missal and decree data.
     * - Sets the locale for the response.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = static::initResponse($request);

        $method = RequestMethod::from($request->getMethod());

        // OPTIONS method for CORS preflight requests is always allowed
        if ($method === RequestMethod::OPTIONS) {
            return $this->handlePreflightRequest($request, $response);
        } else {
            $response = $this->setAccessControlAllowOriginHeader($request, $response);
        }

        // First of all we validate that the Content-Type requested in the Accept header is supported by the endpoint:
        //   if set we negotiate the best Content-Type, if not set we default to the first supported by the current handler
        switch ($method) {
            case RequestMethod::GET:
                $mime = $this->validateAcceptHeader($request, AcceptabilityLevel::LAX);
                break;
            default:
                $mime = $this->validateAcceptHeader($request, AcceptabilityLevel::INTERMEDIATE);
        }

        $response = $response->withHeader('Content-Type', $mime);

        // Initialize any parameters set in the request.
        // If there are any:
        //   - for a GET request method, we expect them to be set in the URL
        //   - for any other request methods, we expect them to be set in the body of the request
        // Considering that this endpoint is both read and write:
        //   - for POST requests we will never have a payload in the request body,
        //       only request parameters
        //   - for PUT and PATCH requests we will have a payload in the request body
        //   - for DELETE requests we will have neither payload nor request parameters, only path parameters

        /** @var array{locale?:string,national_calendar?:string,diocesan_calendar?:string,eternal_high_priest?:bool} $params */
        $params = [];

        // Second of all, we check if an Accept-Language header was set in the request
        $acceptLanguageHeader = $request->getHeaderLine('Accept-Language');
        $locale               = '' !== $acceptLanguageHeader
            ? \Locale::acceptFromHttp($acceptLanguageHeader)
            : LitLocale::LATIN;
        if ($locale && LitLocale::isValid($locale)) {
            $params['locale'] = $locale;
        } else {
            $params['locale'] = LitLocale::LATIN;
        }

        if ($method === RequestMethod::GET) {
            /** @var array{locale?:string,national_calendar?:string,diocesan_calendar?:string,eternal_high_priest?:bool} $params */
            $params = array_merge($params, $this->getScalarQueryParams($request));
        } elseif ($method === RequestMethod::POST) {
            $parsedBodyParams = $this->parseBodyParams($request, false);

            if (null !== $parsedBodyParams) {
                /** @var array{locale?:string,national_calendar?:string,diocesan_calendar?:string,eternal_high_priest?:bool} $params */
                $params = array_merge($params, $parsedBodyParams);
            }
        }

        $this->EventsParams = new EventsParams($params);
        if (count($this->requestPathParams)) {
            $this->validateRequestPathParams();
        }

        $this->validateRequestMethod($request);

        $this->loadNationalAndWiderRegionData();
        $this->loadDiocesanData();
        $this->setLocale();
        $this->processSanctoraleEvents();
        $this->processMemorialsFromDecreesData();
        $this->processNationalCalendarData();
        $this->processDiocesanCalendarData();

        $responseObj  = [
            'litcal_events' => self::$liturgicalEvents->toCollection(),
            'settings'      => [
                'locale'            => $this->EventsParams->Locale,
                'national_calendar' => $this->EventsParams->NationalCalendar,
                'diocesan_calendar' => $this->EventsParams->DiocesanCalendar
            ]
        ];
        $responseBody = json_encode($responseObj, JSON_THROW_ON_ERROR);
        $responseHash = md5($responseBody);
        $response     = $response->withHeader('ETag', "\"{$responseHash}\"");

        if (
            $request->getHeaderLine('If-None-Match') !== ''
            && trim($request->getHeaderLine('If-None-Match'), " \t\"") === $responseHash
        ) {
            return $response->withStatus(StatusCode::NOT_MODIFIED->value, StatusCode::NOT_MODIFIED->reason())
                            ->withHeader('Content-Length', '0');
        }

        return $this->encodeResponseBody($response, $responseObj);
    }
}
