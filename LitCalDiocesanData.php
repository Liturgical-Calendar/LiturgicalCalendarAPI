<?php
error_reporting( E_ALL );
ini_set( 'display_errors', 1 );

include_once( 'includes/enums/AcceptHeader.php' );
include_once( 'includes/enums/RequestMethod.php' );
include_once( 'includes/enums/RequestContentType.php' );
include_once( 'includes/enums/ReturnType.php' );
include_once( 'includes/APICore.php' );

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

$LitCalDiocesanData = new LitCalDiocesanData();

$LitCalDiocesanData->APICore->setAllowedOrigins( $allowedOrigins );
$LitCalDiocesanData->APICore->setAllowedReferers( array_map( function($el){ return $el . "/"; }, $allowedOrigins ) );

$LitCalDiocesanData->APICore->setAllowedAcceptHeaders( [ AcceptHeader::JSON ] );
$LitCalDiocesanData->APICore->setAllowedRequestContentTypes( [ RequestContentType::JSON, RequestContentType::FORMDATA ] );
$LitCalDiocesanData->Init();

class LitCalDiocesanData {

    private object $DATA;
    private object $RESPONSE;
    private ?stdClass $GeneralIndex                 = null;

    public APICore $APICore;

    public function __construct(){
        $this->APICore                              = new APICore();
        $this->RESPONSE                             = new stdClass();
        $this->RESPONSE->requestHeadersReceived     = $this->APICore->getJsonEncodedRequestHeaders();
    }

    private function handleGetPostRequests( array $REQUEST ) {

        $this->APICore->validateAcceptHeader( true );
        if( $this->APICore->getRequestContentType() === 'application/json' ) {
            $this->DATA = $this->APICore->retrieveRequestParamsFromJsonBody();
        } else {
            $this->DATA = (object)$REQUEST;
        }
        $this->retrieveDiocesanCalendar();
    }

    private function handlePutPatchDeleteRequests( string $requestMethod ) {
        $this->APICore->validateAcceptHeader( false );
        $this->APICore->enforceAjaxRequest();
        $this->APICore->enforceReferer();
        if( $this->APICore->getRequestContentType() === 'application/json' ) {
            $this->DATA = $this->APICore->retrieveRequestParamsFromJsonBody();
            if( RequestMethod::PUT === $requestMethod ) {
                $this->writeDiocesanCalendar();
            } elseif( RequestMethod::DELETE === $requestMethod ) {
                $this->deleteDiocesanCalendar();
            }
            
        } else{
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 415 Unsupported Media Type", true, 415 );
            die( '{"error":"You seem to be forming a strange kind of request? Only \'application/json\' is allowed as the Content Type for the body of the Request when using Request Methods PUT, PATCH, or DELETE: the Content Type for the body of your Request was '.$_SERVER[ 'CONTENT_TYPE' ].' and you are using Request Method ' . $_SERVER[ 'REQUEST_METHOD' ] . '"}' );
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
            case RequestMethod::PUT:
            case RequestMethod::PATCH:
                $this->handlePutPatchDeleteRequests( RequestMethod::PUT );
                break;
            case RequestMethod::DELETE:
                $this->handlePutPatchDeleteRequests( RequestMethod::DELETE );
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

    private function loadIndex() {
        if( file_exists( "nations/index.json" ) ){
            $this->GeneralIndex = json_decode( file_get_contents( "nations/index.json" ) );
        }
    }

    private function createOrUpdateIndex( string $path, bool $delete = false ) {
        if( null === $this->GeneralIndex ){
            $this->GeneralIndex = new stdClass();
        }
        $key = strtoupper(preg_replace("/[^a-zA-Z]/","",$this->RESPONSE->Diocese));

        if( $delete ) {
            if( property_exists( $this->GeneralIndex, $key ) ) {
                unset( $this->GeneralIndex->$key );
            }
        } else {
            if( !property_exists( $this->GeneralIndex, $key ) ){
                $this->GeneralIndex->$key = new stdClass();
            }
            $this->GeneralIndex->$key->path = $path . "/{$this->RESPONSE->Diocese}.json";
            $this->GeneralIndex->$key->nation = $this->RESPONSE->Nation;
            $this->GeneralIndex->$key->diocese = $this->RESPONSE->Diocese;
            if(property_exists($this->RESPONSE,'Group')){
                $this->GeneralIndex->$key->group = $this->RESPONSE->Group;
            }
        }

        file_put_contents( "nations/index.json", json_encode( $this->GeneralIndex ) . PHP_EOL );
    }

    private function retrieveDiocesanCalendar() {
        if( property_exists( $this->DATA, 'key' ) ) {
            $key = $this->DATA->key;
            $calendarPath = $this->GeneralIndex->$key->path;
            if( file_exists( $calendarPath ) ) {
                echo file_get_contents( $calendarPath );
                die();
            }
        }
    }

    private function writeDiocesanCalendar() {
        if( !property_exists( $this->DATA, 'calendar' ) || !property_exists( $this->DATA, 'diocese' ) || !property_exists( $this->DATA, 'nation' ) ) {
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad request", true, 400 );
            die( '{"error":"Required parameters were not received"}' );
        } else {
            $this->RESPONSE->Nation = strip_tags( $this->DATA->nation );
            $this->RESPONSE->Diocese = strip_tags( $this->DATA->diocese );
            $CalData = json_decode( $this->DATA->calendar );
            if( json_last_error() !== JSON_ERROR_NONE ) {
                header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad request", true, 400 );
                die( '{"error":"Malformed data received in <calendar> parameters"}' );
            }
            if( property_exists( $this->DATA, 'overrides' ) ) {
                $CalData->Overrides = $this->DATA->overrides;
            }
            $this->RESPONSE->Calendar = json_encode( $CalData );
            if( property_exists( $this->DATA, 'group' ) ) {
                $this->RESPONSE->Group = strip_tags( $this->DATA->group );
            }
            $path = "nations/{$this->RESPONSE->Nation}";
            if( !file_exists( $path ) ){
                mkdir( $path, 0755, true );
            }

            file_put_contents( $path . "/{$this->RESPONSE->Diocese}.json", $this->RESPONSE->Calendar . PHP_EOL );

            $this->createOrUpdateIndex( $path );
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 201 Created", true, 201 );
            die( '{"success":"Diocesan calendar created or updated for diocese \"'. $this->RESPONSE->Diocese .'\""}' );

        }
    }

    private function deleteDiocesanCalendar() {
        if( !property_exists( $this->DATA, 'calendar' ) || !property_exists( $this->DATA, 'diocese' ) || !property_exists( $this->DATA, 'nation' ) ) {
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 400 Bad request", true, 400 );
            die( '{"error":"Required parameters were not received"}' );
        } else {
            $this->RESPONSE->Nation = strip_tags( $this->DATA->nation );
            $this->RESPONSE->Diocese = strip_tags( $this->DATA->diocese );
            $path = "nations/{$this->RESPONSE->Nation}";
            if( file_exists( $path . "/{$this->RESPONSE->Diocese}.json" ) ){
                unlink($path . "/{$this->RESPONSE->Diocese}.json");
            }

            $this->createOrUpdateIndex( $path, true );
            header( $_SERVER[ "SERVER_PROTOCOL" ]." 200 OK", true, 200 );
            die( '{"success":"Diocesan calendar deleted for diocese \"'. $this->RESPONSE->Diocese .'\""}' );

        }
    }

    public function Init() {
        $this->APICore->Init();
        $this->APICore->setResponseContentTypeHeader();
        $this->loadIndex();
        $this->handleRequestedMethod();
    }

}
