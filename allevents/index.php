<?php

// ini_set('display_errors', 1);
// error_reporting(E_ALL);
ini_set('date.timezone', 'Europe/Vatican');

require_once '../includes/enums/LitLocale.php';
require_once '../includes/enums/RomanMissal.php';
require_once '../includes/enums/LitGrade.php';
require_once '../includes/enums/StatusCode.php';

use LitCal\enum\RomanMissal;
use LitCal\enum\LitLocale;
use LitCal\enum\LitGrade;
use LitCal\enum\StatusCode;

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


const STATUS_CODES = [
    StatusCode::METHOD_NOT_ALLOWED     => " 405 Method Not Allowed",
    StatusCode::UNSUPPORTED_MEDIA_TYPE => " 415 Unsupported Media Type",
    StatusCode::UNPROCESSABLE_CONTENT  => " 422 Unprocessable Content",
    StatusCode::SERVICE_UNAVAILABLE    => " 503 Service Unavailable",
    StatusCode::NOT_FOUND              => " 404 Not Found"
];

function produceErrorResponse(int $statusCode, string $description): string
{
    header($_SERVER[ "SERVER_PROTOCOL" ] . STATUS_CODES[ $statusCode ], true, $statusCode);
    $message = new \stdClass();
    $message->status = "ERROR";
    $message->response = $statusCode === 404 ? "Resource not Found" : "Service Unavailable";
    $message->description = $description;
    return json_encode($message);
}

$FestivityCollection = [];

$LatinMissals = array_filter(RomanMissal::$values, function ($item) {
    return str_starts_with($item, "VATICAN_");
});

$SUPPORTED_NATIONAL_CALENDARS = [ "VATICAN" ];
$directories = array_map('basename', glob('../nations/*', GLOB_ONLYDIR));
foreach ($directories as $directory) {
    if (file_exists("../nations/$directory/$directory.json")) {
        $SUPPORTED_NATIONAL_CALENDARS[] = $directory;
    }
}

$GeneralIndexContents = file_exists("../nations/index.json") ? file_get_contents("../nations/index.json") : null;
if (null === $GeneralIndexContents || false === $GeneralIndexContents) {
    produceErrorResponse(StatusCode::NOT_FOUND, "path ../nations/index.json not found");
    die();
}
$GeneralIndex = json_decode($GeneralIndexContents);
if (json_last_error() !== JSON_ERROR_NONE) {
    produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, json_last_error_msg());
    die();
}

$Locale = isset($_GET["locale"]) && LitLocale::isValid($_GET["locale"]) ? $_GET["locale"] : "la";
$NationalCalendar = isset($_GET["nationalcalendar"]) && in_array(strtoupper($_GET["nationalcalendar"]), $SUPPORTED_NATIONAL_CALENDARS)
    ? strtoupper($_GET["nationalcalendar"])
    : null;
$DiocesanCalendar = isset($_GET["diocesancalendar"])
    ? strtoupper($_GET["diocesancalendar"])
    : null;
$NationalData       = null;
$WiderRegionData    = null;
$DiocesanData       = null;
if ($DiocesanCalendar !== null && property_exists($GeneralIndex, $DiocesanCalendar)) {
    $NationalCalendar = $GeneralIndex->{$DiocesanCalendar}->nation;
    $diocesanDataFile = $GeneralIndex->{$DiocesanCalendar}->path;
    if (file_exists($diocesanDataFile)) {
        $DiocesanData = json_decode(file_get_contents($diocesanDataFile));
    }
}

if ($NationalCalendar !== null) {
    $nationalDataFile = "../nations/{$NationalCalendar}/{$NationalCalendar}.json";
    if (file_exists($nationalDataFile)) {
        $NationalData = json_decode(file_get_contents($nationalDataFile));
        if (json_last_error() === JSON_ERROR_NONE) {
            if (property_exists($NationalData, "Settings") && property_exists($NationalData->Settings, "Locale")) {
                $Locale = $NationalData->Settings->Locale;
            }
            if (property_exists($NationalData, "Metadata") && property_exists($NationalData->Metadata, "WiderRegion")) {
                $widerRegionDataFile = $NationalData->Metadata->WiderRegion->jsonFile;
                $widerRegionI18nFile = $NationalData->Metadata->WiderRegion->i18nFile;
                if (file_exists($widerRegionI18nFile)) {
                    $widerRegionI18nData = json_decode(file_get_contents($widerRegionI18nFile));
                    if (json_last_error() === JSON_ERROR_NONE && file_exists($widerRegionDataFile)) {
                        $WiderRegionData = json_decode(file_get_contents($widerRegionDataFile));
                        if (json_last_error() === JSON_ERROR_NONE && property_exists($WiderRegionData, "LitCal")) {
                            foreach ($WiderRegionData->LitCal as $idx => $value) {
                                $tag = $value->Festivity->tag;
                                $WiderRegionData->LitCal[$idx]->Festivity->name = $widerRegionI18nData->{ $tag };
                            }
                        }
                    }
                }
            }
        }
    }
}

