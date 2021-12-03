<?php

$requestHeaders = getallheaders();
if( isset( $requestHeaders[ "Origin" ] ) ) {
    header( "Access-Control-Allow-Origin: {$requestHeaders[ "Origin" ]}" );
}
else {
    header( 'Access-Control-Allow-Origin: *' );
}
header( 'Access-Control-Allow-Credentials: true' );
header( 'Access-Control-Max-Age: 86400' );    // cache for 1 day
header( 'Content-Type: application/json' );
if( file_exists( 'nations/index.json' ) ) {
    $index = file_get_contents( 'nations/index.json' );
    if( $index !== false ) {
        $indexAssoc = json_decode( $index, true );
        foreach( $indexAssoc as $key => $value ) {
            unset( $indexAssoc[$key]["path"] );
        }
        echo json_encode( $indexAssoc );
    } else {
        http_response_code(503);
    }
} else {
    http_response_code(404);
}
die();
?>