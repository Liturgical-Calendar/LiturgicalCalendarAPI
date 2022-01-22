<?php

$requestHeaders = getallheaders();
if( isset( $requestHeaders[ "Origin" ] ) ) {
    header( "Access-Control-Allow-Origin: {$requestHeaders[ "Origin" ]}" );
    header( 'Access-Control-Allow-Credentials: true' );
}
else {
    header( 'Access-Control-Allow-Origin: *' );
}
header( 'Access-Control-Max-Age: 86400' );    // cache for 1 day
header( 'Content-Type: application/json' );

//National Calendars are defined in the LitCalAPI engine itself,
//so we don't really have any way of detecting them except declaring them explicitly here
//If we eventually succeed in finding a rational way to separate national calendars from the central API,
//then perhaps we will also be able to automatically detect which national calendars are defined
//Until then, we'll just use this array, and add to it if a new national calendar is built into the LitCalAPI class
$baseNationalCalendars = [ "ITALY", "USA", "VATICAN" ];

if( file_exists( 'nations/index.json' ) ) {
    $index = file_get_contents( 'nations/index.json' );
    if( $index !== false ) {
        $diocesanCalendars = json_decode( $index, true );
        $nationalCalendars = [];
        foreach( $diocesanCalendars as $key => $value ) {
            unset( $diocesanCalendars[$key]["path"] );
            if( !array_key_exists($diocesanCalendars[$key]["nation"], $nationalCalendars) ) {
                $nationalCalendars[$diocesanCalendars[$key]["nation"]] = [];
            }
            $nationalCalendars[$diocesanCalendars[$key]["nation"]][] = $key;
        }

        foreach( $baseNationalCalendars as $nation ) {
            if( !array_key_exists( $nation, $nationalCalendars ) ) {
                $nationalCalendars[$nation] = [];
            }
        }

        echo json_encode( [
            "LitCalMetadata" => [
                "NationalCalendars" => $nationalCalendars,
                "DiocesanCalendars" => $diocesanCalendars
            ],
        ], JSON_PRETTY_PRINT );
    } else {
        http_response_code(503);
    }
} else {
    http_response_code(404);
}
die();
?>
