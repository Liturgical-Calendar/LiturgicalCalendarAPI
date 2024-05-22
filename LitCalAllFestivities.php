<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include_once 'includes/enums/LitLocale.php';
include_once 'includes/enums/RomanMissal.php';
include_once 'includes/enums/LitGrade.php';

$requestHeaders = getallheaders();
if( isset( $requestHeaders[ "Origin" ] ) ) {
    header( "Access-Control-Allow-Origin: {$requestHeaders[ "Origin" ]}" );
    header( 'Access-Control-Allow-Credentials: true' );
}
else {
    header( 'Access-Control-Allow-Origin: *' );
}
header( 'Access-Control-Max-Age: 86400' );    // cache for 1 day
header( 'Cache-Control: must-revalidate, max-age=259200' );
header( 'Content-Type: application/json' );

$FestivityCollection = [];

$LatinMissals = array_filter( RomanMissal::$values, function($item) {
    return str_starts_with( $item, "VATICAN_" );
});

$SUPPORTED_NATIONAL_CALENDARS = [ "VATICAN" ];
$directories = array_map('basename', glob( 'nations/*' , GLOB_ONLYDIR) );
foreach( $directories as $directory ) {
    if( file_exists( "nations/$directory/$directory.json" ) ) {
        $SUPPORTED_NATIONAL_CALENDARS[] = $directory;
    }
}

$GeneralIndex = file_exists( "nations/index.json" ) ? json_decode( file_get_contents( "nations/index.json" ) ) : null;

$Locale = isset( $_GET["locale"] ) && LitLocale::isValid( $_GET["locale"] ) ? $_GET["locale"] : "la";

$NationalCalendar = isset( $_GET["nationalcalendar"] ) && in_array( strtoupper( $_GET["nationalcalendar"] ), $SUPPORTED_NATIONAL_CALENDARS ) ? strtoupper( $_GET["nationalcalendar"] ) : null;
$DiocesanCalendar = isset( $_GET["diocesancalendar"] ) ? strtoupper( $_GET["diocesancalendar"] ) : null;

$NationalData       = null;
$WiderRegionData    = null;
$DiocesanData       = null;

if( $DiocesanCalendar !== null && property_exists( $GeneralIndex, $DiocesanCalendar ) ) {
    $NationalCalendar = $GeneralIndex->{$DiocesanCalendar}->nation;
    $diocesanDataFile = $GeneralIndex->{$DiocesanCalendar}->path;
    if( file_exists( $diocesanDataFile ) ){
        $DiocesanData = json_decode( file_get_contents( $diocesanDataFile ) );
    }
}

