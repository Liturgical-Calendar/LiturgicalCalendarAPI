<?php

namespace LiturgicalCalendar\Api\Paths;

use LiturgicalCalendar\Api\Core;
use LiturgicalCalendar\Api\Enum\RomanMissal;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitCommon;
use LiturgicalCalendar\Api\Enum\StatusCode;
use LiturgicalCalendar\Api\Enum\RequestMethod;
use LiturgicalCalendar\Api\Enum\RequestContentType;
use LiturgicalCalendar\Api\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\LitMassVariousNeeds;
use LiturgicalCalendar\Api\Enum\ParamError;
use LiturgicalCalendar\Api\Models\Calendar\LitCommons;
use LiturgicalCalendar\Api\Params\EventsParams;
use LiturgicalCalendar\Api\Utilities;

/**
 * @phpstan-type LiturgicalEventItem array{
 *      event_key: string,
 *      missal: string,
 *      grade_lcl: string,
 *      common_lcl: string,
 *      name: string,
 *      common: string[],
 *      calendar: string,
 *      decree?: string,
 *      grade: int
 * }
 * @phpstan-type LiturgicalEventCollectionItem array<string,mixed>
 */
final class EventsPath
{
    public static Core $Core;
    /** @var array<string,LiturgicalEventCollectionItem> */
    private static array $LiturgicalEventCollection = [];
    /** @var string[] */
    private static array $LatinMissals = [];
    /** @var string[] */
    private static array $requestPathParts  = [];
    private static ?object $WiderRegionData = null;
    private static ?object $NationalData    = null;
    private static ?object $DiocesanData    = null;
    private EventsParams $EventsParams;

    /**
     * @param string[] $requestPathParts the path parameters from the request
     *
     * Initializes the Events class.
     *
     * This method will:
     * - Initialize the instance of the Core class
     * - Set the request path parts
     * - Initialize a new EventsParams object
     * - Initialize the WorldDioceses object from the world_dioceses.json file
     *
     * @throws \Exception if there is an issue with reading the world_dioceses.json file
     */
    public function __construct(array $requestPathParts = [])
    {
        self::$Core             = new Core();
        self::$requestPathParts = $requestPathParts;
        $this->EventsParams     = new EventsParams();
        if (EventsParams::$lastErrorStatus !== ParamError::NONE) {
            echo self::produceErrorResponse(StatusCode::BAD_REQUEST, EventsParams::getLastErrorMessage());
            die();
        }
    }

