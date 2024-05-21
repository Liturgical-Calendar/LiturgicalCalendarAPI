<?php
include_once( 'vendor/autoload.php' );

use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;

$schemaFile = dirname(__FILE__) . '/schemas/LitCalTest.json';
$schemaContents = file_get_contents( $schemaFile );
$jsonSchema = json_decode( $schemaContents );

$testsFolder = dirname(__FILE__) . '/tests';
$it = new DirectoryIterator("glob://$testsFolder/*Test.json");
$results = [];
foreach($it as $f) {
    $fileName = $f->getFilename();
    $dataPath = $testsFolder . DIRECTORY_SEPARATOR . $fileName;
    $data = file_get_contents( $dataPath );
    $jsonData = json_decode( $data );
    $message = new stdClass();
    try {
        $schema = Schema::import( $jsonSchema );
        $schema->in($jsonData);
        $message->type = "success";
        $message->text = "File " . $dataPath . " was correctly validated against schema " . $schemaFile;
    } catch (InvalidValue|Exception $e) {
        $message->type = "error";
        $message->text = "File " . $dataPath . " was incorrectly validated against schema " . $schemaFile;
        $message->errorMessage = $e->getMessage();
    }
    $results[] = $message;
}


$testIndexSchemaFile = dirname(__FILE__) . '/schemas/LitCalTestsIndex.json';
$testIndexSchemaContents = file_get_contents( $testIndexSchemaFile );
$testIndexJsonSchema = json_decode( $testIndexSchemaContents );

$testIndexData = file_get_contents( 'https://litcal.johnromanodorazio.com/api/dev/LitCalTestsIndex.php' );
$testIndexJsonData = json_decode( $testIndexData );
$message = new stdClass();
try{
    $testIndexSchema = Schema::import( $testIndexJsonSchema );
    $testIndexSchema->in($testIndexJsonData);
    $message->type = "success";
    $message->text = "LitCalTestsIndex was correctly validated against schema " . $testIndexSchemaFile;
} catch (InvalidValue|Exception $e) {
    $message->type = "error";
    $message->text = "LitCalTestsIndex was incorrectly validated against schema " . $testIndexSchemaFile;
    $message->errorMessage = $e->getMessage();
}
$results[] = $message;

$allFestivitiesSchemaFile = dirname(__FILE__) . '/schemas/LitCalAllFestivities.json';
$allFestivitiesSchemaContents = file_get_contents( $allFestivitiesSchemaFile );
$allFestivitiesJsonSchema = json_decode( $allFestivitiesSchemaContents );

$allFestivitiesParams = [
    "",
    "?locale=EN",
    "?nationalcalendar=ITALY",
    "?nationalcalendar=USA",
    "?diocesancalendar=DIOCESIDIROMA",
    "?diocesancalendar=ROERMOND"
];
foreach( $allFestivitiesParams as $params ) {
    $allFestivitiesData = file_get_contents( 'https://litcal.johnromanodorazio.com/api/dev/LitCalAllFestivities.php' . $params );
    $allFestivitiesJsonData = json_decode( $allFestivitiesData );
    $message = new stdClass();
    try{
        $allFestivitiesSchema = Schema::import( $allFestivitiesJsonSchema );
        $allFestivitiesSchema->in( $allFestivitiesJsonData );
        $message->type = "success";
        $message->text = "LitCalAllFestivities with params <$params> was correctly validated against schema " . $allFestivitiesSchemaFile;
    } catch (InvalidValue|Exception $e) {
        $message->type = "error";
        $message->text = "LitCalAllFestivities with params <$params> was incorrectly validated against schema " . $allFestivitiesSchemaFile;
        $message->errorMessage = $e->getMessage();
    }
    $results[] = $message;
}

echo json_encode( $results, JSON_PRETTY_PRINT );
exit(0);
