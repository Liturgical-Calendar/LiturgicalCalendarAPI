<?php

namespace Johnrdorazio\LitCal;

use Johnrdorazio\LitCal\enum\RomanMissal;
use Johnrdorazio\LitCal\enum\LitLocale;
use Johnrdorazio\LitCal\enum\LitGrade;
use Johnrdorazio\LitCal\enum\StatusCode;

class AllEvents
{
    private static array $SupportedNationalCalendars = [ "VATICAN" ];
    private static array $FestivityCollection        = [];
    private static array $LatinMissals               = [];
    private static ?object $GeneralIndex             = null;
    private static ?object $WiderRegionData          = null;
    private static ?string $NationalCalendar         = null;
    private static ?object $NationalData             = null;
    private static ?string $DiocesanCalendar         = null;
    private static ?object $DiocesanData             = null;
    private static ?LitGrade $LitGrade               = null;
    private static string $Locale                    = "la";

    // The liturgical rank of Proprium de Tempore events is defined in LitCalAPI rather than in resource files
    // So we can't gather this information just from the resource files
    // Which means we need to define them here manually
    private const PROPRIUM_DE_TEMPORE_RANKS = [
        "HolyThurs"         => LitGrade::HIGHER_SOLEMNITY,
        "GoodFri"           => LitGrade::HIGHER_SOLEMNITY,
        "EasterVigil"       => LitGrade::HIGHER_SOLEMNITY,
        "Easter"            => LitGrade::HIGHER_SOLEMNITY,
        "Christmas"         => LitGrade::HIGHER_SOLEMNITY,
        "Christmas2"        => LitGrade::FEAST_LORD,
        "MotherGod"         => LitGrade::SOLEMNITY,
        "Epiphany"          => LitGrade::HIGHER_SOLEMNITY,
        "Easter2"           => LitGrade::HIGHER_SOLEMNITY,
        "Easter3"           => LitGrade::HIGHER_SOLEMNITY,
        "Easter4"           => LitGrade::HIGHER_SOLEMNITY,
        "Easter5"           => LitGrade::HIGHER_SOLEMNITY,
        "Easter6"           => LitGrade::HIGHER_SOLEMNITY,
        "Easter7"           => LitGrade::HIGHER_SOLEMNITY,
        "Ascension"         => LitGrade::HIGHER_SOLEMNITY,
        "Pentecost"         => LitGrade::HIGHER_SOLEMNITY,
        "Advent1"           => LitGrade::HIGHER_SOLEMNITY,
        "Advent2"           => LitGrade::HIGHER_SOLEMNITY,
        "Advent3"           => LitGrade::HIGHER_SOLEMNITY,
        "Advent4"           => LitGrade::HIGHER_SOLEMNITY,
        "Lent1"             => LitGrade::HIGHER_SOLEMNITY,
        "Lent2"             => LitGrade::HIGHER_SOLEMNITY,
        "Lent3"             => LitGrade::HIGHER_SOLEMNITY,
        "Lent4"             => LitGrade::HIGHER_SOLEMNITY,
        "Lent5"             => LitGrade::HIGHER_SOLEMNITY,
        "PalmSun"           => LitGrade::HIGHER_SOLEMNITY,
        "Trinity"           => LitGrade::HIGHER_SOLEMNITY,
        "CorpusChristi"     => LitGrade::HIGHER_SOLEMNITY,
        "AshWednesday"      => LitGrade::HIGHER_SOLEMNITY,
        "MonHolyWeek"       => LitGrade::HIGHER_SOLEMNITY,
        "TueHolyWeek"       => LitGrade::HIGHER_SOLEMNITY,
        "WedHolyWeek"       => LitGrade::HIGHER_SOLEMNITY,
        "MonOctaveEaster"   => LitGrade::HIGHER_SOLEMNITY,
        "TueOctaveEaster"   => LitGrade::HIGHER_SOLEMNITY,
        "WedOctaveEaster"   => LitGrade::HIGHER_SOLEMNITY,
        "ThuOctaveEaster"   => LitGrade::HIGHER_SOLEMNITY,
        "FriOctaveEaster"   => LitGrade::HIGHER_SOLEMNITY,
        "SatOctaveEaster"   => LitGrade::HIGHER_SOLEMNITY,
        "SacredHeart"       => LitGrade::SOLEMNITY,
        "ChristKing"        => LitGrade::SOLEMNITY,
        "BaptismLord"       => LitGrade::FEAST_LORD,
        "HolyFamily"        => LitGrade::FEAST_LORD,
        "OrdSunday2"        => LitGrade::FEAST_LORD,
        "OrdSunday3"        => LitGrade::FEAST_LORD,
        "OrdSunday4"        => LitGrade::FEAST_LORD,
        "OrdSunday5"        => LitGrade::FEAST_LORD,
        "OrdSunday6"        => LitGrade::FEAST_LORD,
        "OrdSunday7"        => LitGrade::FEAST_LORD,
        "OrdSunday8"        => LitGrade::FEAST_LORD,
        "OrdSunday9"        => LitGrade::FEAST_LORD,
        "OrdSunday10"       => LitGrade::FEAST_LORD,
        "OrdSunday11"       => LitGrade::FEAST_LORD,
        "OrdSunday12"       => LitGrade::FEAST_LORD,
        "OrdSunday13"       => LitGrade::FEAST_LORD,
        "OrdSunday14"       => LitGrade::FEAST_LORD,
        "OrdSunday15"       => LitGrade::FEAST_LORD,
        "OrdSunday16"       => LitGrade::FEAST_LORD,
        "OrdSunday17"       => LitGrade::FEAST_LORD,
        "OrdSunday18"       => LitGrade::FEAST_LORD,
        "OrdSunday19"       => LitGrade::FEAST_LORD,
        "OrdSunday20"       => LitGrade::FEAST_LORD,
        "OrdSunday21"       => LitGrade::FEAST_LORD,
        "OrdSunday22"       => LitGrade::FEAST_LORD,
        "OrdSunday23"       => LitGrade::FEAST_LORD,
        "OrdSunday24"       => LitGrade::FEAST_LORD,
        "OrdSunday25"       => LitGrade::FEAST_LORD,
        "OrdSunday26"       => LitGrade::FEAST_LORD,
        "OrdSunday27"       => LitGrade::FEAST_LORD,
        "OrdSunday28"       => LitGrade::FEAST_LORD,
        "OrdSunday29"       => LitGrade::FEAST_LORD,
        "OrdSunday30"       => LitGrade::FEAST_LORD,
        "OrdSunday31"       => LitGrade::FEAST_LORD,
        "OrdSunday32"       => LitGrade::FEAST_LORD,
        "OrdSunday33"       => LitGrade::FEAST_LORD,
        "OrdSunday34"       => LitGrade::FEAST_LORD,
        "ImmaculateHeart"   => LitGrade::MEMORIAL
    ];
    private const PROPRIUM_DE_TEMPORE_RED = [ "SacredHeart", "Pentecost", "GoodFri", "PalmSun", "SacredHeart" ];
    private const PROPROIUM_DE_TEMPORE_PURPLE = [ "Advent1", "Advent2", "Advent4", "AshWednesday", "Lent1", "Lent2", "Lent3", "Lent5" ];
    private const PROPRIUM_DE_TEMPORE_PINK = [ "Advent3", "Lent4" ];

