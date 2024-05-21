<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include_once( 'includes/enums/LitLocale.php' );
include_once( 'includes/enums/RomanMissal.php' );

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

$DataFile = 'data/propriumdetempore.json';
$I18nFile = 'data/propriumdetempore/' . $Locale . ".json";
$DATA = json_decode( file_get_contents( $DataFile ), true );
$NAME = json_decode( file_get_contents( $I18nFile ), true );
foreach( $DATA as $key => $readings ) {
    if( false === array_key_exists( $key, $FestivityCollection ) ) {
        $FestivityCollection[ $key ] = $readings;
        $FestivityCollection[ $key ][ "TAG" ] = $key;
        $FestivityCollection[ $key ][ "NAME" ] = $NAME[ $key ];
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
