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
use LiturgicalCalendar\Api\Params\EventsParams;

class Events
{
    public static Core $Core;
    private static array $FestivityCollection = [];
    private static array $LatinMissals        = [];
    private static ?object $WiderRegionData   = null;
    private static ?object $NationalData      = null;
    private static ?object $DiocesanData      = null;
    private static ?LitGrade $LitGrade        = null;
    private static ?LitCommon $LitCommon      = null;
    private static array $requestPathParts    = [];
    private EventsParams $EventsParams;

    /**
     * @param array $requestPathParts the path parameters from the request
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
        self::$Core = new Core();
        self::$requestPathParts = $requestPathParts;
        $this->EventsParams = new EventsParams();
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
            return str_starts_with($item, "EDITIO_TYPICA_");
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
        $data = null;
        if (false === in_array(self::$requestPathParts[0], ['nation','diocese'])) {
            echo self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, "unknown resource path: " . self::$requestPathParts[0]);
            die();
        }
        if (count(self::$requestPathParts) === 2) {
            if (self::$requestPathParts[0] === "nation") {
                $data = [ "national_calendar" => self::$requestPathParts[1] ];
                if (false === $this->EventsParams->setData($data)) {
                    echo self::produceErrorResponse(StatusCode::BAD_REQUEST, EventsParams::getLastErrorMessage());
                    die();
                }
            } elseif (self::$requestPathParts[0] === "diocese") {
                $data = [ "diocesan_calendar" => self::$requestPathParts[1] ];
                if (false === $this->EventsParams->setData($data)) {
                    echo self::produceErrorResponse(StatusCode::BAD_REQUEST, EventsParams::getLastErrorMessage());
                    die();
                }
            } else {
                echo self::produceErrorResponse(StatusCode::UNPROCESSABLE_CONTENT, "unknown resource path: " . self::$requestPathParts[0]);
                die();
            }
        } else {
            $description = "wrong number of path parameters, needed two but got " . count(self::$requestPathParts) . ": [" . implode(',', self::$requestPathParts) . "]";
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
            if (false !== $json && "" !== $json) {
                $data = json_decode($json, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $description = "Malformed JSON data received in the request: <$json>, " . json_last_error_msg();
                    echo self::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
                    die();
                } else {
                    if (false === $this->EventsParams->setData($data)) {
                        echo self::produceErrorResponse(StatusCode::BAD_REQUEST, EventsParams::getLastErrorMessage());
                        die();
                    }
                }
            }
        } elseif (self::$Core->getRequestContentType() === RequestContentType::FORMDATA) {
            if (count($_POST)) {
                if (false === $this->EventsParams->setData($_POST)) {
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
            if (false === $this->EventsParams->setData($_GET)) {
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
                $description = "You seem to be forming a strange kind of request? Allowed Request Methods are "
                    . implode(' and ', self::$Core->getAllowedRequestMethods())
                    . ', but your Request Method was '
                    . self::$Core->getRequestMethod();
                echo self::produceErrorResponse(StatusCode::METHOD_NOT_ALLOWED, $description);
                die();
        }
    }

    /**
     * Loads the JSON data for the specified diocesan calendar.
     *
     * If the payload is not valid according to {@see LitSchema::DIOCESAN}, the response will be a JSON error response with a status code of 422 Unprocessable Content.
     *
     * @return void
     */
    private function loadDiocesanData(): void
    {
        if ($this->EventsParams->DiocesanCalendar !== null) {
            $DiocesanData = array_values(array_filter($this->EventsParams->calendarsMetadata->diocesan_calendars, function ($el) {
                return $el->calendar_id === $this->EventsParams->DiocesanCalendar;
            }));
            if (count($DiocesanData) === 1) {
                $this->EventsParams->NationalCalendar = $DiocesanData[0]->nation;
                $diocesanDataFile = strtr(
                    JsonData::DIOCESAN_CALENDARS_FILE,
                    [
                        '{nation}' => $this->EventsParams->NationalCalendar,
                        '{diocese}' => $this->EventsParams->DiocesanCalendar,
                        '{diocese_name}' => $DiocesanData[0]->diocese
                    ]
                );
                if (file_exists($diocesanDataFile)) {
                    self::$DiocesanData = json_decode(file_get_contents($diocesanDataFile));
                } else {
                    echo self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "no data file found for diocese {$this->EventsParams->DiocesanCalendar}");
                    die();
                }
            } else {
                $description = "unknown diocese `{$this->EventsParams->DiocesanCalendar}`, supported values are: ["
                    . implode(',', $this->EventsParams->calendarsMetadata->diocesan_calendars) . "]";
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
     * Updates festivity names in the wider region data using the internationalization file.
     *
     * @return void
     */
    private function loadNationalAndWiderRegionData(): void
    {
        if ($this->EventsParams->NationalCalendar !== null) {
            $nationalDataFile = strtr(
                JsonData::NATIONAL_CALENDARS_FILE,
                [
                    '{nation}' => $this->EventsParams->NationalCalendar
                ]
            );
            if (file_exists($nationalDataFile)) {
                self::$NationalData = json_decode(file_get_contents($nationalDataFile));
                if (json_last_error() === JSON_ERROR_NONE) {
                    if (property_exists(self::$NationalData, "metadata") && property_exists(self::$NationalData->metadata, "locales")) {
                        if (
                            null === $this->EventsParams->Locale
                            || !in_array($this->EventsParams->Locale, self::$NationalData->metadata->locales)
                        ) {
                            $this->EventsParams->Locale = self::$NationalData->metadata->locales[0];
                            $this->EventsParams->baseLocale = \Locale::getPrimaryLanguage($this->EventsParams->Locale);
                        }
                    }
                    if (property_exists(self::$NationalData, "metadata") && property_exists(self::$NationalData->metadata, "wider_region")) {
                        $widerRegionDataFile = strtr(
                            JsonData::WIDER_REGIONS_FILE,
                            [
                                '{wider_region}' => self::$NationalData->metadata->wider_region
                            ]
                        );
                        $widerRegionI18nFile = strtr(
                            JsonData::WIDER_REGIONS_I18N_FILE,
                            [
                                '{wider_region}' => self::$NationalData->metadata->wider_region,
                                '{locale}' => $this->EventsParams->baseLocale
                            ]
                        );
                        if (file_exists($widerRegionI18nFile)) {
                            $widerRegionI18nData = json_decode(file_get_contents($widerRegionI18nFile));
                            if (json_last_error() === JSON_ERROR_NONE && file_exists($widerRegionDataFile)) {
                                self::$WiderRegionData = json_decode(file_get_contents($widerRegionDataFile));
                                if (json_last_error() === JSON_ERROR_NONE && property_exists(self::$WiderRegionData, "litcal")) {
                                    foreach (self::$WiderRegionData->litcal as $idx => $value) {
                                        $event_key = $value->festivity->event_key;
                                        if (property_exists($widerRegionI18nData, $event_key)) {
                                            self::$WiderRegionData->litcal[$idx]->festivity->name = $widerRegionI18nData->{$event_key};
                                        }
                                    }
                                }
                            }
                        }
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
        bindtextdomain("litcal", "i18n");
        textdomain("litcal");
        self::$LitGrade = new LitGrade($this->EventsParams->baseLocale);
        self::$LitCommon = new LitCommon($this->EventsParams->baseLocale);
    }

    /**
     * This function processes the data from the Sanctorale of the Latin Missal
     * and adds it to the FestivityCollection.
     *
     * The FestivityCollection is an array of festivity arrays, where each festivity
     * array has several keys: "event_key", "grade", "common", "missal", "grade_lcl",
     * and "common_lcl". "event_key" is the key for the festivity in the
     * FestivityCollection, "grade" is the grade of the festivity (i.e. solemnity,
     * feast, memorial, etc.), "common" is the common number of the festivity,
     * "missal" is the missal to which the festivity belongs, "grade_lcl" is the
     * localized grade of the festivity, and "common_lcl" is the localized common
     * number of the festivity.
     *
     * The function first retrieves the filename of the Sanctorale of the Latin
     * Missal. If the file does not exist, the function returns a 404 error.
     *
     * The function then reads the contents of the file into an array and decodes
     * it from JSON. If there is an error in decoding the JSON, the function returns
     * a 500 error.
     *
     * The function then loops through the array of festivity arrays and adds
     * each festivity to the FestivityCollection. It also adds the missal to which
     * the festivity belongs, the localized grade of the festivity, and the
     * localized common number of the festivity to the festivity array.
     *
     * Finally, the function checks if there is a related translation file for
     * the Sanctorale of the Latin Missal. If there is, the function reads the
     * contents of the file into an array and decodes it from JSON. If there is an
     * error in decoding the JSON, the function returns a 500 error.
     *
     * The function then loops through the array of festivity arrays and adds
     * the translated name of the festivity to the festivity array.
     */
    private function processMissalData(): void
    {
        foreach (self::$LatinMissals as $LatinMissal) {
            $DataFile = RomanMissal::getSanctoraleFileName($LatinMissal);
            if ($DataFile !== false) {
                if (!file_exists($DataFile)) {
                    echo self::produceErrorResponse(StatusCode::NOT_FOUND, "Could not find resource $DataFile");
                    die();
                }
                $DATA = json_decode(file_get_contents($DataFile), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    echo self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, json_last_error_msg());
                    die();
                }
                foreach ($DATA as $festivity) {
                    $key = $festivity[ "event_key" ];
                    self::$FestivityCollection[ $key ] = $festivity;
                    self::$FestivityCollection[ $key ][ "missal" ] = $LatinMissal;
                    self::$FestivityCollection[ $key ][ "grade_lcl" ] = self::$LitGrade->i18n($festivity["grade"], false);
                    self::$FestivityCollection[ $key ][ "common_lcl" ] = self::$LitCommon->c($festivity["common"]);
                }
                // There may or may not be a related translation file; if there is, we get the translated name from here
                $I18nPath = RomanMissal::getSanctoraleI18nFilePath($LatinMissal);
                if ($I18nPath !== false) {
                    $I18nFile = $I18nPath . "/" . $this->EventsParams->baseLocale . ".json";
                    if (false === file_exists($I18nFile)) {
                        echo self::produceErrorResponse(StatusCode::NOT_FOUND, "Could not find resource $I18nPath");
                        die();
                    }
                    $NAME = json_decode(file_get_contents($I18nFile), true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        echo self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, json_last_error_msg());
                        die();
                    }
                    foreach ($DATA as $festivity) {
                        $key = $festivity[ "event_key" ];
                        if (array_key_exists($key, $NAME)) {
                            self::$FestivityCollection[ $key ][ "name" ] = $NAME[ $key ];
                        }
                    }
                }
            }
        }
    }

    /**
     * Processes the Proprium de Tempore data and populates the FestivityCollection.
     *
     * This function reads the Proprium de Tempore data from a JSON file and its
     * internationalization (i18n) data from another JSON file. It decodes both files
     * and checks for JSON errors, producing appropriate error responses if any
     * issues are encountered.
     *
     * For each festivity in the Proprium de Tempore data, the function checks if
     * it is already present in the FestivityCollection. If not, it adds the festivity
     * to the collection with its localized name and default attributes such as
     * grade, common, common_lcl, and calendar.
     *
     * @return void
     */
    private function processPropriumDeTemporeData(): void
    {
        $DataFile = 'jsondata/sourcedata/missals/propriumdetempore/propriumdetempore.json';
        $I18nFile = 'jsondata/sourcedata/missals/propriumdetempore/i18n/' . $this->EventsParams->baseLocale . ".json";
        if (!file_exists($DataFile) || !file_exists($I18nFile)) {
            echo self::produceErrorResponse(
                StatusCode::NOT_FOUND,
                "Could not find either the resource file " . $DataFile . " or the resource file " . $I18nFile
            );
            die();
        }
        $DATA = json_decode(file_get_contents($DataFile), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, json_last_error_msg());
            die();
        }
        $NAME = json_decode(file_get_contents($I18nFile), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, json_last_error_msg());
            die();
        }

        foreach ($DATA as $row) {
            $key = $row['event_key'];
            if (false === array_key_exists($key, self::$FestivityCollection)) {
                self::$FestivityCollection[ $key ] = $row;
                self::$FestivityCollection[ $key ][ "name" ] = $NAME[ $key ];
                self::$FestivityCollection[ $key ][ "grade_lcl" ] = self::$LitGrade->i18n($row['grade'], false);
                self::$FestivityCollection[ $key ][ "common" ] = [];
                self::$FestivityCollection[ $key ][ "common_lcl" ] = "";
                self::$FestivityCollection[ $key ][ "calendar" ] = "GENERAL ROMAN";
            }
        }
    }

    /**
     * Processes the Memorials from Decrees data and populates the FestivityCollection.
     *
     * This function reads the Memorials from Decrees data from a JSON file and its
     * internationalization (i18n) data from another JSON file. It decodes both files
     * and checks for JSON errors, producing appropriate error responses if any
     * issues are encountered.
     *
     * For each festivity in the Memorials from Decrees data, the function checks if
     * it is already present in the FestivityCollection. If not, it adds the festivity
     * to the collection with its localized name and default attributes such as
     * grade, common, common_lcl, and calendar. It also adds the URL of the decree
     * promulgating the festivity.
     *
     * If the festivity is already present in the FestivityCollection, the function
     * checks if the action attribute of the festivity is 'setProperty'. If so, it
     * updates the specified property of the festivity. If the action attribute is
     * 'makeDoctor', it updates the name of the festivity.
     *
     * @return void
     */
    private function processMemorialsFromDecreesData(): void
    {
        $DataFile = 'jsondata/sourcedata/decrees/decrees.json';
        $I18nFile = 'jsondata/sourcedata/decrees/i18n/' . $this->EventsParams->baseLocale . ".json";
        if (!file_exists($DataFile) || !file_exists($I18nFile)) {
            echo self::produceErrorResponse(StatusCode::NOT_FOUND, "Could not find resource file $DataFile or resource file $I18nFile");
            die();
        }

        $DATA = json_decode(file_get_contents($DataFile), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, json_last_error_msg());
            die();
        }
        $NAME = json_decode(file_get_contents($I18nFile), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, json_last_error_msg());
            die();
        }
        foreach ($DATA as $idx => $festivity) {
            $key = $festivity[ "festivity" ][ "event_key" ];
            if (false === array_key_exists($key, self::$FestivityCollection)) {
                self::$FestivityCollection[ $key ] = $festivity[ "festivity" ];
                self::$FestivityCollection[ $key ][ "name" ] = $NAME[ $key ];
                if (array_key_exists("locales", $festivity[ "metadata" ])) {
                    $decreeURL = sprintf($festivity[ "metadata" ][ "url" ], 'LA');
                    if (array_key_exists($this->EventsParams->baseLocale, $festivity[ "metadata" ][ "locales" ])) {
                        $decreeLang = $festivity[ "metadata" ][ "locales" ][ $this->EventsParams->baseLocale ];
                        $decreeURL = sprintf($festivity[ "metadata" ][ "url" ], $decreeLang);
                    }
                } else {
                    $decreeURL = $festivity[ "metadata" ][ "url" ];
                }
                self::$FestivityCollection[ $key ][ "decree" ] = $decreeURL;
            } elseif ($festivity[ "metadata" ][ "action" ] === 'setProperty') {
                if ($festivity[ "metadata" ][ "property" ] === 'name') {
                    self::$FestivityCollection[ $key ][ "name" ] = $NAME[ $key ];
                } elseif ($festivity[ "metadata" ][ "property" ] === 'grade') {
                    self::$FestivityCollection[ $key ][ "grade" ] = $festivity[ "festivity" ][ "grade" ];
                }
            } elseif ($festivity[ "metadata" ][ "action" ] === 'makeDoctor') {
                self::$FestivityCollection[ $key ][ "name" ] = $NAME[ $key ];
            }
            self::$FestivityCollection[ $key ][ "grade_lcl" ] = self::$LitGrade->i18n(self::$FestivityCollection[ $key ][ "grade" ], false);
            if (array_key_exists('common', self::$FestivityCollection[ $key ])) {
                self::$FestivityCollection[ $key ][ "common_lcl" ] = self::$LitCommon->c(self::$FestivityCollection[ $key ][ "common" ]);
            }
        }
    }