    private static function retrieveLatinMissals(): void
    {
        self::$LatinMissals = array_filter(RomanMissal::$values, function ($item) {
            return str_starts_with($item, "VATICAN_");
        });
    }

    private static function retrieveNationalCalendars(): void
    {
        $directories = array_map('basename', glob('nations/*', GLOB_ONLYDIR));
        foreach ($directories as $directory) {
            if (file_exists("nations/$directory/$directory.json")) {
                self::$SupportedNationalCalendars[] = $directory;
            }
        }
    }

    private static function retrieveGeneralIndex(): void
    {
        $GeneralIndexContents = file_exists("nations/index.json") ? file_get_contents("nations/index.json") : null;
        if (null === $GeneralIndexContents || false === $GeneralIndexContents) {
            echo self::produceErrorResponse(StatusCode::NOT_FOUND, "path nations/index.json not found");
            die();
        }
        self::$GeneralIndex = json_decode($GeneralIndexContents);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, json_last_error_msg());
            die();
        }
    }

    private static function handleRequestParams(): void
    {
        self::$Locale = isset($_GET["locale"]) && LitLocale::isValid($_GET["locale"]) ? $_GET["locale"] : "la";
        self::$NationalCalendar = isset($_GET["nationalcalendar"]) && in_array(strtoupper($_GET["nationalcalendar"]), self::$SupportedNationalCalendars)
            ? strtoupper($_GET["nationalcalendar"])
            : null;
        self::$DiocesanCalendar = isset($_GET["diocesancalendar"]) && preg_match("/^[a-zA-Z]{3,45}$/", $_GET["diocesancalendar"])
            ? strtoupper($_GET["diocesancalendar"])
            : null;
    }

    private static function loadDiocesanData(): void
    {
        if (self::$DiocesanCalendar !== null && property_exists(self::$GeneralIndex, self::$DiocesanCalendar)) {
            self::$NationalCalendar = self::$GeneralIndex->{self::$DiocesanCalendar}->nation;
            $diocesanDataFile = self::$GeneralIndex->{self::$DiocesanCalendar}->path;
            if (file_exists($diocesanDataFile)) {
                self::$DiocesanData = json_decode(file_get_contents($diocesanDataFile));
            }
        }
    }

    private static function loadNationalAndWiderRegionData(): void
    {
        if (self::$NationalCalendar !== null) {
            $nationalDataFile = "nations/" . self::$NationalCalendar . "/" . self::$NationalCalendar . ".json";
            if (file_exists($nationalDataFile)) {
                self::$NationalData = json_decode(file_get_contents($nationalDataFile));
                if (json_last_error() === JSON_ERROR_NONE) {
                    if (property_exists(self::$NationalData, "Settings") && property_exists(self::$NationalData->Settings, "Locale")) {
                        self::$Locale = self::$NationalData->Settings->Locale;
                    }
                    if (property_exists(self::$NationalData, "Metadata") && property_exists(self::$NationalData->Metadata, "WiderRegion")) {
                        $widerRegionDataFile = self::$NationalData->Metadata->WiderRegion->jsonFile;
                        $widerRegionI18nFile = self::$NationalData->Metadata->WiderRegion->i18nFile;
                        if (file_exists($widerRegionI18nFile)) {
                            $widerRegionI18nData = json_decode(file_get_contents($widerRegionI18nFile));
                            if (json_last_error() === JSON_ERROR_NONE && file_exists($widerRegionDataFile)) {
                                self::$WiderRegionData = json_decode(file_get_contents($widerRegionDataFile));
                                if (json_last_error() === JSON_ERROR_NONE && property_exists(self::$WiderRegionData, "LitCal")) {
                                    foreach (self::$WiderRegionData->LitCal as $idx => $value) {
                                        $tag = $value->Festivity->tag;
                                        self::$WiderRegionData->LitCal[$idx]->Festivity->name = $widerRegionI18nData->{ $tag };
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    private static function setLocale(): void
    {
        self::$Locale = self::$Locale !== "LA" && self::$Locale !== "la" ? \Locale::getPrimaryLanguage(self::$Locale) : "la";
        $localeArray = [
            self::$Locale . '.utf8',
            self::$Locale . '.UTF-8',
            self::$Locale,
            self::$Locale . '_' . strtoupper(self::$Locale) . '.utf8',
            self::$Locale . '_' . strtoupper(self::$Locale) . '.UTF-8',
            self::$Locale . '_' . strtoupper(self::$Locale),
            self::$Locale . '.utf8',
            self::$Locale . '.UTF-8',
            self::$Locale
        ];
        setlocale(LC_ALL, $localeArray);
        bindtextdomain("litcal", "i18n");
        textdomain("litcal");
        self::$LitGrade = new LitGrade(self::$Locale);
    }

    private static function processMissalData(): void
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
                    $key = $festivity[ "TAG" ];
                    self::$FestivityCollection[ $key ] = $festivity;
                    self::$FestivityCollection[ $key ][ "MISSAL" ] = $LatinMissal;
                    self::$FestivityCollection[ $key ][ "GRADE_LCL" ] = self::$LitGrade->i18n($festivity["GRADE"], false);
                }
                // There may or may not be a related translation file; if there is, we get the translated name from here
                $I18nPath = RomanMissal::getSanctoraleI18nFilePath($LatinMissal);
                if ($I18nPath !== false) {
                    if (false === file_exists($I18nPath . "/" . self::$Locale . ".json")) {
                        echo self::produceErrorResponse(StatusCode::NOT_FOUND, "Could not find resource $I18nPath");
                        die();
                    }
                    $NAME = json_decode(file_get_contents($I18nPath . "/" . self::$Locale . ".json"), true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        echo self::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, json_last_error_msg());
                        die();
                    }
                    foreach ($DATA as $idx => $festivity) {
                        $key = $festivity[ "TAG" ];
                        self::$FestivityCollection[ $key ][ "NAME" ] = $NAME[ $key ];
                    }
                }
            }
        }
    }

    private static function processPropriumDeTemporeData(): void
    {
        $DataFile = 'data/propriumdetempore.json';
        $I18nFile = 'data/propriumdetempore/' . self::$Locale . ".json";
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

        foreach ($DATA as $key => $readings) {
            if (false === array_key_exists($key, self::$FestivityCollection)) {
                self::$FestivityCollection[ $key ] = $readings;
                self::$FestivityCollection[ $key ][ "TAG" ] = $key;
                self::$FestivityCollection[ $key ][ "NAME" ] = $NAME[ $key ];
                self::$FestivityCollection[ $key ][ "GRADE" ] = self::PROPRIUM_DE_TEMPORE_RANKS[ $key ];
                self::$FestivityCollection[ $key ][ "GRADE_LCL" ] = self::$LitGrade->i18n(self::PROPRIUM_DE_TEMPORE_RANKS[ $key ], false);
                self::$FestivityCollection[ $key ][ "COMMON" ] = [];
                self::$FestivityCollection[ $key ][ "CALENDAR" ] = "GENERAL ROMAN";
                if (in_array($key, self::PROPRIUM_DE_TEMPORE_RED)) {
                    self::$FestivityCollection[ $key ][ "COLOR" ] = [ "red" ];
                } elseif (in_array($key, self::PROPROIUM_DE_TEMPORE_PURPLE)) {
                    self::$FestivityCollection[ $key ][ "COLOR" ] = [ "purple" ];
                } elseif (in_array($key, self::PROPRIUM_DE_TEMPORE_PINK)) {
                    self::$FestivityCollection[ $key ][ "COLOR" ] = [ "pink" ];
                } else {
                    self::$FestivityCollection[ $key ][ "COLOR" ] = [ "white" ];
                }
            }
        }
    }

    private static function processMemorialsFromDecreesData(): void
    {
        $DataFile = 'data/memorialsFromDecrees/memorialsFromDecrees.json';
        $I18nFile = 'data/memorialsFromDecrees/i18n/' . self::$Locale . ".json";
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
            $key = $festivity[ "Festivity" ][ "TAG" ];
            if (false === array_key_exists($key, self::$FestivityCollection)) {
                self::$FestivityCollection[ $key ] = $festivity[ "Festivity" ];
                self::$FestivityCollection[ $key ][ "NAME" ] = $NAME[ $key ];
                if (array_key_exists("decreeLangs", $festivity[ "Metadata" ])) {
                    $decreeURL = sprintf($festivity[ "Metadata" ][ "decreeURL" ], 'LA');
                    if (array_key_exists(strtoupper(self::$Locale), $festivity[ "Metadata" ][ "decreeLangs" ])) {
                        $decreeLang = $festivity[ "Metadata" ][ "decreeLangs" ][ strtoupper(self::$Locale) ];
                        $decreeURL = sprintf($festivity[ "Metadata" ][ "decreeURL" ], $decreeLang);
                    }
                } else {
                    $decreeURL = $festivity[ "Metadata" ][ "decreeURL" ];
                }
                self::$FestivityCollection[ $key ][ "DECREE" ] = $decreeURL;
            } elseif ($festivity[ "Metadata" ][ "action" ] === 'setProperty') {
                if ($festivity[ "Metadata" ][ "property" ] === 'name') {
                    self::$FestivityCollection[ $key ][ "NAME" ] = $NAME[ $key ];
                } elseif ($festivity[ "Metadata" ][ "property" ] === 'grade') {
                    self::$FestivityCollection[ $key ][ "GRADE" ] = $festivity[ "Festivity" ][ "GRADE" ];
                }
            } elseif ($festivity[ "Metadata" ][ "action" ] === 'makeDoctor') {
                self::$FestivityCollection[ $key ][ "NAME" ] = $NAME[ $key ];
            }
            self::$FestivityCollection[ $key ][ "GRADE_LCL" ] = self::$LitGrade->i18n(self::$FestivityCollection[ $key ][ "GRADE" ], false);
        }
    }

    private static function processNationalCalendarData(): void
    {
        if (self::$NationalCalendar !== null && self::$NationalData !== null) {
            if (self::$WiderRegionData !== null && property_exists(self::$WiderRegionData, "LitCal")) {
                foreach (self::$WiderRegionData->LitCal as $row) {
                    if ($row->Metadata->action === 'createNew') {
                        $key = $row->Festivity->tag;
                        self::$FestivityCollection[ $key ] = [];
                        foreach ($row->Festivity as $prop => $value) {
                            $prop = strtoupper($prop);
                            self::$FestivityCollection[ $key ][ $prop ] = $value;
                        }
                        self::$FestivityCollection[ $key ][ "GRADE_LCL" ] = self::$LitGrade->i18n($row->Festivity->grade, false);
                    }
                }
            }
            foreach (self::$NationalData->LitCal as $row) {
                if ($row->Metadata->action === 'createNew') {
                    $key = $row->Festivity->tag;
                    $temp = (array) $row->Festivity;
                    self::$FestivityCollection[ $key ] = array_change_key_case($temp, CASE_UPPER);
                    self::$FestivityCollection[ $key ][ "GRADE_LCL" ] = self::$LitGrade->i18n($row->Festivity->grade, false);
                }
            }
            if (property_exists(self::$NationalData, "Metadata") && property_exists(self::$NationalData->Metadata, "Missals")) {
                if (self::$NationalData->Metadata->Region === 'UNITED STATES') {
                    self::$NationalData->Metadata->Region = 'USA';
                }
                foreach (self::$NationalData->Metadata->Missals as $missal) {
                    $DataFile = RomanMissal::getSanctoraleFileName($missal);
                    if ($DataFile !== false) {
                        if (!file_exists($DataFile)) {
                            echo self::produceErrorResponse(StatusCode::NOT_FOUND, "Could not find resource file $DataFile");
                            die();
                        }
                        $PropriumDeSanctis = json_decode(file_get_contents($DataFile));
                        foreach ($PropriumDeSanctis as $idx => $festivity) {
                            $key = $festivity->TAG;
                            self::$FestivityCollection[ $key ] = (array) $festivity;
                            self::$FestivityCollection[ $key ][ "GRADE_LCL" ] = self::$LitGrade->i18n($festivity->GRADE, false);
                            self::$FestivityCollection[ $key ][ "MISSAL" ] = $missal;
                        }
                    }
                }
            }
        }
    }

    private static function processDiocesanCalendarData(): void
    {
        if (self::$DiocesanCalendar !== null && self::$DiocesanData !== null) {
            foreach (self::$DiocesanData->LitCal as $key => $festivity) {
                $temp = (array) $festivity->Festivity;
                self::$FestivityCollection[ self::$DiocesanCalendar . '_' . $key ] = array_change_key_case($temp, CASE_UPPER);
                self::$FestivityCollection[ self::$DiocesanCalendar . '_' . $key ][ "TAG" ] = self::$DiocesanCalendar . '_' . $key;
                self::$FestivityCollection[ self::$DiocesanCalendar . '_' . $key ][ "GRADE_LCL" ] = self::$LitGrade->i18n($festivity->Festivity->grade, false);
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
        return json_encode($message);
    }

    private static function produceResponse(): void
    {
        $responseObj = [
            "LitCalAllFestivities" => self::$FestivityCollection,
            "Settings" => [
                "Locale" => self::$Locale,
                "NationalCalendar" => self::$NationalCalendar,
                "DiocesanCalendar" => self::$DiocesanCalendar
            ]
        ];
        $response = json_encode($responseObj);
        $responseHash = md5($response);
        header("Etag: \"{$responseHash}\"");
        if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $responseHash) {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 304 Not Modified");
            header('Content-Length: 0');
        } else {
            echo $response;
        }
    }

    public static function init()
    {
        $requestHeaders = getallheaders();
        if (isset($requestHeaders[ "Origin" ])) {
            header("Access-Control-Allow-Origin: {$requestHeaders[ "Origin" ]}");
            header('Access-Control-Allow-Credentials: true');
        } else {
            header('Access-Control-Allow-Origin: *');
        }
        header('Access-Control-Max-Age: 86400');
        // cache for 1 day
        header('Cache-Control: must-revalidate, max-age=259200');
        header('Content-Type: application/json');

        self::retrieveLatinMissals();
        self::retrieveNationalCalendars();
        self::retrieveGeneralIndex();
        self::handleRequestParams();
        self::loadDiocesanData();
        self::loadNationalAndWiderRegionData();
        self::setLocale();
        self::processMissalData();
        self::processPropriumDeTemporeData();
        self::processMemorialsFromDecreesData();
        self::processNationalCalendarData();
        self::processDiocesanCalendarData();
        self::produceResponse();
    }
}
