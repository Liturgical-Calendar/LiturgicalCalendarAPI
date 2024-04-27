<?php
include_once( 'includes/enums/RequestContentType.php' );
/*
error_reporting(E_ALL);
ini_set("display_errors", 1);
*/

if( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
    header( "Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}" );
    header( 'Access-Control-Allow-Credentials: true' );
}
else {
    header( 'Access-Control-Allow-Origin: *' );
}
header( 'Access-Control-Max-Age: 86400' );    // cache for 1 day
header( 'Cache-Control: must-revalidate, max-age=259200' );
header( 'Content-Type: application/json' );

$acceptedRequestMethods = [ 'GET', 'POST' ];

if( in_array( $_SERVER['REQUEST_METHOD'], $acceptedRequestMethods ) ) {
    switch( $_SERVER['REQUEST_METHOD'] ) {
        case 'GET':
            handleGetRequest();
            break;
        case 'PUT':
            handlePutRequest();
            break;
        default:
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 405 Method Not Allowed", true, 405 );
    }
}

function handleGetRequest(): void {
    $testSuite = [];

    $testsFolder = dirname(__FILE__) . '/tests';
    $it = new DirectoryIterator("glob://$testsFolder/*Test.json");
    foreach($it as $f) {
        $fileName       = $f->getFilename();
        $testContents   = file_get_contents( 'tests/' . $fileName );
        $testSuite[]    = json_decode( $testContents, true );
        //$baseName       = $f->getBasename('.json');
        /*$yearsOther = array_filter( $testClass::Assertions, function($k) use($testClass){ return !in_array($k, array_keys( $testClass::ExpectedValues )); }, ARRAY_FILTER_USE_KEY);
        $assertions = array_filter( $testClass::Assertions, function($k) use($testClass){ return in_array($k, array_keys( $testClass::ExpectedValues )); }, ARRAY_FILTER_USE_KEY);
        $testSuite[] = [
            "name"          => $basename,
            "description"   => $testClass::DESCRIPTION,
            "testType"      => $testClass::TEST_TYPE,
            "years"         => array_keys( $testClass::ExpectedValues ),
            "expectedValues"=> array_values( $testClass::ExpectedValues ),
            "assertions"    => $assertions,
            "yearsOther"    => count($yearsOther) > 0 ? $yearsOther : null
        ];*/
    }

    echo json_encode( $testSuite, JSON_PRETTY_PRINT );
}

function handlePutRequest(): void {
    if( $_SERVER[ 'CONTENT_TYPE' ] !== RequestContentType::JSON ) {
        header( $_SERVER[ "SERVER_PROTOCOL" ]." 405 Method Not Allowed", true, 405 );
    } else {
        $json = file_get_contents( 'php://input' );
        $data = json_decode( $json );
        // we need to validate the incoming data against the unit test schema
        // if the data is valid, we also need to make sure that the strings do not contain any html or php code;
        // there could also be regex checks against the strings to make sure they follow an expected pattern

        //if all the checks pass:
        $bytesWritten = file_put_contents( 'tests/' . $data->name . '.json', $json );
        if( false === $bytesWritten ) {
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 503 Service Unavailable", true, 503 );
            $message = new stdClass;
            $message->status = "ERROR";
            $message->response = "Resource not Created";
            $message->description = "For some reason the server was not able to write the Unit Test to disk. Please try again later or contact the service administrator for support.";
            echo json_encode( $message );
        } else {
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 201 Created", true, 201 );
            $message = new stdClass;
            $message->status = "OK";
            $message->response = "Resource Created";
            echo json_encode( $message );
        }
    }
}
exit(0);