    /**
     * Processes the National Calendar data and populates the FestivityCollection.
     *
     * This function checks if the NationalCalendar parameter and NationalData are set.
     * If WiderRegionData contains a 'litcal' property, it processes each festivity with
     * the action 'createNew' and adds it to the FestivityCollection, setting localized
     * grade and common attributes.
     *
     * It also iterates through the NationalData 'litcal' property and adds new festivities
     * to the FestivityCollection with localized attributes.
     *
     * If NationalData metadata includes 'missals', it attempts to load festivity data
     * from the specified Roman Missals, adding them to the FestivityCollection with
     * localized attributes and associating the missal name.
     *
     * Produces error responses if required resource files are not found.
     *
     * @return void
     */
    private function processNationalCalendarData(): void
    {
        if ($this->EventsParams->NationalCalendar !== null && self::$NationalData !== null) {
            if (self::$WiderRegionData !== null && property_exists(self::$WiderRegionData, "litcal")) {
                foreach (self::$WiderRegionData->litcal as $row) {
                    if ($row->metadata->action === 'createNew') {
                        $key = $row->festivity->event_key;
                        self::$FestivityCollection[ $key ] = [];
                        foreach ($row->festivity as $prop => $value) {
                            self::$FestivityCollection[ $key ][ $prop ] = $value;
                        }
                        self::$FestivityCollection[ $key ][ "grade_lcl" ] = self::$LitGrade->i18n($row->festivity->grade, false);
                        self::$FestivityCollection[ $key ][ "common_lcl" ] = self::$LitCommon->c($row->festivity->common);
                    }
                }
            }
            $NationalCalendarI18nFile = strtr(
                JsonData::NATIONAL_CALENDARS_I18N_FILE,
                [
                    '{nation}' => $this->EventsParams->NationalCalendar,
                    '{locale}' => $this->EventsParams->Locale
                ]
            );
            $NationalCalendarI18nData = json_decode(file_get_contents($NationalCalendarI18nFile), true);
            foreach (self::$NationalData->litcal as $row) {
                if ($row->metadata->action === 'createNew') {
                    $key = $row->festivity->event_key;
                    self::$FestivityCollection[ $key ] = (array) $row->festivity;
                    self::$FestivityCollection[ $key ][ "grade_lcl" ] = self::$LitGrade->i18n($row->festivity->grade, false);
                    self::$FestivityCollection[ $key ][ "common_lcl" ] = self::$LitCommon->c($row->festivity->common);
                    self::$FestivityCollection[ $key ][ "name" ] = $NationalCalendarI18nData[ $key ];
                } elseif ($row->metadata->action === 'setProperty') {
                    if ($row->metadata->property === 'name') {
                        self::$FestivityCollection[ $row->festivity->event_key ][ "name" ] = $NationalCalendarI18nData[ $row->festivity->event_key ];
                    }
                    if ($row->metadata->property === 'grade') {
                        self::$FestivityCollection[ $row->festivity->event_key ][ "grade" ] = $row->festivity->grade;
                        self::$FestivityCollection[ $row->festivity->event_key ][ "grade_lcl" ] = self::$LitGrade->i18n($row->festivity->grade, false);
                    }
                }
            }
            if (property_exists(self::$NationalData, "metadata") && property_exists(self::$NationalData->metadata, "missals")) {
                foreach (self::$NationalData->metadata->missals as $missal) {
                    $DataFile = RomanMissal::getSanctoraleFileName($missal);
                    if ($DataFile !== false) {
                        if (!file_exists($DataFile)) {
                            echo self::produceErrorResponse(StatusCode::NOT_FOUND, "Could not find resource file $DataFile");
                            die();
                        }
                        $PropriumDeSanctis = json_decode(file_get_contents($DataFile));
                        foreach ($PropriumDeSanctis as $idx => $festivity) {
                            $key = $festivity->event_key;
                            self::$FestivityCollection[ $key ] = (array) $festivity;
                            self::$FestivityCollection[ $key ][ "grade_lcl" ] = self::$LitGrade->i18n($festivity->grade, false);
                            self::$FestivityCollection[ $key ][ "common_lcl" ] = self::$LitCommon->c($festivity->common);
                            self::$FestivityCollection[ $key ][ "missal" ] = $missal;
                        }
                    }
                }
            }
        }
    }