$Locale = $Locale !== "LA" && $Locale !== "la" ? Locale::getPrimaryLanguage($Locale) : "la";
$localeArray = [
    $Locale . '.utf8',
    $Locale . '.UTF-8',
    $Locale,
    $Locale . '_' . strtoupper($Locale) . '.utf8',
    $Locale . '_' . strtoupper($Locale) . '.UTF-8',
    $Locale . '_' . strtoupper($Locale),
    $Locale . '.utf8',
    $Locale . '.UTF-8',
    $Locale
];
$systemLocale = setlocale(LC_ALL, $localeArray);
bindtextdomain("litcal", "../i18n");
textdomain("litcal");
$LitGrade = new LitGrade($Locale);
foreach ($LatinMissals as $LatinMissal) {
    $DataFile = '../' . RomanMissal::getSanctoraleFileName($LatinMissal);
    if ($DataFile !== false) {
        $I18nPath = '../' . RomanMissal::getSanctoraleI18nFilePath($LatinMissal);
        if ($I18nPath !== false && file_exists($I18nPath . "/" . $Locale . ".json")) {
            $NAME = json_decode(file_get_contents($I18nPath . "/" . $Locale . ".json"), true);
            $DATA = json_decode(file_get_contents($DataFile), true);
            foreach ($DATA as $idx => $festivity) {
                $key = $festivity[ "TAG" ];
                $FestivityCollection[ $key ] = $festivity;
                $FestivityCollection[ $key ][ "NAME" ] = $NAME[ $key ];
                $FestivityCollection[ $key ][ "MISSAL" ] = $LatinMissal;
                $FestivityCollection[ $key ][ "GRADE_LCL" ] = $LitGrade->i18n($festivity["GRADE"], false);
            }
        } else {
            produceErrorResponse(StatusCode::NOT_FOUND, "Could not find resource $I18nPath");
            die();
        }
    } else {
        produceErrorResponse(StatusCode::NOT_FOUND, "Could not find resource $DataFile");
        die();
    }
}

// The liturgical rank of Proprium de Tempore events is defined in LitCalAPI rather than in resource files
// So we can't gather this information just from the resource files
// Which means we need to define them here manually
$PropriumDeTemporeRanks = [
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
$PropriumDeTemporeRed = [ "SacredHeart", "Pentecost", "GoodFri", "PalmSun", "SacredHeart" ];
$PropriumDeTemporePurple = [ "Advent1", "Advent2", "Advent4", "AshWednesday", "Lent1", "Lent2", "Lent3", "Lent5" ];
$PropriumDeTemporePink = [ "Advent3", "Lent4" ];
$DataFile = '../data/propriumdetempore.json';
$I18nFile = '../data/propriumdetempore/' . $Locale . ".json";

if (!file_exists($DataFile) || !file_exists($I18nFile)) {
    produceErrorResponse(StatusCode::NOT_FOUND, "Could not find resource file $DataFile or resource file $I18nFile");
    die();
}
$DATA = json_decode(file_get_contents($DataFile), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, json_last_error_msg());
    die();
}
$NAME = json_decode(file_get_contents($I18nFile), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, json_last_error_msg());
    die();
}

foreach ($DATA as $key => $readings) {
    if (false === array_key_exists($key, $FestivityCollection)) {
        $FestivityCollection[ $key ] = $readings;
        $FestivityCollection[ $key ][ "TAG" ] = $key;
        $FestivityCollection[ $key ][ "NAME" ] = $NAME[ $key ];
        $FestivityCollection[ $key ][ "GRADE" ] = $PropriumDeTemporeRanks[ $key ];
        $FestivityCollection[ $key ][ "GRADE_LCL" ] = $LitGrade->i18n($PropriumDeTemporeRanks[ $key ], false);
        $FestivityCollection[ $key ][ "COMMON" ] = [];
        $FestivityCollection[ $key ][ "CALENDAR" ] = "GENERAL ROMAN";
        if (in_array($key, $PropriumDeTemporeRed)) {
            $FestivityCollection[ $key ][ "COLOR" ] = [ "red" ];
        } elseif (in_array($key, $PropriumDeTemporePurple)) {
            $FestivityCollection[ $key ][ "COLOR" ] = [ "purple" ];
        } elseif (in_array($key, $PropriumDeTemporePink)) {
            $FestivityCollection[ $key ][ "COLOR" ] = [ "pink" ];
        } else {
            $FestivityCollection[ $key ][ "COLOR" ] = [ "white" ];
        }
    }
}

$DataFile = '../data/memorialsFromDecrees/memorialsFromDecrees.json';
$I18nFile = '../data/memorialsFromDecrees/i18n/' . $Locale . ".json";
if (!file_exists($DataFile) || !file_exists($I18nFile)) {
    produceErrorResponse(StatusCode::NOT_FOUND, "Could not find resource file $DataFile or resource file $I18nFile");
    die();
}

