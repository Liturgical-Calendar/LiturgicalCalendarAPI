<?php

namespace Johnrdorazio\LitCal\Paths;

use Johnrdorazio\LitCal\APICore;
use Johnrdorazio\LitCal\Enum\RomanMissal;
use Johnrdorazio\LitCal\Enum\LitGrade;
use Johnrdorazio\LitCal\Enum\LitCommon;
use Johnrdorazio\LitCal\Enum\StatusCode;
use Johnrdorazio\LitCal\Enum\RequestMethod;
use Johnrdorazio\LitCal\Enum\RequestContentType;
use Johnrdorazio\LitCal\Enum\AcceptHeader;
use Johnrdorazio\LitCal\Params\EventsParams;

class Events
{
    public static APICore $APICore;
    private static array $FestivityCollection = [];
    private static array $LatinMissals        = [];
    private static ?object $GeneralIndex      = null;
    private static ?object $WiderRegionData   = null;
    private static ?object $NationalData      = null;
    private static ?object $DiocesanData      = null;
    private static ?LitGrade $LitGrade        = null;
    private static ?LitCommon $LitCommon      = null;
    private static array $requestPathParts    = [];
    private EventsParams $EventsParams;

    public function __construct(array $requestPathParts = [])
    {
        self::$APICore = new APICore();
        self::$requestPathParts = $requestPathParts;
        $this->EventsParams = new EventsParams();
    }

    private static function retrieveLatinMissals(): void
    {
        self::$LatinMissals = array_filter(RomanMissal::$values, function ($item) {
            return str_starts_with($item, "EDITIO_TYPICA_");
        });
    }