if( $NationalCalendar !== null ) {
    $nationalDataFile = "nations/{$NationalCalendar}/{$NationalCalendar}.json";
    if( file_exists( $nationalDataFile ) ) {
        $NationalData = json_decode( file_get_contents( $nationalDataFile ) );
        if( json_last_error() === JSON_ERROR_NONE ) {
            if( property_exists( $NationalData, "Settings" ) && property_exists( $NationalData->Settings, "Locale" ) ) {
                $Locale = $NationalData->Settings->Locale;
            }
            if( property_exists( $NationalData, "Metadata" ) && property_exists( $NationalData->Metadata, "WiderRegion" ) ) {
                $widerRegionDataFile = $NationalData->Metadata->WiderRegion->jsonFile;
                $widerRegionI18nFile = $NationalData->Metadata->WiderRegion->i18nFile;
                if( file_exists( $widerRegionI18nFile ) ) {
                    $widerRegionI18nData = json_decode( file_get_contents( $widerRegionI18nFile ) );
                    if( json_last_error() === JSON_ERROR_NONE && file_exists( $widerRegionDataFile ) ) {
                        $WiderRegionData = json_decode( file_get_contents( $widerRegionDataFile ) );
                        if( json_last_error() === JSON_ERROR_NONE && property_exists( $WiderRegionData, "LitCal" ) ) {
                            foreach( $WiderRegionData->LitCal as $idx => $value ) {
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

$Locale = $Locale !== "LA" && $Locale !== "la" ? Locale::getPrimaryLanguage( $Locale ) : "la";

foreach( $LatinMissals as $LatinMissal ) {
    $DataFile = RomanMissal::getSanctoraleFileName( $LatinMissal );
    if( $DataFile !== false ) {
        $I18nPath = RomanMissal::getSanctoraleI18nFilePath( $LatinMissal );
        if( $I18nPath !== false && file_exists( $I18nPath . "/" . $Locale . ".json" ) ) {
            $NAME = json_decode( file_get_contents( $I18nPath . "/" . $Locale . ".json" ), true );
            $DATA = json_decode( file_get_contents( $DataFile ), true );
            foreach( $DATA as $idx => $festivity ) {
                $key = $festivity[ "TAG" ];
                $FestivityCollection[ $key ] = $festivity;
                $FestivityCollection[ $key ][ "NAME" ] = $NAME[ $key ];
                $FestivityCollection[ $key ][ "MISSAL" ] = $LatinMissal;
            }
        }
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

$DataFile = 'data/propriumdetempore.json';
$I18nFile = 'data/propriumdetempore/' . $Locale . ".json";
$DATA = json_decode( file_get_contents( $DataFile ), true );
$NAME = json_decode( file_get_contents( $I18nFile ), true );
foreach( $DATA as $key => $readings ) {
    if( false === array_key_exists( $key, $FestivityCollection ) ) {
        $FestivityCollection[ $key ] = $readings;
        $FestivityCollection[ $key ][ "TAG" ] = $key;
        $FestivityCollection[ $key ][ "NAME" ] = $NAME[ $key ];
        $FestivityCollection[ $key ][ "GRADE" ] = $PropriumDeTemporeRanks[ $key ];
    }
}

$DataFile = 'data/memorialsFromDecrees/memorialsFromDecrees.json';
$I18nFile = 'data/memorialsFromDecrees/i18n/' . $Locale . ".json";
$DATA = json_decode( file_get_contents( $DataFile ), true );
$NAME = json_decode( file_get_contents( $I18nFile ), true );
foreach( $DATA as $idx => $festivity ) {
    $key = $festivity[ "Festivity" ][ "TAG" ];
    if( false === array_key_exists( $key, $FestivityCollection ) ) {
        $FestivityCollection[ $key ] = $festivity[ "Festivity" ];
        $FestivityCollection[ $key ][ "NAME" ] = $NAME[ $key ];
        if( array_key_exists( "decreeLangs", $festivity[ "Metadata" ] ) ) {
            $decreeURL = sprintf( $festivity[ "Metadata" ][ "decreeURL" ], 'LA' );
            if( array_key_exists(strtoupper($Locale), $festivity[ "Metadata" ][ "decreeLangs" ]) ) {
                $decreeLang = $festivity[ "Metadata" ][ "decreeLangs" ][ strtoupper( $Locale ) ];
                $decreeURL = sprintf( $festivity[ "Metadata" ][ "decreeURL" ], $decreeLang );
            }
        } else {
            $decreeURL = $festivity[ "Metadata" ][ "decreeURL" ];
        }
        $FestivityCollection[ $key ][ "DECREE" ] = $decreeURL;
    }
    else if ( $festivity[ "Metadata" ][ "action" ] === 'setProperty' ) {
        if( $festivity[ "Metadata" ][ "property" ] === 'name' ) {
            $FestivityCollection[ $key ][ "NAME" ] = $NAME[ $key ];
        }
        else if( $festivity[ "Metadata" ][ "property" ] === 'grade' ) {
            $FestivityCollection[ $key ][ "GRADE" ] = $festivity[ "Festivity" ][ "GRADE" ];
        }
    }
    else if( $festivity[ "Metadata" ][ "action" ] === 'makeDoctor' ) {
        $FestivityCollection[ $key ][ "NAME" ] = $NAME[ $key ];
    }
}

if( $NationalCalendar !== null && $NationalData !== null ) {
    if( $WiderRegionData !== null && property_exists( $WiderRegionData, "LitCal" ) ) {
        foreach( $WiderRegionData->LitCal as $row ) {
            if( $row->Metadata->action === 'createNew' ) {
                $key = $row->Festivity->tag;
                $FestivityCollection[ $key ] = [];
                foreach( $row->Festivity as $prop => $value ) {
                    $prop = strtoupper( $prop );
                    $FestivityCollection[ $key ][ $prop ] = $value;
                }
            }
        }
    }
    foreach( $NationalData->LitCal as $row ) {
        if( $row->Metadata->action === 'createNew' ) {
            $key = $row->Festivity->tag;
            $temp = (array) $row->Festivity;
            $FestivityCollection[ $key ] = array_change_key_case( $temp, CASE_UPPER );
        }
    }
    if( property_exists( $NationalData, "Metadata" ) && property_exists( $NationalData->Metadata, "Missals" ) ) {
        if( $NationalData->Metadata->Region === 'UNITED STATES' ) {
            $NationalData->Metadata->Region = 'USA';
        }
        foreach( $NationalData->Metadata->Missals as $missal ) {
            $DataFile = RomanMissal::getSanctoraleFileName( $missal );
            if( $DataFile !== false ) {
                $PropriumDeSanctis = json_decode( file_get_contents( $DataFile ) );
                foreach( $PropriumDeSanctis as $idx => $festivity ) {
                    $key = $festivity->TAG;
                    $FestivityCollection[ $key ] = (array) $festivity;
                    $FestivityCollection[ $key ][ "MISSAL" ] = $missal;
                }
            }
        }
    }
}

if( $DiocesanCalendar !== null && $DiocesanData !== null ) {
    foreach( $DiocesanData->LitCal as $key => $festivity ) {
        $temp = (array) $festivity->Festivity;
        $FestivityCollection[ $key ] = array_change_key_case( $temp, CASE_UPPER );
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

$response = json_encode( $responseObj );
$responseHash = md5( $response );
header("Etag: \"{$responseHash}\"");
if (!empty( $_SERVER['HTTP_IF_NONE_MATCH'] ) && $_SERVER['HTTP_IF_NONE_MATCH'] === $responseHash) {
    header( $_SERVER[ "SERVER_PROTOCOL" ] . " 304 Not Modified" );
    header('Content-Length: 0');
} else {
    echo $response;
}
die();
