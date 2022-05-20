<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);

include_once( 'includes/enums/AcceptHeader.php' );
include_once( 'includes/enums/LitSchema.php' );
include_once( 'includes/enums/RequestMethod.php' );
include_once( 'includes/enums/RequestContentType.php' );
include_once( 'includes/enums/ReturnType.php' );
include_once( 'includes/APICore.php' );
include_once( 'vendor/autoload.php' );

use Swaggest\JsonSchema\Schema;

if( file_exists("allowedOrigins.php") ) {
    include_once( 'allowedOrigins.php' );
}

$allowedOrigins = [
    "https://johnromanodorazio.com",
    "https://www.johnromanodorazio.com",
    "https://litcal.johnromanodorazio.com",
    "https://litcal-staging.johnromanodorazio.com"
];

if( defined('ALLOWED_ORIGINS') && is_array( ALLOWED_ORIGINS ) ) {
    $allowedOrigins = array_merge( $allowedOrigins, ALLOWED_ORIGINS );
}

$LitCalHealth = new LitCalHealth();

$LitCalHealth->APICore->setAllowedOrigins( $allowedOrigins );
$LitCalHealth->APICore->setAllowedReferers( array_map( function($el){ return $el . "/"; }, $allowedOrigins ) );

$LitCalHealth->APICore->setAllowedAcceptHeaders( [ AcceptHeader::JSON ] );
$LitCalHealth->APICore->setAllowedRequestContentTypes( [ RequestContentType::JSON, RequestContentType::FORMDATA ] );
$LitCalHealth->APICore->setAllowedRequestMethods( [ RequestMethod::GET, RequestMethod::POST, RequestMethod::OPTIONS ] );
$LitCalHealth->Init();


class LitCalHealth {

    const DataSchema = [
        "data/memorialsFromDecrees/memorialsFromDecrees.json"       => LitSchema::DECREEMEMORIALS,
        "data/propriumdesanctis_1970/propriumdesanctis_1970.json"   => LitSchema::PROPRIUMDESANCTIS,
        "data/propriumdesanctis_2002/propriumdesanctis_2002.json"   => LitSchema::PROPRIUMDESANCTIS,
        "data/propriumdesanctis_2008/propriumdesanctis_2008.json"   => LitSchema::PROPRIUMDESANCTIS,
        "data/propriumdesanctis_ITALY_1983/propriumdesanctis_ITALY_1983.json"   => LitSchema::PROPRIUMDESANCTIS,
        "data/propriumdesanctis_USA_2011/propriumdesanctis_USA_2011.json"       => LitSchema::PROPRIUMDESANCTIS,
        "data/propriumdetempore.json"                                           => LitSchema::PROPRIUMDETEMPORE,
        "nations/index.json"                                        => LitSchema::INDEX,
        "https://litcal.johnromanodorazio.com/api/dev/LitCalMetadata.php" => LitSchema::METADATA
    ];

    public APICore $APICore;
    //private array $MESSAGES                         = [];

    public function __construct(){
        $this->APICore                              = new APICore();
        $this->RESPONSE                             = new stdClass();
        $this->RESPONSE->requestHeadersReceived     = $this->APICore->getJsonEncodedRequestHeaders();
    }

    private function executeValidations() {
        $result = new stdClass();
        $result->messages = [];
        foreach( LitCalHealth::DataSchema as $dataPath => $schema ) {
            $message = new stdClass();
            $data = file_get_contents( $dataPath );
            if( $data !== false ) {
                $message->type = "success";
                $message->text = "Data file $dataPath exists";
                $result->messages = $message;

                $jsonData = json_decode( $data );
                if( json_last_error() === JSON_ERROR_NONE ) {
                    $message->type = "success";
                    $message->text = "The Data file $dataPath was successfully decoded as JSON";
                    $result->messages = $message;

                    if( $this->validateDataAgainstSchema( $jsonData, $schema ) ) {
                        $message->type = "success";
                        $message->text = "The Data file $dataPath was successfully validated against the Schema $schema";
                        $result->messages = $message;
                    }
                } else {
                    $message->type = "error";
                    $message->text = "There was an error decoding the Data file $dataPath as JSON: " . json_last_error_msg();
                    $result->messages = $message;
                }

            } else {
                $message->type = "error";
                $message->text = "Data file $dataPath does not exist";
                $result->messages = $message;
            }
        }
        die( json_encode( $result ) );
    }

    private function validateDataAgainstSchema( object|array $data, string $schemaUrl ) : bool {
        $result = new stdClass();
        $schema = Schema::import( $schemaUrl );
        try {
            $validation = $schema->in($data);
            return true;
        } catch (Exception $e) {
            $result->error = LitSchema::ERROR_MESSAGES[ $schemaUrl ] . PHP_EOL . $e->getMessage();
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 422 Unprocessable Entity", true, 422 );
            die( json_encode( $result ) );
        }
    }

    private function handleRequestedMethod() {
        switch( strtoupper( $_SERVER[ "REQUEST_METHOD" ] ) ) {
            case RequestMethod::GET:
                $this->handleGetPostRequests( $_GET );
                break;
            case RequestMethod::POST:
                $this->handleGetPostRequests( $_POST );
                break;
            case RequestMethod::OPTIONS:
                //continue;
                break;
            default:
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 405 Method Not Allowed", true, 405 );
                $errorMessage = '{"error":"You seem to be forming a strange kind of request? Allowed Request Methods are ';
                $errorMessage .= implode( ' and ', $this->AllowedRequestMethods );
                $errorMessage .= ', but your Request Method was ' . strtoupper( $_SERVER[ 'REQUEST_METHOD' ] ) . '"}';
                die( $errorMessage );
        }
    }

    private function handleGetPostRequests( array $REQUEST ) {

        $this->APICore->validateAcceptHeader( true );
        if( $this->APICore->getRequestContentType() === 'application/json' ) {
            $this->DATA = $this->APICore->retrieveRequestParamsFromJsonBody();
        } else {
            $this->DATA = (object)$REQUEST;
        }
        $this->executeValidations();
    }


    public function Init() {
        $this->APICore->Init();
        $this->APICore->setResponseContentTypeHeader();
        $this->handleRequestedMethod();
    }

}