$DATA = json_decode(file_get_contents($DataFile), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, json_last_error_msg());
    die();
}
$NAME = json_decode(file_get_contents($I18nFile), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, json_last_error_msg());
    die();
}
foreach ($DATA as $idx => $festivity) {
    $key = $festivity[ "Festivity" ][ "TAG" ];
    if (false === array_key_exists($key, $FestivityCollection)) {
        $FestivityCollection[ $key ] = $festivity[ "Festivity" ];
        $FestivityCollection[ $key ][ "NAME" ] = $NAME[ $key ];
        if (array_key_exists("decreeLangs", $festivity[ "Metadata" ])) {
            $decreeURL = sprintf($festivity[ "Metadata" ][ "decreeURL" ], 'LA');
            if (array_key_exists(strtoupper($Locale), $festivity[ "Metadata" ][ "decreeLangs" ])) {
                $decreeLang = $festivity[ "Metadata" ][ "decreeLangs" ][ strtoupper($Locale) ];
                $decreeURL = sprintf($festivity[ "Metadata" ][ "decreeURL" ], $decreeLang);
            }
        } else {
            $decreeURL = $festivity[ "Metadata" ][ "decreeURL" ];
        }
        $FestivityCollection[ $key ][ "DECREE" ] = $decreeURL;
    } elseif ($festivity[ "Metadata" ][ "action" ] === 'setProperty') {
        if ($festivity[ "Metadata" ][ "property" ] === 'name') {
            $FestivityCollection[ $key ][ "NAME" ] = $NAME[ $key ];
        } elseif ($festivity[ "Metadata" ][ "property" ] === 'grade') {
            $FestivityCollection[ $key ][ "GRADE" ] = $festivity[ "Festivity" ][ "GRADE" ];
        }
    } elseif ($festivity[ "Metadata" ][ "action" ] === 'makeDoctor') {
        $FestivityCollection[ $key ][ "NAME" ] = $NAME[ $key ];
    }
    $FestivityCollection[ $key ][ "GRADE_LCL" ] = $LitGrade->i18n($FestivityCollection[ $key ][ "GRADE" ], false);
}

if ($NationalCalendar !== null && $NationalData !== null) {
    if ($WiderRegionData !== null && property_exists($WiderRegionData, "LitCal")) {
        foreach ($WiderRegionData->LitCal as $row) {
            if ($row->Metadata->action === 'createNew') {
                $key = $row->Festivity->tag;
                $FestivityCollection[ $key ] = [];
                foreach ($row->Festivity as $prop => $value) {
                    $prop = strtoupper($prop);
                    $FestivityCollection[ $key ][ $prop ] = $value;
                }
                $FestivityCollection[ $key ][ "GRADE_LCL" ] = $LitGrade->i18n($row->Festivity->grade, false);
            }
        }
    }
    foreach ($NationalData->LitCal as $row) {
        if ($row->Metadata->action === 'createNew') {
            $key = $row->Festivity->tag;
            $temp = (array) $row->Festivity;
            $FestivityCollection[ $key ] = array_change_key_case($temp, CASE_UPPER);
            $FestivityCollection[ $key ][ "GRADE_LCL" ] = $LitGrade->i18n($row->Festivity->grade, false);
        }
    }
    if (property_exists($NationalData, "Metadata") && property_exists($NationalData->Metadata, "Missals")) {
        if ($NationalData->Metadata->Region === 'UNITED STATES') {
            $NationalData->Metadata->Region = 'USA';
        }
        foreach ($NationalData->Metadata->Missals as $missal) {
            $DataFile = RomanMissal::getSanctoraleFileName($missal);
            if ($DataFile !== false) {
                $PropriumDeSanctis = json_decode(file_get_contents($DataFile));
                foreach ($PropriumDeSanctis as $idx => $festivity) {
                    $key = $festivity->TAG;
                    $FestivityCollection[ $key ] = (array) $festivity;
                    $FestivityCollection[ $key ][ "GRADE_LCL" ] = $LitGrade->i18n($festivity->GRADE, false);
                    $FestivityCollection[ $key ][ "MISSAL" ] = $missal;
                }
            }
        }
    }
}

if ($DiocesanCalendar !== null && $DiocesanData !== null) {
    foreach ($DiocesanData->LitCal as $key => $festivity) {
        $temp = (array) $festivity->Festivity;
        $FestivityCollection[ $DiocesanCalendar . '_' . $key ] = array_change_key_case($temp, CASE_UPPER);
        $FestivityCollection[ $DiocesanCalendar . '_' . $key ][ "TAG" ] = $DiocesanCalendar . '_' . $key;
        $FestivityCollection[ $DiocesanCalendar . '_' . $key ][ "GRADE_LCL" ] = $LitGrade->i18n($festivity->Festivity->grade, false);
    }
}


$responseObj = [
    "LitCalAllFestivities" => $FestivityCollection,
    "Settings" => [
        "Locale" => $Locale,
        "NationalCalendar" => $NationalCalendar,
        "DiocesanCalendar" => $DiocesanCalendar
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
die();