    private static function retrieveGeneralIndex(): void
    {
        $GeneralIndexContents = file_exists("data/nations/index.json") ? file_get_contents("data/nations/index.json") : null;
        if (null === $GeneralIndexContents || false === $GeneralIndexContents) {
            echo self::produceErrorResponse(StatusCode::NOT_FOUND, "path data/nations/index.json not found");
            die();
        }
        self::$GeneralIndex = json_decode($GeneralIndexContents);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, json_last_error_msg());
            die();
        }
    }

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

    private function validatePostParams(): void
    {
        if (self::$APICore->getRequestContentType() === RequestContentType::JSON) {
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
        } elseif (self::$APICore->getRequestContentType() === RequestContentType::FORMDATA) {
            if (count($_POST)) {
                if (false === $this->EventsParams->setData($_POST)) {
                    echo self::produceErrorResponse(StatusCode::BAD_REQUEST, EventsParams::getLastErrorMessage());
                    die();
                }
            }
        }
    }

    private function validateGetParams(): void
    {
        if (count($_GET)) {
            if (false === $this->EventsParams->setData($_GET)) {
                echo self::produceErrorResponse(StatusCode::BAD_REQUEST, EventsParams::getLastErrorMessage());
                die();
            }
        }
    }

    private function handleRequestParams(): void
    {
        if (count(self::$requestPathParts)) {
            $this->validateRequestPathParams();
        }

        switch (self::$APICore->getRequestMethod()) {
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
                    . implode(' and ', self::$APICore->getAllowedRequestMethods())
                    . ', but your Request Method was '
                    . self::$APICore->getRequestMethod();
                echo self::produceErrorResponse(StatusCode::METHOD_NOT_ALLOWED, $description);
                die();
        }
    }

    private function loadDiocesanData(): void
    {
        if ($this->EventsParams->DiocesanCalendar !== null) {
            if (property_exists(self::$GeneralIndex, $this->EventsParams->DiocesanCalendar)) {
                $this->EventsParams->NationalCalendar = self::$GeneralIndex->{$this->EventsParams->DiocesanCalendar}->nation;
                $diocesanDataFile = self::$GeneralIndex->{$this->EventsParams->DiocesanCalendar}->path;
                if (file_exists($diocesanDataFile)) {
                    self::$DiocesanData = json_decode(file_get_contents($diocesanDataFile));
                } else {
                    echo self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "no data file found for diocese {$this->EventsParams->DiocesanCalendar}");
                    die();
                }
            } else {
                $description = "unknown diocese `{$this->EventsParams->DiocesanCalendar}`, supported values are: ["
                    . implode(',', array_keys(get_object_vars(self::$GeneralIndex))) . "]";
                echo self::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
                die();
            }
        }
    }

    private function loadNationalAndWiderRegionData(): void
    {
        if ($this->EventsParams->NationalCalendar !== null) {
            $nationalDataFile = "nations/" . $this->EventsParams->NationalCalendar . "/" . $this->EventsParams->NationalCalendar . ".json";
            if (file_exists($nationalDataFile)) {
                self::$NationalData = json_decode(file_get_contents($nationalDataFile));
                if (json_last_error() === JSON_ERROR_NONE) {
                    if (property_exists(self::$NationalData, "settings") && property_exists(self::$NationalData->settings, "locale")) {
                        $this->EventsParams->Locale = self::$NationalData->settings->locale;
                    }
                    if (property_exists(self::$NationalData, "metadata") && property_exists(self::$NationalData->metadata, "wider_region")) {
                        $widerRegionDataFile = self::$NationalData->metadata->wider_region->json_file;
                        $widerRegionI18nFile = self::$NationalData->metadata->wider_region->i18n_file;
                        if (file_exists($widerRegionI18nFile)) {
                            $widerRegionI18nData = json_decode(file_get_contents($widerRegionI18nFile));
                            if (json_last_error() === JSON_ERROR_NONE && file_exists($widerRegionDataFile)) {
                                self::$WiderRegionData = json_decode(file_get_contents($widerRegionDataFile));
                                if (json_last_error() === JSON_ERROR_NONE && property_exists(self::$WiderRegionData, "litcal")) {
                                    foreach (self::$WiderRegionData->litcal as $idx => $value) {
                                        $event_key = $value->festivity->event_key;
                                        self::$WiderRegionData->litcal[$idx]->festivity->name = $widerRegionI18nData->{ $event_key };
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    private function setLocale(): void
    {
        $this->EventsParams->Locale = \Locale::getPrimaryLanguage($this->EventsParams->Locale);
        $localeArray = [
            $this->EventsParams->Locale . '.utf8',
            $this->EventsParams->Locale . '.UTF-8',
            $this->EventsParams->Locale,
            $this->EventsParams->Locale . '_' . strtoupper($this->EventsParams->Locale) . '.utf8',
            $this->EventsParams->Locale . '_' . strtoupper($this->EventsParams->Locale) . '.UTF-8',
            $this->EventsParams->Locale . '_' . strtoupper($this->EventsParams->Locale),
            $this->EventsParams->Locale . '.utf8',
            $this->EventsParams->Locale . '.UTF-8',
            $this->EventsParams->Locale
        ];
        setlocale(LC_ALL, $localeArray);
        bindtextdomain("litcal", "i18n");
        textdomain("litcal");
        self::$LitGrade = new LitGrade($this->EventsParams->Locale);
        self::$LitCommon = new LitCommon($this->EventsParams->Locale);
    }

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
                foreach ($DATA as $idx => $festivity) {
                    $key = $festivity[ "event_key" ];
                    self::$FestivityCollection[ $key ] = $festivity;
                    self::$FestivityCollection[ $key ][ "missal" ] = $LatinMissal;
                    self::$FestivityCollection[ $key ][ "grade_lcl" ] = self::$LitGrade->i18n($festivity["grade"], false);
                    self::$FestivityCollection[ $key ][ "common_lcl" ] = self::$LitCommon->c($festivity["common"]);
                }
                // There may or may not be a related translation file; if there is, we get the translated name from here
                $I18nPath = RomanMissal::getSanctoraleI18nFilePath($LatinMissal);
                if ($I18nPath !== false) {
                    if (false === file_exists($I18nPath . "/" . $this->EventsParams->Locale . ".json")) {
                        echo self::produceErrorResponse(StatusCode::NOT_FOUND, "Could not find resource $I18nPath");
                        die();
                    }
                    $NAME = json_decode(file_get_contents($I18nPath . "/" . $this->EventsParams->Locale . ".json"), true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        echo self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, json_last_error_msg());
                        die();
                    }
                    foreach ($DATA as $idx => $festivity) {
                        $key = $festivity[ "event_key" ];
                        self::$FestivityCollection[ $key ][ "name" ] = $NAME[ $key ];
                    }
                }
            }
        }
    }

    private function processPropriumDeTemporeData(): void
    {
        $DataFile = 'data/missals/propriumdetempore/propriumdetempore.json';
        $I18nFile = 'data/missals/propriumdetempore/i18n/' . $this->EventsParams->Locale . ".json";
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

    private function processMemorialsFromDecreesData(): void
    {
        $DataFile = 'data/decrees/decrees.json';
        $I18nFile = 'data/decrees/i18n/' . $this->EventsParams->Locale . ".json";
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
                if (array_key_exists("languages", $festivity[ "metadata" ])) {
                    $decreeURL = sprintf($festivity[ "metadata" ][ "url" ], 'LA');
                    if (array_key_exists($this->EventsParams->Locale, $festivity[ "metadata" ][ "languages" ])) {
                        $decreeLang = $festivity[ "metadata" ][ "languages" ][ $this->EventsParams->Locale ];
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
            foreach (self::$NationalData->litcal as $row) {
                if ($row->metadata->action === 'createNew') {
                    $key = $row->festivity->event_key;
                    self::$FestivityCollection[ $key ] = (array) $row->festivity;
                    self::$FestivityCollection[ $key ][ "grade_lcl" ] = self::$LitGrade->i18n($row->festivity->grade, false);
                    self::$FestivityCollection[ $key ][ "common_lcl" ] = self::$LitCommon->c($row->festivity->common);
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

    private static function produceErrorResponse(int $statusCode, string $description): string
    {
        header($_SERVER[ "SERVER_PROTOCOL" ] . StatusCode::toString($statusCode), true, $statusCode);
        $message = new \stdClass();
        $message->status = "ERROR";
        $message->response = StatusCode::toString($statusCode);
        $message->description = $description;
        $errResponse = json_encode($message);
        switch (self::$APICore->getResponseContentType()) {
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

    private function produceResponse(): void
    {
        $responseObj = [
            "litcal_events" => self::$FestivityCollection,
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
            switch (self::$APICore->getResponseContentType()) {
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

    public function init(array $requestPathParts = [])
    {
        self::$APICore->init();
        self::$APICore->validateAcceptHeader(true);
        self::$APICore->setResponseContentTypeHeader();

        self::$requestPathParts = $requestPathParts;
        self::retrieveLatinMissals();
        self::retrieveGeneralIndex();
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