    /**
     * Populates the Latin Missals array with Roman Missal values.
     *
     * This method filters through the Roman Missal values and selects those
     * that represent Latin Missals, identified by the prefix "EDITIO_TYPICA_".
     * The filtered values are stored in the static Latin Missals array.
     */
    private static function retrieveLatinMissals(): void
    {
        self::$LatinMissals = array_filter(RomanMissal::$values, function ($item) {
            return str_starts_with($item, 'EDITIO_TYPICA_');
        });
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
        $params = null;
        if (false === in_array(self::$requestPathParts[0], ['nation', 'diocese'])) {
            echo self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, 'unknown resource path: ' . self::$requestPathParts[0]);
            die();
        }
        if (count(self::$requestPathParts) === 2) {
            if (self::$requestPathParts[0] === 'nation') {
                $params = [ 'national_calendar' => self::$requestPathParts[1] ];
                $this->EventsParams->setParams($params);
                if (EventsParams::$lastErrorStatus !== ParamError::NONE) {
                    echo self::produceErrorResponse(StatusCode::BAD_REQUEST, EventsParams::getLastErrorMessage());
                    die();
                }
            } else {
                $params = [ 'diocesan_calendar' => self::$requestPathParts[1] ];
                $this->EventsParams->setParams($params);
                if (EventsParams::$lastErrorStatus !== ParamError::NONE) {
                    echo self::produceErrorResponse(StatusCode::BAD_REQUEST, EventsParams::getLastErrorMessage());
                    die();
                }
            }
        } else {
            $description = 'wrong number of path parameters, needed two but got ' . count(self::$requestPathParts) . ': [' . implode(',', self::$requestPathParts) . ']';
            echo self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, $description);
            die();
        }
    }

    /**
     * Validate the POST request parameters.
     *
     * This method checks the content type of the request. If the content type is JSON,
     * it reads the JSON input from the request body, decodes it, and validates it. If the
     * JSON is malformed or the data is invalid, it produces a 400 Bad Request error response.
     * If the content type is FORMDATA, it validates the POST data. If the data is invalid,
     * it produces a 400 Bad Request error response.
     *
     * @return void
     */
    private function validatePostParams(): void
    {
        if (self::$Core->getRequestContentType() === RequestContentType::JSON) {
            $json = file_get_contents('php://input');
            if (false !== $json && '' !== $json) {
                $params = json_decode($json, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $description = "Malformed JSON data received in the request: <$json>, " . json_last_error_msg();
                    echo self::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
                    die();
                } else {
                    $this->EventsParams->setParams($params);
                    if (EventsParams::$lastErrorStatus !== ParamError::NONE) {
                        echo self::produceErrorResponse(StatusCode::BAD_REQUEST, EventsParams::getLastErrorMessage());
                        die();
                    }
                }
            }
        } elseif (self::$Core->getRequestContentType() === RequestContentType::FORMDATA) {
            if (count($_POST)) {
                $this->EventsParams->setParams($_POST);
                if (EventsParams::$lastErrorStatus !== ParamError::NONE) {
                    echo self::produceErrorResponse(StatusCode::BAD_REQUEST, EventsParams::getLastErrorMessage());
                    die();
                }
            }
        }
    }

    /**
     * Validate the GET request parameters.
     *
     * This method checks if there are any GET parameters present in the request.
     * If there are, it attempts to set the data to the EventsParams object.
     * If the data is invalid, it produces a 400 Bad Request error response
     * with the last error message from EventsParams.
     *
     * @return void
     */
    private function validateGetParams(): void
    {
        if (count($_GET)) {
            $this->EventsParams->setParams($_GET);
            if (EventsParams::$lastErrorStatus !== ParamError::NONE) {
                echo self::produceErrorResponse(StatusCode::BAD_REQUEST, EventsParams::getLastErrorMessage());
                die();
            }
        }
    }

    /**
     * Handles the request parameters for the Events resource.
     *
     * This method examines the request path parts and validates them if present.
     * It then validates the request parameters based on the HTTP request method.
     * - For POST requests, it validates the POST parameters.
     * - For GET requests, it validates the GET parameters.
     * - For OPTIONS requests, it continues without validation.
     * Produces a 405 Method Not Allowed error response if the request method is not supported.
     *
     * @return void
     */
    private function handleRequestParams(): void
    {
        if (count(self::$requestPathParts)) {
            $this->validateRequestPathParams();
        }

        switch (self::$Core->getRequestMethod()) {
            case RequestMethod::POST:
                $this->validatePostParams();
                break;
            case RequestMethod::GET:
                $this->validateGetParams();
                break;
            case RequestMethod::OPTIONS:
                //continue
                break;
            default:
                $description = 'You seem to be forming a strange kind of request? Allowed Request Methods are '
                    . implode(' and ', array_column(self::$Core->getAllowedRequestMethods(), 'value'))
                    . ', but your Request Method was '
                    . self::$Core->getRequestMethod()->value;
                echo self::produceErrorResponse(StatusCode::METHOD_NOT_ALLOWED, $description);
                die();
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
            $DiocesanData = array_find($this->EventsParams->calendarsMetadata->diocesan_calendars, function ($el) {
                return $el->calendar_id === $this->EventsParams->DiocesanCalendar;
            });
            if (null !== $DiocesanData) {
                $this->EventsParams->NationalCalendar = $DiocesanData->nation;

                $diocesanDataFile = strtr(
                    JsonData::DIOCESAN_CALENDAR_FILE,
                    [
                        '{nation}'       => $this->EventsParams->NationalCalendar,
                        '{diocese}'      => $this->EventsParams->DiocesanCalendar,
                        '{diocese_name}' => $DiocesanData->diocese
                    ]
                );

                self::$DiocesanData = Utilities::jsonFileToObject($diocesanDataFile);
            } else {
                $description = "unknown diocese `{$this->EventsParams->DiocesanCalendar}`, supported values are: ["
                    . implode(',', $this->EventsParams->calendarsMetadata->diocesan_calendars) . ']';
                echo self::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
                die();
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
            $nationalDataFile = strtr(
                JsonData::NATIONAL_CALENDAR_FILE,
                [
                    '{nation}' => $this->EventsParams->NationalCalendar
                ]
            );

            self::$NationalData = Utilities::jsonFileToObject($nationalDataFile);

            if (property_exists(self::$NationalData, 'metadata') && property_exists(self::$NationalData->metadata, 'locales')) {
                if (
                    null === $this->EventsParams->Locale
                    || !in_array($this->EventsParams->Locale, self::$NationalData->metadata->locales)
                ) {
                    $this->EventsParams->Locale     = self::$NationalData->metadata->locales[0];
                    $this->EventsParams->baseLocale = \Locale::getPrimaryLanguage($this->EventsParams->Locale);
                }
            }

            if (property_exists(self::$NationalData, 'metadata') && property_exists(self::$NationalData->metadata, 'wider_region')) {
                $widerRegionDataFile = strtr(
                    JsonData::WIDER_REGION_FILE,
                    [
                        '{wider_region}' => self::$NationalData->metadata->wider_region
                    ]
                );

                $widerRegionI18nFile = strtr(
                    JsonData::WIDER_REGION_I18N_FILE,
                    [
                        '{wider_region}' => self::$NationalData->metadata->wider_region,
                        '{locale}'       => $this->EventsParams->Locale
                    ]
                );

                $widerRegionI18nData   = Utilities::jsonFileToArray($widerRegionI18nFile);
                self::$WiderRegionData = Utilities::jsonFileToObject($widerRegionDataFile);

                foreach (self::$WiderRegionData->litcal as $idx => $value) {
                    $event_key = $value->liturgical_event->event_key;
                    if (array_key_exists($event_key, $widerRegionI18nData)) {
                        self::$WiderRegionData->litcal[$idx]->liturgical_event->name = $widerRegionI18nData[$event_key];
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
        bindtextdomain('litcal', 'i18n');
        textdomain('litcal');
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
    private function processMissalData(): void
    {
        foreach (self::$LatinMissals as $LatinMissal) {
            $MissalDataFile = RomanMissal::getSanctoraleFileName($LatinMissal);
            if ($MissalDataFile !== false) {
                $MissalData = Utilities::jsonFileToArray($MissalDataFile);

                // There may or may not be a related translation file.
                // If there is, we get the translated name for each liturgical event from here
                $names    = null;
                $I18nPath = RomanMissal::getSanctoraleI18nFilePath($LatinMissal);
                if ($I18nPath !== false) {
                    $I18nFile = $I18nPath . '/' . $this->EventsParams->baseLocale . '.json';
                    $names    = Utilities::jsonFileToArray($I18nFile);
                }

                foreach ($MissalData as $liturgicalEvent) {
                    $key = $liturgicalEvent['event_key'];
                    if ($names !== null && array_key_exists($key, $names)) {
                        $liturgicalEvent['name'] = $names[$key];
                    }
                    $grade                                 = LitGrade::from($liturgicalEvent['grade']);
                    $liturgicalEvent['grade']              = $grade;
                    $liturgicalEvent['grade_lcl']          = LitGrade::i18n($grade, $this->EventsParams->baseLocale, false);
                    $liturgicalEvent['missal']             = $LatinMissal;
                    $commons                               = LitCommons::create($liturgicalEvent['common']);
                    $liturgicalEvent['common_lcl']         = $commons->fullTranslate($this->EventsParams->baseLocale);
                    self::$LiturgicalEventCollection[$key] = $liturgicalEvent;
                }
            }
        }
    }

    /**
     * Processes the Proprium de Tempore data and populates the LiturgicalEventCollection.
     *
     * This function reads the Proprium de Tempore data from a JSON file and its
     * internationalization (i18n) data from another JSON file. It decodes both files
     * and checks for JSON errors, producing appropriate error responses if any
     * issues are encountered.
     *
     * For each liturgical event in the Proprium de Tempore data, the function checks if
     * it is already present in the LiturgicalEventCollection. If not, it adds the liturgical event
     * to the collection with its localized name and default attributes such as
     * grade, common, common_lcl, and calendar.
     *
     * @return void
     */
    private function processPropriumDeTemporeData(): void
    {
        $DataFile          = JsonData::MISSALS_FOLDER . '/propriumdetempore/propriumdetempore.json';
        $I18nFile          = JsonData::MISSALS_FOLDER . '/propriumdetempore/i18n/' . $this->EventsParams->baseLocale . '.json';
        $PropriumDeSanctis = Utilities::jsonFileToArray($DataFile);
        $names             = Utilities::jsonFileToArray($I18nFile);

        foreach ($PropriumDeSanctis as $liturgicalEvent) {
            $key = $liturgicalEvent['event_key'];
            if (false === array_key_exists($key, self::$LiturgicalEventCollection)) {
                $grade                                 = LitGrade::from($liturgicalEvent['grade']);
                $liturgicalEvent['name']               = $names[$key];
                $liturgicalEvent['grade_lcl']          = LitGrade::i18n($grade, $this->EventsParams->baseLocale, false);
                $liturgicalEvent['common']             = [];
                $liturgicalEvent['common_lcl']         = '';
                $liturgicalEvent['calendar']           = 'GENERAL ROMAN';
                self::$LiturgicalEventCollection[$key] = $liturgicalEvent;
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
        $decreesFile = JsonData::DECREES_FILE;
        $I18nFile    = JsonData::DECREES_I18N_FOLDER . '/' . $this->EventsParams->baseLocale . '.json';
        $decrees     = Utilities::jsonFileToArray($decreesFile);
        $names       = Utilities::jsonFileToArray($I18nFile);

        foreach ($decrees as $liturgicalEventData) {
            $liturgicalEvent         = $liturgicalEventData['liturgical_event'];
            $liturgicalEventMetadata = $liturgicalEventData['metadata'];
            $key                     = $liturgicalEvent['event_key'];
            if (false === array_key_exists($key, self::$LiturgicalEventCollection)) {
                $liturgicalEvent['name'] = $names[$key];
                if (array_key_exists('locales', $liturgicalEventMetadata)) {
                    $decreeURL = sprintf($liturgicalEventMetadata['url'], 'LA');
                    if (array_key_exists($this->EventsParams->baseLocale, $liturgicalEventMetadata['locales'])) {
                        $decreeLang = $liturgicalEvent['metadata']['locales'][$this->EventsParams->baseLocale];
                        $decreeURL  = sprintf($liturgicalEvent['metadata']['url'], $decreeLang);
                    }
                } else {
                    $decreeURL = $liturgicalEventMetadata['url'];
                }
                $liturgicalEvent['decree']             = $decreeURL;
                self::$LiturgicalEventCollection[$key] = $liturgicalEvent;
            } elseif ($liturgicalEventMetadata['action'] === 'setProperty') {
                if ($liturgicalEventMetadata['property'] === 'name') {
                    self::$LiturgicalEventCollection[$key]['name'] = $names[$key];
                } elseif ($liturgicalEventMetadata['property'] === 'grade') {
                    self::$LiturgicalEventCollection[$key]['grade'] = $liturgicalEvent['grade'];
                }
            } elseif ($liturgicalEventMetadata['action'] === 'makeDoctor') {
                self::$LiturgicalEventCollection[$key]['name'] = $names[$key];
            }

            $grade = self::$LiturgicalEventCollection[$key]['grade'] instanceof LitGrade
                    ? self::$LiturgicalEventCollection[$key]['grade']
                    : LitGrade::from(self::$LiturgicalEventCollection[$key]['grade']);

            self::$LiturgicalEventCollection[$key]['grade']     = $grade;
            self::$LiturgicalEventCollection[$key]['grade_lcl'] = LitGrade::i18n($grade, $this->EventsParams->baseLocale, false);
            if (array_key_exists('common', self::$LiturgicalEventCollection[$key])) {
                $common    = LitCommons::create(self::$LiturgicalEventCollection[$key]['common']);
                $commonLcl = $common->fullTranslate($this->EventsParams->baseLocale);

                self::$LiturgicalEventCollection[$key]['common']     = $common;
                self::$LiturgicalEventCollection[$key]['common_lcl'] = $commonLcl;
            }
        }
    }

    /**
     * @param LitCalMassVariousNeeds[]|LitCommon[]|LitCommons|string[] $common
     */
    private static function transformCommons(array|LitCommons $common): LitCommons|array
    {
        if ($common instanceof LitCommons) {
            return $common;
        }

        if ($common instanceof LitCommon) {
            /** @var LitCommons $commons */
            return LitCommons::create([$common]);
        }

        if (false === is_array($common)) {
            throw new \InvalidArgumentException('Invalid common provided to create LiturgicalEvent: expected an array of string, of LitCommon cases, or of LitMassVariousNeeds cases');
        }

        if (count($common) === 0) {
            return LitCommons::create([LitCommon::NONE]);
        }

        $valueTypes = array_values(array_unique(array_map(fn($value) => gettype($value), $common)));

        if (count($valueTypes) > 1) {
            throw new \InvalidArgumentException('Incoherent liturgical common value types provided to create LiturgicalEvent: found multiple types ' . implode(', ', $valueTypes));
        }

        if ($valueTypes[0] === 'string') {
            return LitCommons::create($common) ?? array_values(array_map(fn(string $value) => LitMassVariousNeeds::from($value), $common));
        }

        if ($common[0] instanceof LitCommon) {
            return LitCommons::create($common);
        }

        if ($common[0] instanceof LitMassVariousNeeds) {
            return $common;
        }

        throw new \InvalidArgumentException('Invalid common value type provided to create LiturgicalEvent: expected an array of string, of LitCommon cases, or of LitMassVariousNeeds cases');
    }

    /**
     * @param LitMassVariousNeeds[]|LitCommons $commons
     */
    private static function localizeCommons(array|LitCommons $commons, string $locale): string
    {
        if ($commons instanceof LitCommons) {
            return $commons->fullTranslate($locale);
        }

        $litMassVariousNeedsArray = false;
        if (is_array($commons)) {
            $valueTypes = array_values(array_unique(array_map(fn($value) => gettype($value), $commons)));
            if (count($valueTypes) > 1) {
                throw new \InvalidArgumentException('Incoherent liturgical common value types provided to create LiturgicalEvent: found multiple types ' . implode(', ', $valueTypes));
            }
            $litMassVariousNeedsArray = $commons[0] instanceof LitMassVariousNeeds;
        }

        if ($litMassVariousNeedsArray) {
            /** @var LitMassVariousNeeds[] $commons */
            $commonsLcl = array_map(fn(LitMassVariousNeeds $item) => $item->fullTranslate($locale === LitLocale::LATIN || $locale === LitLocale::LATIN_PRIMARY_LANGUAGE), $commons);
            return implode('; ' . _('or') . ' ', $commonsLcl);
        }
        return '???';
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
            if (self::$WiderRegionData !== null && property_exists(self::$WiderRegionData, 'litcal')) {
                foreach (self::$WiderRegionData->litcal as $row) {
                    if ($row->metadata->action === 'createNew') {
                        $key = $row->liturgical_event->event_key;

                        self::$LiturgicalEventCollection[$key] = [];
                        foreach ($row->liturgical_event as $prop => $value) {
                            if ($prop === 'grade') {
                                $value = LitGrade::from($value);
                            } elseif ($prop === 'common') {
                                $value = self::transformCommons($value);
                            }
                            self::$LiturgicalEventCollection[$key][$prop] = $value;
                        }
                        $grade  = self::$LiturgicalEventCollection[$key]['grade'];
                        $common = self::$LiturgicalEventCollection[$key]['common'];

                        self::$LiturgicalEventCollection[$key]['grade_lcl']  = LitGrade::i18n($grade, $this->EventsParams->baseLocale, false);
                        self::$LiturgicalEventCollection[$key]['common_lcl'] = self::localizeCommons($common, $this->EventsParams->baseLocale);
                    }
                }
            }

            $NationalCalendarI18nFile = strtr(
                JsonData::NATIONAL_CALENDAR_I18N_FILE,
                [
                    '{nation}' => $this->EventsParams->NationalCalendar,
                    '{locale}' => $this->EventsParams->Locale
                ]
            );

            $NationalCalendarI18nData = Utilities::jsonFileToArray($NationalCalendarI18nFile);

            foreach (self::$NationalData->litcal as $row) {
                $key = $row->liturgical_event->event_key;
                if ($row->metadata->action === 'createNew') {
                    self::$LiturgicalEventCollection[$key] = [];
                    foreach ($row->liturgical_event as $prop => $value) {
                        if ($prop === 'grade') {
                            $value = LitGrade::from($value);
                        } elseif ($prop === 'common') {
                            $value = self::transformCommons($value);
                        }
                        self::$LiturgicalEventCollection[$key][$prop] = $value;
                    }
                    $grade  = self::$LiturgicalEventCollection[$key]['grade'];
                    $common = self::$LiturgicalEventCollection[$key]['common'];

                    self::$LiturgicalEventCollection[$key]['grade_lcl']  = LitGrade::i18n($grade, $this->EventsParams->baseLocale, false);
                    self::$LiturgicalEventCollection[$key]['common_lcl'] = self::localizeCommons($common, $this->EventsParams->baseLocale);
                    self::$LiturgicalEventCollection[$key]['name']       = $NationalCalendarI18nData[$key];
                } elseif ($row->metadata->action === 'setProperty') {
                    if ($row->metadata->property === 'name') {
                        self::$LiturgicalEventCollection[$key]['name'] = $NationalCalendarI18nData[$key];
                    }
                    if ($row->metadata->property === 'grade') {
                        $grade = LitGrade::from($row->liturgical_event->grade);

                        self::$LiturgicalEventCollection[$key]['grade']     = $grade;
                        self::$LiturgicalEventCollection[$key]['grade_lcl'] = LitGrade::i18n($grade, $this->EventsParams->baseLocale, false);
                    }
                }
            }

            if (property_exists(self::$NationalData, 'metadata') && property_exists(self::$NationalData->metadata, 'missals')) {
                foreach (self::$NationalData->metadata->missals as $missal) {
                    $missalDataFile = RomanMissal::getSanctoraleFileName($missal);
                    if ($missalDataFile !== false) {
                        // There may or may not be a related translation file.
                        // If there is, we get the translated name for each liturgical event from here
                        $names    = null;
                        $I18nPath = RomanMissal::getSanctoraleI18nFilePath($missal);
                        if ($I18nPath !== false) {
                            $I18nFile = $I18nPath . $this->EventsParams->Locale . '.json';
                            $names    = Utilities::jsonFileToArray($I18nFile);
                        }

                        $PropriumDeSanctis = Utilities::jsonFileToObject($missalDataFile);
                        foreach ($PropriumDeSanctis as $liturgicalEvent) {
                            $key     = $liturgicalEvent->event_key;
                            $grade   = LitGrade::from($liturgicalEvent->grade);
                            $commons = self::transformCommons($liturgicalEvent->common);

                            self::$LiturgicalEventCollection[$key]               = (array) $liturgicalEvent;
                            self::$LiturgicalEventCollection[$key]['grade']      = $grade;
                            self::$LiturgicalEventCollection[$key]['grade_lcl']  = LitGrade::i18n($grade, $this->EventsParams->baseLocale, false);
                            self::$LiturgicalEventCollection[$key]['common_lcl'] = self::localizeCommons($commons, $this->EventsParams->baseLocale);
                            self::$LiturgicalEventCollection[$key]['missal']     = $missal;
                            if ($names !== null && array_key_exists($key, $names)) {
                                self::$LiturgicalEventCollection[$key]['name'] = $names[$key];
                            }
                        }
                    }
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
                JsonData::DIOCESAN_CALENDAR_I18N_FILE,
                [
                    '{nation}'  => $this->EventsParams->NationalCalendar,
                    '{diocese}' => $this->EventsParams->DiocesanCalendar,
                    '{locale}'  => $this->EventsParams->Locale
                ]
            );

            $DiocesanCalendarI18nData = Utilities::jsonFileToArray($DiocesanCalendarI18nFile);

            foreach (self::$DiocesanData->litcal as $row) {
                $key    = $this->EventsParams->DiocesanCalendar . '_' . $row->liturgical_event->event_key;
                $grade  = LitGrade::from($row->liturgical_event->grade);
                $common = LitCommons::create($row->liturgical_event->common);

                self::$LiturgicalEventCollection[$key]               = (array) $row->liturgical_event;
                self::$LiturgicalEventCollection[$key]['event_key']  = $key;
                self::$LiturgicalEventCollection[$key]['grade']      = $grade;
                self::$LiturgicalEventCollection[$key]['grade_lcl']  = LitGrade::i18n($grade, $this->EventsParams->baseLocale, false);
                self::$LiturgicalEventCollection[$key]['common_lcl'] = $common->fullTranslate($this->EventsParams->baseLocale);
                self::$LiturgicalEventCollection[$key]['name']       = $DiocesanCalendarI18nData[$row->liturgical_event->event_key];
            }
        }
    }

    /**
     * Produce an error response with the given HTTP status code and description.
     *
     * The description is a short string that should be used to give more context to the error.
     *
     * The function will output the error in the response format specified by the Accept header
     * of the request (JSON or YAML) and terminate the script execution with a call to die().
     *
     * @param int $statusCode the HTTP status code to return
     * @param string $description a short description of the error
     * @return string the error response in the specified format
     */
    private static function produceErrorResponse(int $statusCode, string $description): string
    {
        header($_SERVER['SERVER_PROTOCOL'] . StatusCode::toString($statusCode), true, $statusCode);
        $message              = new \stdClass();
        $message->status      = 'ERROR';
        $message->response    = StatusCode::toString($statusCode);
        $message->description = $description;
        $errResponse          = json_encode($message);
        switch (self::$Core->getResponseContentType()) {
            case AcceptHeader::YAML:
                $response = json_decode($errResponse, true);
                return yaml_emit($response, YAML_UTF8_ENCODING);
            case AcceptHeader::JSON:
            default:
                return $errResponse;
        }
    }

    /**
     * Produce the response for the /events endpoint.
     *
     * The function will output the response in the response format specified by the Accept header
     * of the request (JSON or YAML) and terminate the script execution with a call to die().
     *
     * @return void
     */
    private function produceResponse(): void
    {
        $responseObj  = [
            'litcal_events' => array_values(self::$LiturgicalEventCollection),
            'settings'      => [
                'locale'            => $this->EventsParams->Locale,
                'national_calendar' => $this->EventsParams->NationalCalendar,
                'diocesan_calendar' => $this->EventsParams->DiocesanCalendar
            ]
        ];
        $response     = json_encode($responseObj);
        $responseHash = md5($response);
        header("Etag: \"{$responseHash}\"");
        if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $responseHash) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 304 Not Modified');
            header('Content-Length: 0');
        } else {
            switch (self::$Core->getResponseContentType()) {
                case AcceptHeader::YAML:
                    // We must make sure that any nested stdClass objects are converted to associative arrays
                    $responseStr = json_encode($responseObj);
                    $responseObj = json_decode($responseStr, true);
                    echo yaml_emit($responseObj, YAML_UTF8_ENCODING);
                    break;
                case AcceptHeader::JSON:
                default:
                    echo $response;
                    break;
            }
        }
    }

    /**
     * Initializes the Events class and processes the request.
     *
     * @param string[] $requestPathParts The path parameters from the request.
     *
     * This method performs the following actions:
     * - Initializes the Core component and validates the Accept header.
     * - Sets the response content type based on the request.
     * - Retrieves and sets the request path parts.
     * - Loads and processes various calendar and missal data, including Latin Missals,
     *   Diocese Index, Diocesan Data, National and Wider Region Data.
     * - Sets the locale for the response.
     * - Handles request parameters and processes different types of calendar data,
     *   such as Missal, Proprium De Tempore, Memorials from Decrees, National,
     *   and Diocesan calendars.
     * - Produces and sends the response to the client.
     */
    public function init(array $requestPathParts = []): void
    {
        self::$Core->init();
        self::$Core->validateAcceptHeader(true);
        self::$Core->setResponseContentTypeHeader();

        self::$requestPathParts = $requestPathParts;
        self::retrieveLatinMissals();
        $this->handleRequestParams();
        $this->loadDiocesanData();
        $this->loadNationalAndWiderRegionData();
        $this->setLocale();
        $this->processMissalData();
        $this->processPropriumDeTemporeData();
        $this->processMemorialsFromDecreesData();
        $this->processNationalCalendarData();
        $this->processDiocesanCalendarData();
        self::produceResponse();
    }
}
