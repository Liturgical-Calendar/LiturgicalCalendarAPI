<?php

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

$LatinMissals = array_filter( RomanMissal::$values, function($item){
    return str_starts_with( $item, "VATICAN_" );
});

$LOCALE = isset( $_GET["locale"] ) ? $_GET["locale"] : "la";
$LOCALE = LitLocale::isValid( $LOCALE ) ? $LOCALE : "la";
$LOCALE = $LOCALE !== "LA" && $LOCALE !== "la" ? LOCALE::getPrimaryLanguage( $LOCALE ) : "la";

foreach( $LatinMissals as $LatinMissal ) {
    $DataFile = RomanMissal::getSanctoraleFileName( $LatinMissal );
    if( $DataFile !== false ) {
        $I18nPath = RomanMissal::getSanctoraleI18nFilePath( $LatinMissal );
        if( $I18nPath !== false && file_exists( $I18nPath . "/" . $LOCALE . ".json" ) ) {
            $NAME = json_decode( file_get_contents( $I18nPath . "/" . $LOCALE . ".json" ), true );
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
$I18nFile = 'data/propriumdetempore/' . $LOCALE . ".json";
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
$I18nFile = 'data/memorialsFromDecrees/i18n/' . $LOCALE . ".json";
$DATA = json_decode( file_get_contents( $DataFile ), true );
$NAME = json_decode( file_get_contents( $I18nFile ), true );
foreach( $DATA as $idx => $festivity ) {
    $key = $festivity[ "Festivity" ][ "TAG" ];
    if( false === array_key_exists( $key, $FestivityCollection ) ) {
        $FestivityCollection[ $key ] = $festivity[ "Festivity" ];
        $FestivityCollection[ $key ][ "NAME" ] = $NAME[ $key ];
        if( array_key_exists( "decreeLangs", $festivity[ "Metadata" ] ) ) {
            $decreeURL = sprintf( $festivity[ "Metadata" ][ "decreeURL" ], 'LA' );
            if( array_key_exists(strtoupper($LOCALE), $festivity[ "Metadata" ][ "decreeLangs" ]) ) {
                $decreeLang = $festivity[ "Metadata" ][ "decreeLangs" ][ strtoupper( $LOCALE ) ];
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


$responseObj = [ "LitCalAllFestivities" => $FestivityCollection ];

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
