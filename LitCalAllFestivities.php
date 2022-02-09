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

$LOCALE = isset( $_GET["locale"] ) && LitLocale::isValid( strtoupper( $_GET["locale"] ) ) ? strtoupper( $_GET["locale"] ) : "LA";

foreach( $LatinMissals as $LatinMissal ) {
    $DataFile = RomanMissal::getSanctoraleFileName( $LatinMissal );
    if( $DataFile !== false ) {
        $I18nPath = RomanMissal::getSanctoraleI18nFilePath( $LatinMissal );
        if( $I18nPath !== false && file_exists( $I18nPath . "/" . strtolower( $LOCALE ) . ".json" ) ) {
            $NAME = json_decode( file_get_contents( $I18nPath . "/" . strtolower( $LOCALE ) . ".json" ), true );
            $DATA = json_decode( file_get_contents( $DataFile ), true );
            foreach( $DATA as $idx => $festivity ) {
                $key = $festivity[ "TAG" ];
                $FestivityCollection[ $key ] = $festivity;
                $FestivityCollection[ $key ][ "NAME" ] = $NAME[ $key ];
            }
        }
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