    /**
     * Processes the Diocesan Calendar data and populates the FestivityCollection.
     *
     * This function checks if the DiocesanCalendar parameter and DiocesanData are set.
     * If so, it iterates through the DiocesanData 'litcal' property and adds new festivities
     * to the FestivityCollection with localized attributes and a modified event_key
     * incorporating the DiocesanCalendar parameter.
     *
     * @return void
     */
    private function processDiocesanCalendarData(): void
    {
        if ($this->EventsParams->DiocesanCalendar !== null && self::$DiocesanData !== null) {
            foreach (self::$DiocesanData->litcal as $key => $row) {
                self::$FestivityCollection[ $this->EventsParams->DiocesanCalendar . '_' . $key ] = (array) $row->festivity;
                self::$FestivityCollection[ $this->EventsParams->DiocesanCalendar . '_' . $key ][ "event_key" ] = $this->EventsParams->DiocesanCalendar . '_' . $key;
                self::$FestivityCollection[ $this->EventsParams->DiocesanCalendar . '_' . $key ][ "grade_lcl" ] = self::$LitGrade->i18n($row->festivity->grade, false);
                self::$FestivityCollection[ $this->EventsParams->DiocesanCalendar . '_' . $key ][ "common_lcl" ] = self::$LitCommon->c($row->festivity->common);
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
        header($_SERVER[ "SERVER_PROTOCOL" ] . StatusCode::toString($statusCode), true, $statusCode);
        $message = new \stdClass();
        $message->status = "ERROR";
        $message->response = StatusCode::toString($statusCode);
        $message->description = $description;
        $errResponse = json_encode($message);
        switch (self::$Core->getResponseContentType()) {
            case AcceptHeader::YAML:
                $response = json_decode($errResponse, true);
                return yaml_emit($response, YAML_UTF8_ENCODING);
                break;
            case AcceptHeader::JSON:
            default:
                return $errResponse;
                break;
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
        $responseObj = [
            "litcal_events" => array_values(self::$FestivityCollection),
            "settings" => [
                "locale" => $this->EventsParams->Locale,
                "national_calendar" => $this->EventsParams->NationalCalendar,
                "diocesan_calendar" => $this->EventsParams->DiocesanCalendar
            ]
        ];
        $response = json_encode($responseObj);
        $responseHash = md5($response);
        header("Etag: \"{$responseHash}\"");
        if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $responseHash) {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 304 Not Modified");
            header('Content-Length: 0');
        } else {
            switch (self::$Core->getResponseContentType()) {
                case AcceptHeader::YAML:
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
     * @param array $requestPathParts The path parameters from the request.
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
    public function init(array $requestPathParts = [])
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
