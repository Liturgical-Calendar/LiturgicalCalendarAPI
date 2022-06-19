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
header( 'Cache-Control: must-revalidate, max-age=259200' );
header( 'Content-Type: application/json' );

$testSuite = [];

$testsFolder = dirname(__FILE__) . '/tests';
$it = new DirectoryIterator("glob://$testsFolder/*Test.php");
foreach($it as $f) {
    $fileName = $f->getFilename();
    include_once( 'tests/' . $fileName );
    $basename =  $f->getBasename('.php');
    $testClass = new $basename;
    $yearsOther = array_filter( $testClass::Assertions, function($k) use($testClass){ return !in_array($k, array_keys( $testClass::ExpectedValues )); }, ARRAY_FILTER_USE_KEY);
    $assertions = array_filter( $testClass::Assertions, function($k) use($testClass){ return in_array($k, array_keys( $testClass::ExpectedValues )); }, ARRAY_FILTER_USE_KEY);
    $testSuite[$basename] = [
        "description"   => $testClass::DESCRIPTION,
        "testType"      => $testClass::TEST_TYPE,
        "years"         => array_keys( $testClass::ExpectedValues ),
        "expectedValues"=> array_values( $testClass::ExpectedValues ),
        "assertions"    => $assertions,
        "yearsOther"    => count($yearsOther) > 0 ? $yearsOther : null
    ];
}

echo json_encode( $testSuite, JSON_PRETTY_PRINT );
exit(0);
