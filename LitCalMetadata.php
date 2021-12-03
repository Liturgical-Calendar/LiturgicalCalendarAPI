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
    echo file_get_contents( 'nations/index.json' );
} else {
    http_response_code(412);
}
die();
?>